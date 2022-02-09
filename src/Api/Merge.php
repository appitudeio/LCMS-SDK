<?php
	/** 
	 *
	 */
	namespace LCMS\Api;

	use LCMS\Core\Env;
	use LCMS\Core\Database as DB;
	use LCMS\Core\Route;
	use LCMS\Core\Navigations;
	use LCMS\Core\Node;
	use LCMS\Core\Request;
	use LCMS\Core\Locale;
	use LCMS\Utils\Arr;
	use \Exception;

	class Merge
	{
		private $object;
		private $merger;

		function __construct($_obj)
		{
			$this->object = $_obj;
		}

		public function getFamily()
		{
			return (new \ReflectionClass($this->object))->getShortName();
		}

		public function with($_storage)
		{
			if($this->merger)
			{
				return $this->merger->merge($_storage);
			}

			if($this->object instanceof Node)
			{
				$this->merger = new NodeMerge($this->object);
			}
			elseif($this->object instanceof Route)
			{
				$this->merger = new RouteMerge($this->object);
			}
			elseif($this->object instanceof Navigations)
			{
				$this->merger = new NavigationsMerger($this->object);
			}
			elseif($this->object instanceof Env)
			{
				$this->merger = new EnvMerge($this->object);
			}
			else
			{
				throw new Exception("No merger available from Object");
			}

			return $this->with($_storage);
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

		function __construct($_instance)
		{
			$this->instance = $_instance;		
		}

		protected function prepare($_storage): Self
		{
			$this->storage = $_storage;

			if($_storage instanceof DB)
			{
				return $this->prepareFromDatabase($_storage);
			}
			elseif(is_array($_storage))
			{
				return $this->prepareFromArray($_storage);
			}			
			elseif(is_file($_storage))
			{
				return $this->prepareFromFile($_storage);
			}

			throw new Exception("Invalid preparation storage: " . $_storage);
		}

		protected function prepareFromDatabase(DB $db): Self
		{}

		protected function prepareFromArray($_array): Self
		{}		

		protected function prepareFromFile($_file): Self
		{
			throw new Exception(get_class($this) . " cant prepare from File");
		}

		protected function store($_what, $_into = null): Self
		{
			throw new Exception(get_class($this) . " does not support storage");
		}

		public function merge($_storage)
		{
			return $this->prepare($_storage)->execute($_storage);
		}

		protected function execute($_storage): Mixed
		{
			return $this->instance;
		}	
	}

	class NodeMerge extends BaseMerge
	{
		public $nodes;
		public $properties;
		private $unmergers;
		
		protected function prepareFromFile($_file): Self
		{
			$this->nodes = array();
			$this->properties = array();
			$this->unmergers = array();

			return $this->prepareFromIni($_file);
		}

		protected function prepareFromDatabase(DB $db): Self
		{
			$this->nodes = array();
			$this->properties = array();
			$this->unmergers = array();

			$condition = ($this->instance::$namespace != null && isset($this->instance::$namespace['id'])) ? " OR `route_id`=".$this->instance::$namespace['id'] : null;

			$query = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_nodes` 
									WHERE (`route_id` IS NULL " . $condition . ") 
										AND `deleted_at` IS NULL 
										AND JSON_EXTRACT(`hidden_at`, '$.".Locale::getLanguage()."') IS NULL
										AND (
											`type`=".Node::TYPE_LOOP."
											OR JSON_EXTRACT(`content`, '$.".Locale::getLanguage()."') > 0 
											OR JSON_EXTRACT(`content`, '$.*') > 0
										)
											ORDER BY `order` ASC, `id` ASC");

			if($db::num_rows($query) == 0)
			{
				return $this;
			}

			$loops = array();
			$loops_relations = array();

			while($row = $db::fetch_assoc($query))
			{
				$node = $this->buildNode($row);

				$identifier = $node['identifier'];
				$value = htmlspecialchars_decode($node['content'][Locale::getLanguage()] ?? $node['content']["*"] ?? "");

				if(empty($node['route_id']))
				{
					$identifier = array("global" => array($identifier => $value));
				}
				elseif($this->instance::$namespace != null)
				{
					$array = array();
					Arr::unflatten($array, $identifier, $value);

					$identifier = array(($this->instance::$namespace['alias'] ?? $this->instance::$namespace['pattern']) => $array);
					unset($array);
				}				

				if(!empty($node['loop_id']))
				{
					$row_id = explode(".", $node['identifier'], 2)[0];

					if(!isset($loops[$node['loop_id']]))
					{
						$loops[$node['loop_id']] = array();
					}
					elseif(!isset($loops[$node['loop_id']][$row_id]))
					{
						$loops[$node['loop_id']][$row_id] = array();
					}
					
					$loops[$node['loop_id']][$row_id][explode(".", $node['identifier'], 2)[1]] = $node;
				}
				elseif($node['type'] == Node::TYPE_LOOP)
				{
					$loops_relations[$node['id']] = array_key_first(Arr::flatten($identifier));
				}
				else
				{
					$this->nodes = array_replace_recursive($this->nodes, $identifier);
				
					if(isset($node['properties'][Locale::getLanguage()]) && !empty($node['properties'][Locale::getLanguage()]) && $properties = $node['properties'][Locale::getLanguage()])
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
			foreach($loops_relations AS $node_id => $identifier)
			{
				$this->unmergers[] = $identifier;
				
				foreach($loops[$node_id] AS $key => $nodes)
				{
					foreach($nodes AS $k => $node)
					{
						$array = array();
						Arr::unflatten($array, $identifier, array($key => array($k => $node['content'][Locale::getLanguage()] ?? $node['content']["*"] ?? "")));

						$this->nodes = array_replace_recursive($this->nodes, $array);

						if(isset($node['properties'][Locale::getLanguage()]) && !empty($node['properties'][Locale::getLanguage()]) && $properties = $node['properties'][Locale::getLanguage()])
						{
							array_walk_recursive($array, fn(&$value) => ($value = $properties));
							$this->properties = array_replace_recursive($this->properties, $array);
						}

						unset($array);
					}
				}
			}

			return $this;
		}

		private function getLoop(DB $db, $loop_identifier)
		{
			// Fetch existing loops (Extend if any exists)
			$condition = ($this->instance::$namespace != null && isset($this->instance::$namespace['id'])) ? " OR `route_id`=".$this->instance::$namespace['id'] : null;

			$query = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_nodes` WHERE `identifier`='".$loop_identifier."' AND (`route_id` IS NULL " . $condition . ") AND `type`=".Node::TYPE_LOOP." AND `deleted_at` IS NULL AND `hidden_at` IS NULL");

			if($db::num_rows($query) == 0)
			{
				return array();
			}

			return $db::fetch_assoc($query);
		}		

		public function store($_what, $_into = null): Self
		{
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

		private function storeToDatabase(DB $db, $_nodes): Self
		{
			/**
			 *	Find out if a loop
			 */
			foreach($_nodes AS $key => $node)
			{
				// If a loop
				if(!isset($node['identifier']))
				{
					$loop_array = $this->getLoop($db, $key);

					if(!$loop_array)
					{
						$db::insert(Env::get("db")['database'].".`lcms_nodes`", array(
							'route_id' 		=> ((!isset($n['global']) || !$n['global']) && $this->instance::$namespace != null && isset($this->instance::$namespace['id'])) ? $this->instance::$namespace['id'] : null,
							'identifier'	=> $key,
							'type'			=> Node::TYPE_LOOP,
							'parameters'	=> $node // Snapshot of all nodes to be used here
						));
					}
					else
					{
						// Loop exists, update the params
						$org_parameters = json_decode($loop_array['parameters'], true);
						$new_parameters = (empty(!$org_parameters)) ? $org_parameters : array();

						foreach($node AS $n)
						{
							if(in_array($n['identifier'], array_column($org_parameters, "identifier")))
							{
								continue;
							}

							$n['content'] = ""; //array(Locale::getLanguage() => $n['content']);
							$new_parameters[] = $n;
						}

						if(count($new_parameters) != count($org_parameters))
						{
							$db::update(Env::get("db")['database'].".`lcms_nodes`", array('parameters' => $new_parameters), array('id' => $loop_array['id']));
						}
					}
				}
				elseif(str_starts_with($node['identifier'], "meta."))
				{
					// Meta-data
					if(!empty($this->instance::$namespace) && isset($this->instance::$namespace['id']))
					{
						$db::query("UPDATE ".Env::get("db")['database'].".`lcms_routes` SET `meta` = JSON_MERGE_PATCH(`meta`, ?) WHERE `id`=?", [ array(Locale::getLanguage() => [explode(".", $node['identifier'], 2)[1] => $node['content']]), $this->instance::$namespace['id'] ]);
					}
					else
					{
						throw new Exception("Namespace is missing for storing meta data");
					}
				}
				else
				{
					// Default node
					$db::insert(Env::get("db")['database'].".`lcms_nodes`", array(
						'route_id' 		=> ((!isset($node['global']) || !$node['global']) && $this->instance::$namespace != null && isset($this->instance::$namespace['id'])) ? $this->instance::$namespace['id'] : null,
						'identifier'	=> $node['identifier'],
						'type'			=> $node['type'],
						'parameters'	=> $node['parameters'] ?? null,
						'properties'	=> (isset($node['properties'])) ? array(Locale::getLanguage() => $node['properties']) : null,
						'content'		=> array(Locale::getLanguage() => $node['content'])
					));
				}
			}
			
			return $this;
		}

		private function storeToIni($_nodes): Self
		{
			if(!is_writable($this->storage['filename']))
			{
				throw new Exception("Cant store nodes in " . $this->storage['filename'] . " (Unwriteable, needs 0666 permission)");
			}

			$existing_file_array = $this->storage['content'];
			$loops = array();
			$new_entrys = 0;

			// Only store if this Route has an Alias OR is global
			foreach($_nodes AS $identifier => $node)
			{
				if((!isset($node['global']) || !$node['global']) && $this->instance::$namespace == null)
				{
					continue;
				}

				$alias = (isset($node['global']) && $node['global']) ? "global" : ($this->instance::$namespace['alias'] ?? $this->instance::$namespace['pattern']);

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
				if(!isset($node['identifier']))
				{
					if(!isset($loops[$alias]))
					{
						$loops[$alias] = array();
					}

					foreach($node AS $k => $n)
					{
						$id = $identifier . ".1." . $n['identifier'];

						// Only overwrite if necessary
						if(isset($existing_file_array[$alias][$id]) && $existing_file_array[$alias][$id] == $n['content'])
						{
							continue;
						}

						$loops[$alias][$id] = $n['content'];

						$new_entrys++;
					}
				}
				else
				{
					// Only overwrite if necessary
					if(isset($existing_file_array[$alias][$node['identifier']]) && $existing_file_array[$alias][$node['identifier']] == $node['content'])
					{
						continue;
					}

					if(isset($node['properties']) && !empty($node['properties']))
					{
						$existing_file_array[$alias][$node['identifier']] = array('content' => $node['content']) + $node['properties'];
					}
					else
					{
						$existing_file_array[$alias][$node['identifier']] = $node['content'];
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
					throw new Exception("Cant write Routes to ini-file: " . $_file);
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

			return true;
		}

		private function prepareFromIni($_file): Self
		{
			$file_content = parse_ini_file($_file, true);

			// Unflatten the file-content
			foreach($file_content AS $key => $value)
			{
				foreach($value AS $k => $v)
				{
					if(is_array($v))
					{
						if($content = $v['text'] ?? $v['content'] ?? $v['innertext'] ?? $v['src'] ?? $v['label'] ?? false)
						{
							Arr::unflatten($this->nodes[$key], $k, $content);
							unset($v['text'], $v['content'], $v['innertext'], $v['src'], $v['label'], $content);
						}

						if(!empty($v))
						{
							Arr::unflatten($this->properties[$key], $k, $v);
						}
					}
					else
					{
						Arr::unflatten($this->nodes[$key], $k, $v);
					}
				}
			}

			$this->storage = array('filename' => $_file, 'content' => $file_content);

			return $this;
		}

		protected function prepareFromArray($_array): Self
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
		public function execute($_storage): Node
		{
			if(empty($this->nodes))
			{
				return $this->instance;
			}

			// Unflatten the file-content
			return $this->instance->merge($this->nodes, $this->properties, $this->unmergers);
		}

		/**
		 * 	2022-02-08: Now handles array values (attributes)
		 */
		private function array_to_ini(array $array): string
		{
			return array_reduce(array_keys($array), function($str, $sectionName) use ($array) 
			{
				$sub = $array[$sectionName];

				$str .= "[" . $sectionName . "]" . PHP_EOL;

				foreach($sub AS $key => $value)
				{
					if(is_array($value))
					{
						foreach($value AS $k => $v)
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

			/*return array_reduce(array_keys($array), function($str, $sectionName) use ($array) 
			{
				$sub = $array[$sectionName];

				return $str . "[$sectionName]" . PHP_EOL .
					array_reduce(array_keys($sub), fn($str, $key) => $str . $key . ' = "' . $sub[$key] . '"' . PHP_EOL) . PHP_EOL;
			});*/
		}

		private function buildNode($row)
		{
			return array_merge($row, array(
				'content'		=> (!empty($row['content'])) ? json_decode($row['content'], true) : null,
				'parameters'	=> (!empty($row['parameters'])) ? json_decode($row['parameters'], true) : null,
				'properties' 	=> (!empty($row['properties'])) ? json_decode($row['properties'], true) : null
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
		protected function prepareFromDatabase(DB $db): Self
		{
			$this->instance::bindControllerRoutes();

			$this->system_routes = $this->instance::$routes;

			$query = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_routes`");

			if($db::num_rows($query) == 0)
			{
				return $this;
			}

			while($row = $db::fetch_assoc($query))
			{
				$this->database_routes[$row['id']] = $this->buildRoute($row);
			}

			return $this;
		}

		private function pair($system_route)
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

		private function createRoute(DB $db, $_route)
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

		private function buildRoute($row)
		{
			if(isset($row['parameters']) && !empty($row['parameters']) && !is_array($row['parameters']))
			{
				$row['parameters'] = json_decode($row['parameters'], true);
			}

			if(isset($row['settings']) && !empty($row['settings']) && !is_array($row['settings']))
			{
				$row['settings'] = json_decode($row['settings'], true);
			}

			if(isset($row['meta']) && !empty($row['meta']) && !is_array($row['meta']))
			{
				$row['meta'] = json_decode($row['meta'], true);
			}

			if(isset($row['snapshot']) && !empty($row['snapshot']) && !is_array($row['snapshot']))
			{
				$row['snapshot'] = json_decode($row['snapshot'], true);
			}

			if(isset($row['pattern']) && !empty($row['pattern']) && !is_array($row['pattern']))
			{
				$row['pattern'] = json_decode($row['pattern'], true);
			}

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

		public function execute($_storage): Route
		{
			$relations = array();

			foreach($this->system_routes AS $key => $route)
			{
				if(!in_array($key, $this->instance::$map[Request::METHOD_GET])) // Post variable
				{
					/**
					 *	If parent has changed it's pattern, just replace it here
					 */
					if(!isset($route['parent']) || !isset($this->system_routes[$route['parent']]['pattern'])) // Disabled because parent is disabled
					{
						//$this->system_routes[$key]['disabled'] = true;
						//unset($this->system_routes[$key]);
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

					// Is this route disabled? (From LCMS)
					/*if(isset($this->system_routes[$key]['settings'], $this->system_routes[$key]['settings'][Locale::getLanguage()], $this->system_routes[$key]['settings'][Locale::getLanguage()]['disabled']) && $this->system_routes[$key]['settings'][Locale::getLanguage()]['disabled']['value'])
					{
						// Remove this route from the mapping too
						foreach(array_filter($this->instance::$map, fn($keys) => in_array($key, $keys)) AS $map => $keys)
						{
							unset($this->instance::$map[$map][array_search($key, $keys)]);
						}

						$this->system_routes[$key]['disabled'] = true;
						
						//unset($this->system_routes[$key]);
					}
					else
					{*/
						$relations[$this->system_routes[$key]['id']] = $key;
					//}
				}
			}

			if(empty($relations))
			{
				return $this->instance;
			}

			if(empty($this->database_routes))
			{
				return $this->instance->merge($this->system_routes);
			}
			
			foreach($this->database_routes AS $k => $route)
			{
				$key = count($this->system_routes);
				$relations[$route['id']] = $key;

				$this->system_routes[] = $route + array('key' => $key);

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

			return $this->instance->merge($this->system_routes);
		}

		private function storeToDatabase(DB $db, $_settings): Self
		{
			$settings = array(Locale::getLanguage() => array());

			foreach($_settings AS $key => $value)
			{
				$settings[Locale::getLanguage()][$key] = array(
					'value' => $value,
					'type' => match(gettype($value))
					{
						"boolean" 	=> Node::TYPE_BOOLEAN,
						"array" 	=> Node::TYPE_ARRAY,
						default 	=> Node::TYPE_TEXT
					}
				);
			}

			$db::query("UPDATE ".Env::get("db")['database'].".`lcms_routes` SET `settings` = JSON_MERGE_PATCH(`settings`, ?) WHERE `id`=?", [ $settings, $this->instance::$current['id'] ]);
		
			return $this;
		}

		public function store($_what, $_into = null): Self
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

		protected function prepareFromDatabase(DB $db): Self
		{
			$this->system_navs = $this->instance->getAll();

			// Only select those menu items which has enabled routes
			$query = $db::query("SELECT `mi`.*, `r`.`alias` AS `route`, `r`.`settings` AS `route_settings`, `r`.`parent_id` AS `route_parent_id`
									FROM ".Env::get("db")['database'].".`lcms_navigations` AS `mi` 
										LEFT JOIN ".Env::get("db")['database'].".`lcms_routes` AS `r` ON(`r`.`id` = `mi`.`route_id`) 
											WHERE `mi`.`deleted_at` IS NULL ORDER BY `mi`.`parent_id` ASC, `mi`.`id` ASC");

			if($db::num_rows($query) == 0)
			{
				return $this;
			}

			while($row = $db::fetch_assoc($query))
			{
				if(!empty($row['hidden_at']) && ($hidden_at = json_decode($row['hidden_at'], true)) && isset($hidden_at[Locale::getLanguage()]) && !empty($hidden_at[Locale::getLanguage()]))
				{
					continue;
				}

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
				if(empty($lcms_nav_item['snapshot']) || $lcms_nav_item['snapshot'] != $snapshot)
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

		private function createNavItem(DB $db, $_nav_identifier, $_nav_item, $_parent_id = null)
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

			return $_nav_item;
		}

		/*private function updateNavItem(DB $db, $nav_item_id, $params): Void
		{
			DB::update(Env::get("db")['database'].".`lcms_navigations`", $params, array('id' => $nav_item_id));
		}*/

		/**
		 *	Merge everything together
		 */
		protected function execute($_storage): Navigations
		{
			if(empty($this->system_navs))
			{
				return $this->instance;
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
						$system_items[$navigation][$nav_item_key] = array_merge($system_items[$navigation][$nav_item_key], $this->createNavItem($_storage, $navigation, $nav_item));
					}

					$route_settings = (!empty($system_items[$navigation][$nav_item_key]['route_settings'])) ? json_decode($system_items[$navigation][$nav_item_key]['route_settings'], true) : null;
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
				foreach(array_filter($system_items[$navigation], fn($i) => isset($i['parent'])) AS $nav_item_key => $nav_object)
				{
					$system_items[$navigation][$nav_item_key]['parent_id'] = $system_items[$navigation][$nav_object['parent']]['id'];
					
					//$this->updateNavItem($_storage, $nav_object['id'], array('parent_id' => $system_items[$navigation][$nav_item_key]['parent_id']));
				}
			}

			if(empty($relations))
			{
				return $this->instance;
			}

			if(empty($this->database_navs))
			{
				return $this->instance->merge($system_items);
			}

			/**
			 *	Items found in Database, add them too (+ pair with children)
			 */
			foreach($this->database_navs AS $nav_identifier => $nav_items)
			{
				foreach($nav_items AS $key => $nav_item)
				{
					$re_key = count($system_items[$nav_identifier]);
					$nav_item['key'] = $re_key;

					if(!empty($nav_item['parent_id']))
					{
						// Find parent
						$find_key = $relations[$nav_identifier][$nav_item['parent_id']] ?? $nav_items[$nav_item['parent_id']]['key'];
		
						if(!isset($system_items[$nav_identifier][$find_key]['children']))
						{
							$system_items[$nav_identifier][$find_key]['children'] = array();
						}

						$system_items[$nav_identifier][$find_key]['children'][] = $re_key;
					}

					$system_items[$nav_identifier][$re_key] = $nav_item; //[$key];
				}
			}

			return $this->instance->merge($system_items);
		}

		private function buildNavItem($row)
		{
			if(isset($row['title']) && !empty($row['title']) && !is_array($row['title']))
			{
				$row['title'] = json_decode($row['title'], true)[Locale::getLanguage()] ?? "";
			}

			if(isset($row['parameters']) && !empty($row['parameters']) && !is_array($row['parameters']))
			{
				$row['parameters'] = json_decode($row['parameters'], true);
			}			

			if(isset($row['snapshot']) && !empty($row['snapshot']) && !is_array($row['snapshot']))
			{
				$row['snapshot'] = json_decode($row['snapshot'], true);
			}

			if(empty($row['route_id']) && isset($row['snapshot'], $row['snapshot']['route']) && !empty($row['snapshot']['route']))
			{
				$row['route'] = $row['snapshot']['route'];
			}

			$row['order'] = (int) $row['order'];

			return $row;
		}
	}

	/*class NavMerge extends BaseMerge
	{
		private $navigation;
		private $database_items = array();
		private $system_items = array();

		protected function prepareFromDatabase(DB $db): Self
		{
			$data = $this->instance->asArray();

			$this->navigation = $data['id'];
			$this->system_items = $data['items'];

			unset($data);

			$query = $db::query("SELECT `mi`.*, `r`.`alias` AS `route` FROM ".Env::get("db")['database'].".`lcms_navigations` AS `mi` JOIN ".Env::get("db")['database'].".`lcms_routes` AS `r` ON(`r`.`id` = `mi`.`route_id`) WHERE `mi`.`deleted_at` IS NULL AND `nav`='".$this->navigation."' ORDER BY `mi`.`parent_id` ASC, `mi`.`id` ASC");

			if($db::num_rows($query) == 0)
			{
				return $this;
			}

			while($row = $db::fetch_assoc($query))
			{
				$this->database_items[$row['id']] = $this->buildNavItem($row);
			}

			return $this;
		}

		private function pair($system_nav_item)
		{
			$snapshot = array('title' => $system_nav_item['title'], 'route' => $system_nav_item['route']);

			foreach($this->database_items AS $k => $lcms_nav_item)
			{
				if(empty($lcms_nav_item['snapshot']) || $lcms_nav_item['snapshot'] != $snapshot)
				{
					continue;
				}

				unset($this->database_items[$k]); // Remove this entry from LCMS

				return array_merge($system_nav_item, $lcms_nav_item);
			}

			return $system_nav_item;
		}

		private function createNavItem(DB $db, $_nav_item)
		{
			pre($_nav_item); die("nav item");
			$item = array(
				'navigation' 	=> $this->navigation,
				'parent_id'		=> (isset($_nav_item['parent'])) ? $this->system_items[$_nav_item['parent']]['id'] : null,
				'title'			=> array(Locale::getLanguage() => $_nav_item['title']),
				'route_id'		=> Route::asItem($_nav_item['route'])['id'] ?? null,
				'parameters'	=> (isset($_nav_item['parameters']) && !empty($_nav_item['parameters'])) ? $_nav_item['parameters'] : null,
				'snapshot'		=> array('title' => $_nav_item['title'], 'route' => $_nav_item['route']),
				'order'			=> $_nav_item['order'] ?? 99
			);

			$db::insert(Env::get("db")['database'].".`lcms_navigations`", $item);

			$item['id'] = DB::last_insert_id();

			return $item;
		}

		protected function execute($_storage): Navigation
		{	
			if(empty($this->system_items))
			{
				return $this->instance;
			}

			$relations = array();

			foreach($this->system_items AS $navigation => $nav_item)
			{
				if(!empty($this->database_items))
				{
					$this->system_items[$navigation] = $this->pair($nav_item);
				}

				if(!isset($this->system_items[$navigation]['id']))
				{
					$this->system_items[$navigation] = array_merge($this->system_items[$navigation], $this->createNavItem($_storage, $navigation, $nav_item));
				}

				$relations[$this->system_items[$navigation]['id']] = $navigation;
			}

			if(empty($relations))
			{
				return $this->instance;
			}

			if(empty($this->database_items))
			{
				return $this->instance->merge($this->system_items);
			}

			foreach($this->database_items AS $k => $nav_item)
			{
				$key = count($this->system_items);
				$relations[$nav_item['id']] = $key;

				$this->system_items[] = array_merge($nav_item, array('key' => $key));

				if(!empty($nav_item['parent_id']))
				{
					if(!isset($this->system_items[$relations[$nav_item['parent_id']]]['children']))
					{
						$this->system_items[$relations[$nav_item['parent_id']]]['children'] = array();
					}

					$this->system_items[$key]['parent'] = $relations[$nav_item['parent_id']];
					$this->system_items[$relations[$nav_item['parent_id']]]['children'][] = $key;
				}

				unset($this->database_items[$k]);
			}

			return $this->instance->merge($this->system_items);
		}

		private function buildNavItem($row)
		{
			if(isset($row['title']) && !empty($row['title']) && !is_array($row['title']))
			{
				$row['title'] = json_decode($row['title'], true)[Locale::getLanguage()] ?? null;
			}

			if(isset($row['snapshot']) && !empty($row['snapshot']) && !is_array($row['snapshot']))
			{
				$row['snapshot'] = json_decode($row['snapshot'], true);
			}

			return $row;
		}
	}*/

	class EnvMerge extends BaseMerge
	{
		private $excluded_keys = ['is_dev', 'db'];
		private $items;

		protected function prepareFromFile($_file): Self
		{
			$this->items = require($_file);

			return $this;
		}

		protected function prepareFromArray($_array): Self
		{
			$this->items = (empty($this->items)) ? $_array : array_merge($this->items, $_array);

			return $this;
		}

		protected function prepareFromDatabase(DB $db): Self
		{
			$query = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_settings` WHERE `key` NOT IN('".implode("', '", $this->excluded_keys)."')");

			if($db::num_rows($query) == 0)
			{
				return $this;
			}

			// $this->items = array();

			while($row = $db::fetch_assoc($query))
			{
				$this->items[$row['key']] = (empty($row['value'])) ? null : ((\LCMS\Utils\Toolset::isJson($row['value'])) ? json_decode($row['value'], true) : $row['value']);
			}

			return $this;
		}

		protected function execute($_storage): Env
		{
			if(empty($this->items))
			{
				return $this->instance;
			}

			return $this->instance->merge($this->items);
		}
	}
?>