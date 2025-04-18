<?php
	/** 
	 *	Merges different provider objects with source's. E.g local file, database.
	 *	@author Mathias Eklöf
	 * 	@created 2021-12-01
	 * 	@updated 2022-12-07: DI with "prepare"-methods
	 * 		- Env can use Locale to extract language
	 */
	namespace LCMS\Api;

	use LCMS\Core\Env;
	use LCMS\Core\Database as DB;
	use LCMS\Core\Route;
	use LCMS\Page\Navigations;
	use LCMS\Core\Node;
	use LCMS\Core\NodeType;
	use LCMS\Core\NodeObject;
	use LCMS\Core\Request;
	use LCMS\Core\Locale;
	use LCMS\Util\Arr;
	use LCMS\DI;
	
	use \Closure;
	use \Exception;
	
	class Merge
	{
		private $object;
		private $merger;

		function __construct(object $_obj)
		{
			$this->object = $_obj;
		}

		public function factory(object $_obj)
		{
			$this->object = $_obj;
		}

		public function getFamily()
		{
			return (new \ReflectionClass($this->object))->getShortName();
		}

		public static function getClassOf(object $_obj): string | bool
		{
			return match(true)
			{
				$_obj instanceof Node 			=> NodeMerge::class,
				$_obj instanceof Route 			=> RouteMerge::class,
				$_obj instanceof Navigations 	=> NavigationsMerger::class,
				$_obj instanceof Env 			=> EnvMerge::class,
				$_obj instanceof Locale 		=> LocaleMerge::class,
				default => false
			};
		}

		public function with($_storage)
		{
			$mergObj = $this->getClassOf($this->object);

			if(!$mergObj || !$this->merger = new $mergObj($this->object, $_storage))
			{
				throw new Exception("No merger available from Object");
			}

			return $this->merger;
		}

		public function store($_what = null, $_into = null)
		{
			if(!$this->merger && empty($_into))
			{
				throw new Exception("Merger requires a storage");
			}
			elseif(!$this->merger)
			{
				$this->with($_into);
			}

			if(empty($_what))
			{
				return;
			}
			elseif(!$this->object instanceof Node && !$this->object instanceof Route)
			{
				throw new Exception("Cant store data if not Node nor Route");
			}

			return $this->merger->store($_what, $_into);
		}
	}

	abstract class BaseMerge
	{
		protected $instance;
		protected $storage;

		function __construct(object $_instance, mixed $_storage = null)
		{
			$this->instance = $_instance;
			
			if(!empty($_storage))
			{
				$this->storage = $_storage;
			}
		}

		public function setStorage(mixed $_storage): self
		{
			$this->storage = $_storage;

			return $this;
		}

		public function getStorage()
		{
			return $this->storage;
		}

		public function getInstance()
		{
			return $this->instance;
		}

		public function prepare($_storage): self
		{
			$this->storage = $_storage;

			if(!$fn = match(true)
			{
				$_storage instanceof DB => "prepareFromDatabase",
				$_storage instanceof Closure => "prepareFromClosure",
				is_array($_storage) => "prepareFromArray",
				is_string($_storage) && is_file($_storage) => "prepareFromFile",
				is_string($_storage) && is_dir($_storage) => "prepareFromDir",
				default => false
			})
			{
				$str = (is_object($_storage)) ? get_class($_storage) : $_storage;
				throw new Exception("Invalid preparation storage: " . $str);
			}

			return DI::call([$this, $fn], [$_storage]); // Let Storage-methods inherit DI
		}

		public function prepareFromClosure(Closure $_routes_closure): self
		{
			DI::call($_routes_closure);

			return $this;
		}		

		/*public function prepareFromDir(string $_dir): self
		{
			throw new Exception(get_class($this) . " cant prepare from Directory");
		}*/

		protected function store(mixed $_what, mixed $_into = null): self
		{
			throw new Exception(get_class($this) . " does not support storage");
		}

		public function merge(mixed $_storage = null): self
		{
			$storage = $_storage ?? $this->storage;
			return $this->prepare($storage)->execute($storage);
		}

		public function set(object $_instance): self
		{
			$this->instance = $_instance;

			return $this;
		}

		protected function execute($_storage): self
		{
			return $this;
		}
	}

	class NodeMerge extends BaseMerge
	{
		public array $nodes;
		public array $properties;
		private array $unmergers;
		private bool $has_written_to_ini = false;
		
		public function prepareFromFile(string $_file): self
		{
			$this->nodes = array();
			$this->properties = array();
			$this->unmergers = array();

			return $this->prepareFromIni($_file);
		}

		public function prepareFromDatabase(DB $db, Locale $locale): self
		{
			$this->nodes = array();
			$this->properties = array();
			$this->unmergers = array();

			$condition = "";
			$values = array();

			if($this->instance->namespace != null && isset($this->instance->namespace['id']))
			{
				$condition = " OR `route_id`=?";
				$values[] = $this->instance->namespace['id'];
			}

			$lang_prop = "$.".$locale->getLanguage();
			$values = [...$values, $lang_prop, NodeType::LOOP->value, $lang_prop, $lang_prop];

			if(!$nodes = $db->query("SELECT * FROM ".Env::get("db")['database'].".`lcms_nodes` 
										WHERE (`route_id` IS NULL " . $condition . ") 
											AND `deleted_at` IS NULL 
											AND JSON_EXTRACT(`hidden_at`, ?) IS NULL
											AND (
												`type`=?
												OR JSON_EXTRACT(`content`, ?) > 0 
												OR JSON_EXTRACT(`content`, '$.*') > 0
												OR JSON_EXTRACT(`properties`, ?) > 0
											)
												ORDER BY `order` ASC, `identifier` ASC", $values)->asArray())
			{
				return $this;
			}
			
			$loops = array();
			$loops_relations = array();
			$rows_relations = array();

			foreach($nodes AS $row)
			{
				$node = $this->buildNode($row);

				$identifier = $node['identifier'];
				$identifier_flat = $identifier;
				$value = htmlspecialchars_decode($node['content'][$locale->getLanguage()] ?? $node['content']["*"] ?? "");

				if(empty($node['route_id']))
				{
					if(str_contains($identifier, "."))
					{
						$array = array();
						Arr::unflatten($array, $identifier, $value);

						$identifier = array("global" => $array);
						unset($array);
					}
					else
					{
						$identifier = array("global" => array($identifier => $value));
					}
					
					$identifier_flat = "global." . $identifier_flat;
				}
				elseif($this->instance->namespace != null)
				{
					$array = array();
					Arr::unflatten($array, $identifier, $value);

					$alias = ($this->instance->namespace['alias'] ?? $this->instance->namespace['pattern']);

					$identifier = array($alias => $array);
					$identifier_flat = $alias . "." . $identifier_flat;
					unset($array);
				}

				if(!empty($node['loop_id']))
				{
					list($row_id, $node_identifier) =  explode(".", $node['identifier'], 2);
					
					// Rewrite "row_id" from db starting from 0...++
					if(!isset($rows_relations[$node['loop_id']]))
					{
						$rows_relations[$node['loop_id']] = array();
					}

					if(!isset($rows_relations[$node['loop_id']][$row_id]))
					{
						$rows_relations[$node['loop_id']][$row_id] = count($rows_relations[$node['loop_id']]);
					}

					$row_id = $rows_relations[$node['loop_id']][$row_id];

					if(!isset($loops[$node['loop_id']]))
					{
						$loops[$node['loop_id']] = array($row_id => array());
					}
					elseif(!isset($loops[$node['loop_id']][$row_id]))
					{
						$loops[$node['loop_id']][$row_id] = array();
					}
					
					$loops[$node['loop_id']][$row_id][$node_identifier] = $node;
				}
				elseif($node['type'] == NodeType::LOOP->value)
				{
					$loops_relations[$node['id']] = [ $identifier_flat, $identifier, $node['parameters'] ];
				}
				else
				{
					$this->nodes = array_replace_recursive($this->nodes, $identifier);
				
					if($properties = $node['properties'][$locale->getLanguage()] ?? false)
					{
						array_walk_recursive($identifier, fn(&$value) => ($value = $properties));
						$this->properties = array_replace_recursive($this->properties, $identifier);
					}
				}
			}

			/**
			 *	Pair the loops with the nodes
			 */
			if(empty($loops_relations))
			{
				return $this;
			}

			/**
			 * 	If loop already exists (Probably from i18n) - null it and replace with new ones from this source
			 */
			foreach($loops_relations AS $node_id => $relation) // node_id = loop_id
			{
				list($identifier_flat, $identifier_array, $parameters) = $relation;

				$this->unmergers[] = $identifier_flat;
			
				// Loop exists from the source but has no entries
				if(!isset($loops[$node_id]))
				{
					// Since this loop has no items, store the structure ("nodes") as property instead
					array_walk_recursive($identifier_array, fn(&$v) => ($v = $parameters));
					$this->properties = array_replace_recursive($this->properties, $identifier_array);
				}
				else
				{
					foreach($loops[$node_id] AS $row_id => $nodes)
					{
						foreach($nodes AS $node_identifier => $node)
						{
							$arr = array();
							Arr::unflatten($arr, $identifier_flat, array($row_id => array($node_identifier => $node['content'][$locale->getLanguage()] ?? $node['content']["*"] ?? "")));
							$this->nodes = array_replace_recursive($this->nodes, $arr);

							if($properties = $node['properties'][$locale->getLanguage()] ?? $node['properties']["*"] ?? false)
							{
								array_walk_recursive($arr, fn(&$v) => $v = $properties);
								$this->properties = array_replace_recursive($this->properties, $arr);
							}
						}

						unset($arr);
					}
				}
			}

			return $this;
		}

		public function prepareFromDir(string $_dir, Locale $locale): self
		{
			return $this->prepareFromFile($_dir . "/" . $locale->getLanguage() . ".ini");
		}

		private function getLoop(DB $db, string $_loop_identifier): array
		{
			// Fetch existing loops (Extend if any exists)
			$condition = "";
			$values = [$_loop_identifier, NodeType::LOOP->value];

			if($this->instance->namespace != null && isset($this->instance->namespace['id']))
			{
				$condition = " OR `route_id`=?";
				$values[] = $this->instance->namespace['id'];
			}

			if(!$loop = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_nodes` WHERE `identifier`=? AND `type`=? AND (`route_id` IS NULL " . $condition . ") AND `deleted_at` IS NULL AND `hidden_at` IS NULL", $values)->asArray()[0] ?? false)
			{
				return array();
			}

			return $loop;
		}

		public function store(mixed $_what, $_into = null): self
		{
			// Convert to array, if Nodes
			if(is_array($_what))
			{
				foreach(array_filter($_what, fn($o) => $o instanceof NodeObject) AS $k => $v)
				{
					$_what[$k] = $v->asArray();
				}
			}

			if($_into instanceof DB || (!empty($this->storage) && $this->storage instanceof DB))
			{
				return $this->storeToDatabase($_into ?? $this->storage, $_what);
			}
			elseif($this->storage != null)
			{
				return $this->storeToIni($_what);
			}
			
			throw new Exception("Unexpected storage: " . ($_into ?? "No storage-file defined"));
		}

		private function storeToDatabase(DB $db, array $_nodes): self
		{
			foreach($_nodes AS $key => $node)
			{
				// If a loop
				$global = (isset($node['properties'], $node['properties']['global']) || isset($node['global'])) ? true : false;	
				unset($node['properties']['global'], $node['global']);

				if(isset($node['properties']) && empty($node['properties']))
				{
					unset($node['properties']);
				}

				if(array_is_list($node) || (isset($node['type']) && $node['type'] == NodeType::LOOP->value))
				{
					$identifier = $node['identifier'] ?? $key;

					// Update existing loop
					if($loop_array = $this->getLoop($db, $identifier))
					{
						// If the 'incoming' loop structure is actually the one we have stored, just skip this
						if(count($loop_array['parameters']) == count(($node['properties'] ?? $node)) && !array_diff(array_column($loop_array['parameters'], "identifier"), array_column(($node['properties'] ?? $node), "identifier")))
						{
							continue;
						}

						// Update!
						$db::update(Env::get("db")['database'].".`lcms_nodes`", array('parameters' => $node['properties'] ?? $node), array('id' => $loop_array['id']));
					}
					else
					{
						$db::insert(Env::get("db")['database'].".`lcms_nodes`", array(
							'route_id' 		=> ($global) ? null : (($this->instance->namespace != null && isset($this->instance->namespace['id'])) ? $this->instance->namespace['id'] : null),
							'identifier'	=> $identifier,
							'type'			=> NodeType::LOOP->value,
							'parameters'	=> $node['properties'] ?? $node // Snapshot of all nodes to be used here
						));
					}
				}
				elseif(str_starts_with($node['identifier'], "meta."))
				{
					// Meta-data
					if(!empty($this->instance->namespace) && isset($this->instance->namespace['id']))
					{
						$db::query("UPDATE ".Env::get("db")['database'].".`lcms_routes` SET `meta` = JSON_MERGE_PATCH(`meta`, ?) WHERE `id`=?", [ array(Locale::getLanguage() => [explode(".", $node['identifier'], 2)[1] => $node['content']]), $this->instance->namespace['id'] ]);
					}
					else
					{
						throw new Exception("Namespace is missing for storing meta data");
					}
				}
				else
				{
					$db::insert(Env::get("db")['database'].".`lcms_nodes`", array(
						'route_id' 		=> ($global) ? null : (($this->instance->namespace != null && isset($this->instance->namespace['id'])) ? $this->instance->namespace['id'] : null),
						'identifier'	=> $node['identifier'],
						'type'			=> $node['type'],
						'parameters'	=> $node['parameters'] ?? null,
						'properties'	=> (isset($node['properties'])) ? array(Locale::getLanguage() => $node['properties'] ?? array()) : null,
						'content'		=> array(Locale::getLanguage() => $node['content'] ?? "")
					));
				}
			}
			
			return $this;
		}

		private function storeToIni($_nodes): self
		{
			if(!is_writable($this->storage['filename']))
			{
				throw new Exception("Cant store nodes in " . $this->storage['filename'] . " (Unwriteable, needs 0666 permission)");
			}

			// If we've already written to the ini file, let's refresh it
			if($this->has_written_to_ini && is_array($this->storage) && isset($this->storage['filename']))
			{
				$this->storage['content'] = parse_ini_file($this->storage['filename'], true);
			}

			$existing_file_array = $this->storage['content'];
			$loops = array();
			$new_entrys = 0;

			// Only store if this Route has an Alias OR is global
			foreach($_nodes AS $identifier => $node)
			{
				if((!isset($node['global']) || !$node['global']) && $this->instance->namespace == null)
				{
					continue;
				}

				$alias = (isset($node['global']) && $node['global']) ? "global" : ($this->instance->namespace['alias'] ?? $this->instance->namespace['pattern']);

				if(!isset($existing_file_array[$alias]))
				{
					if($alias == "global") // prepend
					{
						$existing_file_array = array('global' => array(), ...$existing_file_array);
					}
					else
					{
						$existing_file_array[$alias] = array();
					}
				}

				// Loop
				if(!isset($node['identifier']) || (isset($node['type']) && $node['type'] == Node::TYPE_LOOP->value))
				{
					if(!isset($loops[$alias]))
					{
						$loops[$alias] = array();
					}

					$iterator = $node['properties'] ?? $node;

					foreach($iterator AS $prop)
					{
						$second_iterator = (isset($node['items'])) ? $node['items'] : array($prop);

						foreach($second_iterator AS $row => $items)
						{
							$id = ($node['identifier'] ?? $identifier) . ".".$row.".".$prop['identifier'];

							if(isset($existing_file_array[$alias][$id]))
							{
								continue;
							}

							// New entry
							$loops[$alias][$id] = $prop['properties'] ?? "";
							$new_entrys++;
						}
					}
				}
				else
				{
					// Only overwrite if necessary
					if(isset($existing_file_array[$alias][$node['identifier']]) && $existing_file_array[$alias][$node['identifier']] == $node['content'])
					{
						continue;
					}

					if($properties = (!empty($node['properties'])) ? array_filter($node['properties']) : null)
					{
						$existing_file_array[$alias][$node['identifier']] = array('content' => $node['content']) + $properties;
					}
					else
					{
						$existing_file_array[$alias][$node['identifier']] = $node['content'] ?? $node['properties']['src'] ?? "";
					}

					$new_entrys++;
				}
			}

			if(!empty($loops))
			{
				foreach($loops AS $alias => $node)
				{
					$existing_file_array[$alias] = array_merge($existing_file_array[$alias], $node);
				}
			}

			/**
			 *	Let's write
			 */
			if($new_entrys > 0)
			{
				$new_file_content = trim($this->array_to_ini($existing_file_array));

				if(!$this->writeToIniFile($new_file_content))
				{
					throw new Exception("Cant write Routes to ini-file: " . $this->storage['filename']);
				}
			}

			return $this;
		}

		private function writeToIniFile($_content)
		{
			$fp = fopen($this->storage['filename'], 'w');

			$start_time = microtime(true);

			do
			{
				$writeable = flock($fp, LOCK_EX);

				// If lock not obtained sleep for 0 - 100 milliseconds, to avoid collision and CPU load
				if(!$writeable) 
				{
					usleep(round(rand(0, 100) * 1000));
				}
			} 
			while (!$writeable && (microtime(true) - $start_time) < 5);

			//file was locked so now we can store information
			if ($writeable)
			{            
				fwrite($fp, $_content);
				flock($fp, LOCK_UN);
			}

			fclose($fp);

			$this->storage['content'] = $_content;

			$this->has_written_to_ini = true;

			return true;
		}

		private function prepareFromIni($_file): self
		{
			$file_content = parse_ini_file($_file, true);

			// Unflatten the file-content
			foreach($file_content AS $key => $value)
			{
				foreach($value AS $k => $v)
				{
					if(is_array($v) && !empty($v)) // Unpack properties
					{
						Arr::unflatten($this->properties[$key], $k, $v);
					}

					Arr::unflatten($this->nodes[$key], $k, (is_array($v)) ? "" : $v);
				}
			}

			$this->storage = array('filename' => $_file, 'content' => $file_content);

			return $this;
		}

		public function prepareFromArray($_array): self
		{
			$flattened_array = Arr::flatten($_array);

			foreach($flattened_array AS $identifier => $node)
			{
				self::$nodes[$identifier] = array('content' => $node);
			}

			return $this;
		}

		/**
		 *	Construct the new routes as the format they came as
		 */
		public function execute($_storage): self
		{
			if(empty($this->nodes))
			{
				return $this;
			}

			// Unflatten the file-content
			$this->instance->merge($this->nodes, $this->properties, $this->unmergers);

			return $this;
		}

		/**
		 * 	2022-02-08: Now handles array values (attributes)
		 */
		private function array_to_ini(array $_array): string
		{
			return array_reduce(array_keys($_array), function($str, $sectionName) use ($_array) 
			{
				$sub = $_array[$sectionName];

				$str .= "[" . $sectionName . "]" . PHP_EOL;

				foreach($sub AS $key => $value)
				{
					// Loop
					if(is_array($value))
					{
						foreach(array_filter($value, fn($k) => !is_numeric($k), ARRAY_FILTER_USE_KEY) AS $k => $v)
						{
							$str .= $key . '[' . $k . '] = "' . $v . '"' . PHP_EOL;
						}
					}
					else
					{
						$str .= $key . ' = "' . $value . '"' . PHP_EOL; 
					}
				}

				return $str . PHP_EOL;
			});
		}

		private function buildNode($row): array
		{
			return array_merge($row, array(
				'content'		=> $row['content'] ?? null,
				'parameters'	=> $row['parameters'] ?? null,
				'properties' 	=> $row['properties'] ?? null
			));
		}		
	}

	class RouteMerge extends BaseMerge
	{
		private $system_routes_patterns = ["404", "500", "api", "sitemap.xml", "robots.txt", "r", "/"];
		private $database_routes = array();
		private $system_routes = array();

		/**
		 *	Only GET-routes
		 */
		public function prepareFromDatabase(DB $db, Env $env): self
		{
			$this->instance->bindControllerRoutes();

			$this->system_routes = $this->instance->routes;
			
			if(!$routes = $db->query("SELECT * FROM ".$env->get("db")['database'].".`lcms_routes`")->asArray())
			{
				return $this;
			}

			foreach($routes AS $row)
			{
				$this->database_routes[$row['id']] = $this->buildRoute($row);
			}

			return $this;
		}

		private function pair($system_route): array
		{
			foreach($this->database_routes AS $k => $lcms_route)
			{
				if((!empty($system_route['alias']) && $system_route['alias'] == $lcms_route['alias'])
					  || (!empty($lcms_route['snapshot']) && $system_route['pattern'] == $lcms_route['snapshot']['pattern']))
				{
					unset($this->database_routes[$k]); // Remove this entry from LCMS

					return array_merge($system_route, $lcms_route);
				}
			}

			return $system_route;
		}

		private function createRoute(DB $db, $_route): int
		{
			$snapshot = array(
				'pattern' => $_route['org_pattern'] ?? $_route['pattern']
			);

			if(isset($_route['controller'], $_route['action']))
			{
				$snapshot['controller']	= $_route['controller'];
				$snapshot['action']		= $_route['action'];
			}		

			if(isset($_route['alias']) && !empty($_route['alias']))
			{
				$snapshot['alias'] = $_route['alias'];
			}

			$locale = (in_array(strtolower($_route['pattern']), $this->system_routes_patterns)) ? "*" : Locale::getLanguage();

			$db::insert(Env::get("db")['database'].".`lcms_routes`", array(
				'parent_id'		=> $_route['parent_id'] ?? null,
				'alias'			=> (isset($_route['alias']) && !empty($_route['alias'])) ? $_route['alias'] : null,
				'pattern'		=> array($locale => $_route['pattern']),
				'controller'	=> $_route['controller'] ?? null,
				'action'		=> $_route['action'] ?? null,
				'external_url'	=> $_route['external_url'] ?? null,
				'settings'		=> $_route['settings'] ?? array(),
				'meta'			=> $_route['meta'] ?? array(),
				'snapshot'		=> $snapshot
			));

			return $db::last_insert_id();
		}

		private function buildRoute($row): array
		{
			$row['org_pattern'] = array_filter($row['pattern']);
			$row['pattern'] = $row['pattern'][Locale::getLanguage()] ?? $row['pattern'][array_key_first($row['pattern'])];	

			if(!empty($row['parent_id']))
			{
				$row['pattern'] = $this->database_routes[$row['parent_id']]['pattern'] . "/" . $row['pattern'];

				foreach($row['org_pattern'] AS $lang => $pattern)
				{
					if(!$parent_pattern = $this->database_routes[$row['parent_id']]['org_pattern'][$lang] ?? false)
					{
						unset($row['org_pattern'][$lang]);
						continue;
					}

					$row['org_pattern'][$lang] = $this->database_routes[$row['parent_id']]['org_pattern'][$lang] . "/" . $pattern;
				}
			}

			if(empty($row['org_pattern']))
			{
				unset($row['org_pattern']);
			}			

			return $row;
		}

		public function execute($_storage): self
		{
			$relations = array();

			foreach($this->system_routes AS $key => $route)
			{
				if(!in_array($key, $this->instance->map[Request::METHOD_GET])) // Post variable
				{
					/**
					 *	If parent has changed it's pattern, just replace it here
					 */
					if(!isset($route['parent']) || !isset($this->system_routes[$route['parent']]['pattern'])) // Disabled because parent is disabled
					{
						continue;
					}
					elseif(substr($route['pattern'], 0, strlen($this->system_routes[$route['parent']]['pattern'])) !== $this->system_routes[$route['parent']]['pattern'])
					{
						$this->system_routes[$key]['pattern'] = str_replace($route['pattern'], $this->system_routes[$route['parent']]['pattern'], $this->system_routes[$key]['pattern']);
					}
				}
				else
				{
					if(!empty($this->database_routes))
					{
						$this->system_routes[$key] = $this->pair($route);
					}

					if(!isset($this->system_routes[$key]['id']))
					{
						$class = $route['controller'];
						$c = new \ReflectionClass($class);

						try
						{
							if((!$method = $c->getMethod($route['action'])) || empty($method) || empty($method->getReturnType()))
							{
								continue;
							}
						}
						catch(Exception $e)
						{
							continue;
						}

						$returnType = $method->getReturnType();

						if($returnType instanceof \ReflectionUnionType)
						{
							$parts = array_unique(array_merge(...array_map(fn($type) => explode("\\", $type->getName()), $returnType->getTypes())));
						}
						else
						{
							$parts = explode("\\", $returnType->getName());
						}
						
						// Only store method with View returned
						if(!in_array("View", $parts))
						{
							continue;
						}

						$route['org_pattern'] 	= $route['pattern'];
						$route['parent_id'] 	= (isset($route['parent'])) ? $this->system_routes[$route['parent']]['id'] : null;
						$route['pattern'] 		= (isset($route['parent'])) ? str_replace($this->system_routes[$route['parent']]['pattern'] . "/", "", $route['pattern']) : $route['pattern'];

						$this->system_routes[$key]['id'] = $this->createRoute($_storage, $route);
					}

					$relations[$this->system_routes[$key]['id']] = $key;
				}
			}

			if(empty($relations))
			{
				return $this;
			}
			elseif(empty($this->database_routes))
			{
				$this->instance->merge($this->system_routes);
				return $this;
			}
			
			foreach($this->database_routes AS $k => $route)
			{
				$key = count($this->system_routes);
				$relations[$route['id']] = $key;

				$this->system_routes[] = $route; // + array('key' => $key); --> Added in 'Route::merge'

				if(!empty($route['parent_id']))
				{
					if(!isset($this->system_routes[$relations[$route['parent_id']]]['children']))
					{
						$this->system_routes[$relations[$route['parent_id']]]['children'] = array();
					}

					$this->system_routes[$relations[$route['parent_id']]]['children'][] = $key;
				}

				unset($this->database_routes[$k]);
			}

			$this->instance->merge($this->system_routes);

			return $this;
		}

		private function storeToDatabase(DB $db, $_settings): self
		{
			$settings = array(Locale::getLanguage() => array());

			foreach($_settings AS $key => $value)
			{
				$settings[Locale::getLanguage()][$key] = array(
					'value' => $value,
					'type' => match(gettype($value))
					{
						"boolean" 	=> NodeType::BOOLEAN->value,
						"array" 	=> NodeType::ARRAY->value,
						default 	=> NodeType::TEXT->value
					}
				);
			}

			$db::query("UPDATE ".Env::get("db")['database'].".`lcms_routes` SET `settings` = JSON_MERGE_PATCH(`settings`, ?) WHERE `id`=?", [ $settings, $this->instance->current['id'] ]);
		
			return $this;
		}

		public function store($_what, $_into = null): self
		{
			$_into = $_into ?? $this->storage;

			if(!$_into instanceof DB)
			{
				throw new Exception("Cant merge Page Settings w/o DB instance");
			}

			return $this->storeToDatabase($_into, $_what);
		}
	}

	class NavigationsMerger extends BaseMerge
	{
		private $database_navs = array();
		private $system_navs = array();

		public function prepareFromDatabase(DB $db): self
		{
			$this->system_navs = $this->instance->getAll();

			// Only select those menu items which has enabled routes
			if(!$navs = $db::query("SELECT `mi`.*, `r`.`alias` AS `route`, `r`.`settings` AS `route_settings`, `r`.`parent_id` AS `route_parent_id`
									FROM ".Env::get("db")['database'].".`lcms_navigations` AS `mi` 
										LEFT JOIN ".Env::get("db")['database'].".`lcms_routes` AS `r` ON(`r`.`id` = `mi`.`route_id`) 
											WHERE `mi`.`deleted_at` IS NULL ORDER BY `mi`.`parent_id` ASC, `mi`.`id` ASC")->asArray())
			{
				return $this;
			}

			foreach($navs AS $row)
			{
				if(!isset($this->database_navs[$row['navigation']]))
				{
					$this->database_navs[$row['navigation']] = array();
				}

				$this->database_navs[$row['navigation']][$row['id']] = $this->buildNavItem($row);
			}

			return $this;
		}

		private function pair($nav_identifier, $system_nav_item)
		{
			$snapshot = array('title' => $system_nav_item['title'] ?? null, 'route' => $system_nav_item['route'] ?? null, 'parameters' => $system_nav_item['parameters'] ?? null);

			foreach($this->database_navs[$nav_identifier] AS $k => $lcms_nav_item)
			{
				if(empty($lcms_nav_item['snapshot']) || !($lcms_nav_item['snapshot'] == $snapshot))
				{
					continue;
				}

				unset($this->database_navs[$nav_identifier][$k]); // Remove this entry from LCMS

				if(empty($this->database_navs[$nav_identifier]))
				{
					unset($this->database_navs[$nav_identifier]);
				}
				
				return array_merge($system_nav_item, $lcms_nav_item);
			}

			return $system_nav_item;
		}

		private function createNavItem(DB $db, $_nav_identifier, $_nav_item, $_parent_id = null): array
		{
			$db::insert(Env::get("db")['database'].".`lcms_navigations`", array(
				'navigation' 	=> $_nav_identifier,
				'parent_id'		=> $_parent_id, //_nav_item['parent_id'] ?? null,
				'title'			=> (isset($_nav_item['title']) && !empty($_nav_item['title'])) ? array(Locale::getLanguage() => $_nav_item['title']) : null,
				'route_id'		=> (isset($_nav_item['route']) && !empty($_nav_item['route'])) ? Route::asItem($_nav_item['route'])['id'] ?? null : null,
				'parameters'	=> (isset($_nav_item['parameters']) && !empty($_nav_item['parameters'])) ? $_nav_item['parameters'] : null,
				'snapshot'		=> array('title' => $_nav_item['title'] ?? null, 'route' => $_nav_item['route'] ?? null, 'parameters' => $_nav_item['parameters'] ?? null),
				'order'			=> $_nav_item['order'] ?? 99
			));

			$_nav_item['id'] = $db::last_insert_id();

			if($_parent_id)
			{
				$_nav_item['parent_id'] = $_parent_id;
			}

			return $_nav_item;
		}

		private function updateNavItem(DB $db, int $_nav_item_id, array $_params): void
		{
			$db::update(Env::get("db")['database'].".`lcms_navigations`", $_params, ['id' => $_nav_item_id]);
		}

		/**
		 *	Merge everything together
		 */
		protected function execute($_storage): self
		{
			if(empty($this->system_navs))
			{
				return $this;
			}

			$relations = $system_items = array();

			foreach($this->system_navs AS $navigation => $nav_object)
			{
				$system_items[$navigation] = $nav_object->items();
				$disables = array();

				foreach($system_items[$navigation] AS $nav_item_key => $nav_item)
				{
					if(isset($this->database_navs[$navigation]) && !empty($this->database_navs[$navigation]))
					{
						$system_items[$navigation][$nav_item_key] = $this->pair($navigation, $nav_item);
					}

					if(!isset($system_items[$navigation][$nav_item_key]['id']))
					{
						$parent_id = (isset($nav_item['parent'], $system_items[$navigation][$nav_item['parent']], $system_items[$navigation][$nav_item['parent']]['id'])) ? $system_items[$navigation][$nav_item['parent']]['id'] : null;
						$system_items[$navigation][$nav_item_key] = array_merge($system_items[$navigation][$nav_item_key], $this->createNavItem($_storage, $navigation, $nav_item, $parent_id));
					}

					$route_settings = $system_items[$navigation][$nav_item_key]['route_settings'] ?? null;
					unset($system_items[$navigation][$nav_item_key]['route_settings']);

					// Disabled route?
					if(!empty($route_settings) && isset($route_settings[Locale::getLanguage()], $route_settings[Locale::getLanguage()]['disabled']) && $route_settings[Locale::getLanguage()]['disabled']['value'])
					{
						$disables[] = $system_items[$navigation][$nav_item_key]['route_id'];
					}

					$relations[$navigation][$system_items[$navigation][$nav_item_key]['id']] = $nav_item_key;
				}

				if(!empty($disables))
				{
					$disables = array_unique($disables);

					foreach(array_filter($system_items[$navigation], fn($i) => in_array($i['route_id'], $disables) || in_array($i['route_parent_id'], $disables)) AS $nav_item_key => $nav_item)
					{
						$system_items[$navigation][$nav_item_key]['disabled'] = true;
					}
				}

				// Pair with parents
				foreach(array_filter($system_items[$navigation], fn($i) => isset($i['parent']) && !isset($i['parent_id'])) AS $nav_item_key => $nav_object)
				{
					$this->updateNavItem($_storage, $nav_object['id'], ['parent_id' => $system_items[$navigation][$nav_object['parent']]['id']]);
					$system_items[$navigation][$nav_item_key]['parent_id'] = $system_items[$navigation][$nav_object['parent']]['id'];
				}
			}

			if(empty($relations))
			{
				return $this;
			}

			if(empty($this->database_navs))
			{
				$this->instance->merge($system_items);
				return $this;
			}

			/**
			 *	Items found in Database, add them too (+ pair with children)
			 */
			foreach($this->database_navs AS $nav_identifier => $nav_items)
			{
				foreach($nav_items AS $nav_item)
				{
					$re_key = count($system_items[$nav_identifier]);
					$nav_item['key'] = $re_key;

					if(!empty($nav_item['parent_id']))
					{
						// Find parent
						if(false === $find_key = $relations[$nav_identifier][$nav_item['parent_id']] ?? $nav_items[$nav_item['parent_id']]['key'] ?? $system_items[$nav_identifier][$nav_item['parent_id']] ?? false)
						{
							continue;
						}
						elseif(!isset($system_items[$nav_identifier][$find_key]['children']))
						{
							$system_items[$nav_identifier][$find_key]['children'] = array();
						}

						$system_items[$nav_identifier][$find_key]['children'][] = $re_key;
						$nav_item['parent'] = $find_key;
					}

					$system_items[$nav_identifier][$re_key] = $nav_item; //[$key];
				}
			}

			$this->instance->merge($system_items);

			return $this;
		}

		private function buildNavItem($row): array
		{
			if(isset($row['title']) && !empty($row['title']) && is_array($row['title']))
			{
				$row['title'] = $row['title'][Locale::getLanguage()] ?? "";
			}

			if(empty($row['route_id']) && isset($row['snapshot'], $row['snapshot']['route']) && !empty($row['snapshot']['route']))
			{
				$row['route'] = $row['snapshot']['route'];
			}

			$hidden_at = (!empty($row['hidden_at'])) ? array_filter($row['hidden_at']) : null;
			unset($row['hidden_at']);

			if(!empty($hidden_at) && isset($hidden_at[Locale::getLanguage()]))
			{
				$row['hidden_at'] = $hidden_at[Locale::getLanguage()];
			}

			$row['order'] = (int) $row['order'];

			return $row;
		}
	}

	class EnvMerge extends BaseMerge
	{
		private $excluded_keys = ['is_dev', 'db'];
		private $items;

		public function prepareFromFile(string $_file): self
		{
			$from_file = require $_file;
			$this->items = (empty($this->items)) ? $from_file : array_merge($this->items, $from_file);
			unset($from_file);

			return $this;
		}

		public function prepareFromArray(array $_array): self
		{
			$this->items = (empty($this->items)) ? $_array : array_merge($this->items, $_array);

			return $this;
		}

		public function prepareFromDatabase(DB $db): self
		{
			if(!$rows = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_settings` WHERE `key` NOT IN('".implode("', '", $this->excluded_keys)."')")->asArray())
			{
				return $this;
			}

			foreach($rows AS $row)
			{
				$this->items[$row['key']] = $row['value'];
			}

			return $this;
		}

		protected function execute($_storage): self
		{
			if(empty($this->items))
			{
				return $this;
			}

			$this->instance->merge($this->items);

			return $this;
		}
	}

	class LocaleMerge extends BaseMerge
	{
		private $languages = array();

		public function prepareFromDir(string $_dir): self
		{
			foreach(new \DirectoryIterator($_dir) AS $item) 
			{
				if(!$item->isFile() || $item->getExtension() != "ini") 
				{
					continue;
				}

				$this->languages[] = explode(".", $item->getFilename())[0];
			}

			return $this;
		}

		protected function execute($_storage): self
		{
			if(empty($this->languages))
			{
				return $this;
			}

			$this->instance->setLanguages($this->languages);

			return $this;
		}
	}
?>