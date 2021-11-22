<?php
	/** 
	 *
	 */
	namespace LCMS\Api;

	use LCMS\Core\Env;
	use LCMS\Core\Database as DB;
	use LCMS\Core\Route;
	use LCMS\Core\Menus;
	use LCMS\Core\Menu;
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
			elseif($this->object instanceof Menus)
			{
				$this->merger = new MenusMerger($this->object);
			}
			/*elseif($this->object instanceof Menu)
			{
				$this->merger = new MenuMerge($this->object);
			}*/
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

		function __construct($_instance)
		{
			$this->instance = $_instance;		
		}

		protected function prepare($_storage)
		{
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

		protected function prepareFromDatabase(DB $db)
		{}

		protected function prepareFromArray($_array)
		{}		

		protected function prepareFromFile($_file)
		{
			throw new Exception(get_class($this) . " cant prepare from File");
		}

		protected function store($_what, $_into = null)
		{
			throw new Exception(get_class($this) . " does not support storage");
		}

		public function merge($_storage)
		{
			return $this->prepare($_storage)->execute($_storage);
		}

		protected function execute($_storage)
		{
			return $this->instance;
		}	
	}

	class NodeMerge extends BaseMerge
	{
		public $nodes = array();
		private $storage;
		
		protected function prepareFromFile($_file)
		{
			$this->prepareFromIni($_file);

			return $this;
		}

		protected function prepareFromDatabase(DB $db)
		{
			$condition = ($this->instance::$namespace != null && $this->instance::$namespace[1] != null) ? " OR `route_id`=".$this->instance::$namespace[1] : null;

			$query = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_nodes` WHERE (`route_id` IS NULL " . $condition . ") AND `deleted_at` IS NULL AND `hidden_at` IS NULL ORDER BY `order` ASC, `id` ASC");

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
				$value = $node['content'][Locale::getLanguage()] ?? "";

				if(empty($node['route_id']))
				{
					$identifier = array("global" => array($identifier => $value));
					//$identifier = "global." . $identifier;
				}
				elseif($this->instance::$namespace != null)
				{
					$identifier = array($this->instance::$namespace[1] => array($identifier => $value));
					//$identifier = $this->instance::$namespace[1] . "." . $identifier;
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
					$loops_relations[$node['id']] = $identifier;
				}
				else
				{
					$this->nodes = array_replace_recursive($this->nodes, $identifier);
				}
			}

			/**
			 *	Pair the loops with the nodes
			 */
			if(!empty($loops_relations))
			{
				foreach($loops_relations AS $node_id => $identifier)
				{
					if(!isset($loops[$node_id]))
					{
						$this->nodes[$identifier] = array();
						continue;
					}

					foreach($loops[$node_id] AS $key => $value)
					{
						foreach($value AS $k => $v)
						{
							$this->nodes[$identifier . "." . $key . "." . $k] = $v;
						}
					}
				}
			}

			return $this;
		}

		private function getLoop(DB $db, $loop_identifier)
		{
			// Fetch existing loops (Extend if any exists)
			$condition = ($this->instance::$namespace != null) ? " OR `route_id`=".$this->instance::$namespace[0] : null;

			$query = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_nodes` WHERE `identifier`='".$loop_identifier."' AND (`route_id` IS NULL " . $condition . ") AND `type`=".Node::TYPE_LOOP." AND `deleted_at` IS NULL AND `hidden_at` IS NULL");

			if($db::num_rows($query) == 0)
			{
				return array();
			}

			return $db::fetch_assoc($query);
		}		

		public function store($_what, $_into = null)
		{
			if($_into instanceof DB)
			{
				return $this->storeToDatabase($_into, $_what);
			}
			elseif($this->storage != null)
			{
				return $this->storeToIni($_what);
			}
			
			throw new Exception("Unexpected storage: " . ($_into ?? "No storage-file defined"));
		}

		private function storeToDatabase(DB $db, $_nodes) : bool
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
							'route_id' 		=> ((!isset($n['global']) || !$n['global']) && $this->instance::$namespace != null) ? $this->instance::$namespace[0] : null,
							'identifier'	=> $key,
							'type'			=> Node::TYPE_LOOP,
							'parameters'	=> $node // Snapshot of all nodes to be used here
						));
					}
					else
					{
						// Loop exists, update the params
						$params = json_decode($loop_array['parameters'], true);
						$new_params = (empty($params)) ? array() : $params;

						if(empty($new_params))
						{
							$new_params = $node;
						}
						else
						{
							foreach($node AS $k => $v)
							{
								if(isset($params[$k]))
								{
									continue;
								}

								$new_params += array($k => $v);
							}
						}

						if(count($new_params) != count($params))
						{
							$db::update(Env::get("db")['database'].".`lcms_nodes`", array('parameters' => $new_params), array('id' => $loop_array['id']));
						}
					}
				}
				elseif(str_starts_with($node['identifier'], "meta."))
				{
					// Meta-data
					$db::query("UPDATE ".Env::get("db")['database'].".`lcms_routes` SET `meta` = JSON_MERGE_PATCH(`meta`, ?) WHERE `id`=?", [ array(Locale::getLanguage() => [explode(".", $node['identifier'], 2)[1] => $node['content']]), $this->instance::$namespace[1] ]);
				}
				else
				{
					// Default node
					$db::insert(Env::get("db")['database'].".`lcms_nodes`", array(
						'route_id' 		=> ((!isset($node['global']) || !$node['global']) && $this->instance::$namespace != null) ? $this->instance::$namespace[1] : null,
						'identifier'	=> $node['identifier'],
						'type'			=> $node['type'],
						'parameters'	=> $node['parameters'] ?? null,
						'properties'	=> (isset($node['properties'])) ? array(Locale::getLanguage() => $node['properties']) : null,
						'content'		=> array(Locale::getLanguage() => $node['content'])
					));
				}
			}
			
			return true;
		}

		private function storeToIni($_nodes) : void
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
					die("damn");
					continue;
				}

				$alias = (isset($node['global']) && $node['global']) ? "global" : $this->instance::$namespace[0];

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

					$existing_file_array[$alias][$node['identifier']] = $node['content'];

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

		private function prepareFromIni($_file)
		{
			$file_content = parse_ini_file($_file, true);

			// Unflatten the file-content
			foreach($file_content AS $key => $value)
			{
				foreach($value AS $k => $v)
				{
					Arr::unflatten($this->nodes[$key], $k, $v);
				}
			}

			$this->storage = array('filename' => $_file, 'content' => $file_content);
		}

		protected function prepareFromArray($_array)
		{
			$flattened_array = Arr::flatten($_array);

			foreach($flattened_array AS $identifier => $node)
			{
				self::$nodes[$identifier] = array('content' => $node);
			}
		}		

		/**
		 *	Construct the new routes as the format they came as
		 */
		public function execute($_storage) : Node
		{
			if(empty($this->nodes))
			{
				return $this->instance;
			}

			// Unflatten the file-content
			return $this->instance->merge($this->nodes);
		}

		private function array_to_ini(array $array) : string
		{
			return array_reduce(array_keys($array), function($str, $sectionName) use ($array) 
			{
				$sub = $array[$sectionName];

				return $str . "[$sectionName]" . PHP_EOL .
					array_reduce(array_keys($sub), function($str, $key) use($sub) 
					{
						return $str . $key . ' = "' . $sub[$key] . '"' . PHP_EOL;
					}) . PHP_EOL;
			});
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
		protected function prepareFromDatabase(DB $db)
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
				'alias'			=> (isset($_route['alias']) && !empty($_route['alias'])) ? strtolower($_route['alias']) : null,
				'pattern'		=> array($locale => strtolower($_route['pattern'])),
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

			$row['pattern'] = $row['pattern'][Locale::getLanguage()] ?? $row['pattern'][array_key_first($row['pattern'])];	

			if(!empty($row['parent_id']))
			{
				$row['pattern'] = $this->database_routes[$row['parent_id']]['pattern'] . "/" . $row['pattern'];
			}

			return $row;
		}

		public function execute($_storage) : Route
		{
			$relations = array();

			foreach($this->system_routes AS $key => $route)
			{
				if(!in_array($key, $this->instance::$map[Request::METHOD_GET])) // Post variable
				{
					/**
					 *	If parent has changed it's pattern, just replace it here
					 */
					if(substr($route['pattern'], 0, strlen($this->system_routes[$route['parent']]['pattern'])) !== $this->system_routes[$route['parent']]['pattern'])
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

				$this->system_routes[] = array_merge($route, array('key' => $key));

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

		private function storeToDatabase(DB $db, $_settings)
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
		}

		public function store($_what, $_into = null)
		{
			if(!$_into instanceof DB)
			{
				throw new Exception("Cant merge Page Settings w/o DB instance");
			}

			return $this->storeToDatabase($_what);
		}
	}

	class MenusMerger extends BaseMerge
	{
		private $database_menus = array();
		private $system_menus = array();

		protected function prepareFromDatabase(DB $db)
		{
			$this->system_menus = $this->instance->getAll();

			$query = $db::query("SELECT `mi`.*, `r`.`alias` AS `route` FROM ".Env::get("db")['database'].".`lcms_menus` AS `mi` JOIN ".Env::get("db")['database'].".`lcms_routes` AS `r` ON(`r`.`id` = `mi`.`route_id`) WHERE `mi`.`deleted_at` IS NULL ORDER BY `mi`.`parent_id` ASC, `mi`.`id` ASC");

			if($db::num_rows($query) == 0)
			{
				return $this;
			}

			while($row = $db::fetch_assoc($query))
			{
				if(!isset($this->database_menus[$row['menu']]))
				{
					$this->database_menus[$row['menu']] = array();
				}

				if(!empty($row['route_id']))
				{
					$this->database_menus[$row['menu']][$row['id']] = $this->buildMenuItem($row);
				}
			}

			return $this;
		}

		private function pair($menu_identifier, $system_menu_item)
		{
			$snapshot = array('title' => $system_menu_item['title'], 'route' => $system_menu_item['route']);

			foreach($this->database_menus[$menu_identifier] AS $k => $lcms_menu_item)
			{
				if(empty($lcms_menu_item['snapshot']) || $lcms_menu_item['snapshot'] != $snapshot)
				{
					continue;
				}

				unset($this->database_menus[$menu_identifier][$k]); // Remove this entry from LCMS

				if(empty($this->database_menus[$menu_identifier]))
				{
					unset($this->database_menus[$menu_identifier]);
				}

				$item = array_merge($system_menu_item, $lcms_menu_item);	

				// Any children?
				/*if(!empty($item['parent_id']))
				{
					pre($item);

					pre($this->system_menus[$menu_identifier]->item($item['key']));

					die("PARENT");
				}*/

				return $item;
			}

			return $system_menu_item;
		}

		private function prepareMenuItem(DB $db, $_menu_item)
		{
			$route = Route::asItem($_menu_item['route']);

			if(!isset($route['id']))
			{
				throw new Exception("Cant create menu item without database connection to a route");
			}

			return array(
				//'parent_id'		=> $_menu_item['parent_id'] ?? null, //(isset($_menu_item['parent'])) ? $this->system_items[$_menu_item['parent']]['id'] : null,
				'title'			=> array(Locale::getLanguage() => $_menu_item['title']),
				'route_id'		=> $route['id'],
				'parameters'	=> (isset($_menu_item['parameters']) && !empty($_menu_item['parameters'])) ? $_menu_item['parameters'] : null,
				'snapshot'		=> array('title' => $_menu_item['title'], 'route' => $_menu_item['route']),
				'order'			=> $_menu_item['order'] ?? 99
			);

			pre($item);

			

			$item['id'] = $db::last_insert_id();

			return $item;
		}

		private function createMenuItem(DB $db, $_menu_identifier, $_menu_item, $_parent_id = null)
		{
			$db::insert(Env::get("db")['database'].".`lcms_menus`", array(
				'menu' 			=> $_menu_identifier,
				'parent_id'		=> $_menu_item['parent_id'] ?? null,
				'title'			=> array(Locale::getLanguage() => $_menu_item['title']),
				'route_id'		=> Route::asItem($_menu_item['route'])['id'] ?? null,
				'parameters'	=> (isset($_menu_item['parameters']) && !empty($_menu_item['parameters'])) ? $_menu_item['parameters'] : null,
				'snapshot'		=> array('title' => $_menu_item['title'], 'route' => $_menu_item['route']),
				'order'			=> $_menu_item['order'] ?? 99
			));

			$_menu_item['id'] = $db::last_insert_id();

			return $_menu_item;
		}

		private function createMenu(DB $db, $_menu_object)
		{
			$items = array();

			if(!$_menu_object->valid() && empty($this->database_menus[$_menu_object->asArray()['identifier']]))
			{
				DB::insert(Env::get("db")['database'].".`lcms_menus`", array('menu' => $_menu_object->asArray()['identifier']));

				return $_menu_object;
			}

			foreach($_menu_object AS $key => $menu_item)
			{
				if(!empty($_parent_id))
				{
					$menu_item['parent_id'] = $_parent_id;
				}

				$items[$key] = $this->prepareMenuItem($menu_item);

				if(isset($menu_item['children']))
				{
					foreach($menu_item['children'] AS $child_key)
					{
						if(!isset($items[$key]['children']))
						{
							$items[$key]['children'] = array();
						}

						$items[$key]['children'][$child_key] = $this->prepareMenuItem($_menu_object->item($child_key));
					}
				}
			}

			// Without any errors, let's create all items
			$database_items = array();

			foreach($items AS $key => $menu_item)
			{
				$menu_item = array_merge($menu_item, $this->createMenuItem($db, $_menu_object->asArray()['identifier'], $menu_item));
				
				$database_items[$menu_item['id']] = $menu_item;

				if(isset($menu_item['children']))
				{
					foreach($menu_item['children'] AS $child_key => $child)
					{
						if(!isset($database_items['children']))
						{
							$database_items['children'] = array();
						}

						$child = array_merge($child, $this->createMenuItem($db, $_menu_object->asArray()['identifier'], $child, $menu_item['id']));	

						$database_items['children'][] = $child['id'];
					}
				}
			}

			return $_menu_object->merge($database_items);
		}

		/**
		 *	Merge everything together
		 */
		protected function execute($_storage): Menus
		{
			if(empty($this->system_menus))
			{
				return $this->instance;
			}

			$relations = $system_items = array();

			foreach($this->system_menus AS $key => $menu_object)
			{
				$system_items[$key] = $menu_object->items();

				foreach($menu_object->items() AS $menu_item_key => $menu_item)
				{
					if(isset($this->database_menus[$key]) && !empty($this->database_menus[$key]))
					{
						$system_items[$key][$menu_item_key] = $this->pair($key, $menu_item);
					}

					if(!isset($system_items[$key][$menu_item_key]['id']))
					{
						$system_items[$key][$menu_item_key] = array_merge($system_items[$key][$menu_item_key], $this->createMenuItem($key, $menu_item));
					}

					$relations[$key][$system_items[$key][$menu_item_key]['id']] = $menu_item_key;
				}
			}

			if(empty($relations))
			{
				return $this->instance;
			}

			if(empty($this->database_menus))
			{
				return $this->instance->merge($system_items);
			}

			/**
			 *	Items found in Database, add them too (+ pair with children)
			 */
			foreach($this->database_menus AS $menu_identifier => $menu_items)
			{
				foreach($menu_items AS $key => $menu_item)
				{
					$re_key = count($system_items[$menu_identifier]);

					$menu_items[$key]['key'] = $re_key;

					if(!empty($menu_items[$key]['parent_id']))
					{
						$find_key = (isset($relations[$menu_identifier][$menu_items[$key]['parent_id']])) ? $relations[$menu_identifier][$menu_items[$key]['parent_id']] : $menu_items[$menu_items[$key]['parent_id']]['key'];
		
						if(!isset($system_items[$menu_identifier][$find_key]['children']))
						{
							$system_items[$menu_identifier][$find_key]['children'] = array();
						}

						$system_items[$menu_identifier][$find_key]['children'][] = $re_key;
					}

					$system_items[$menu_identifier][$re_key] = $menu_items[$key];
				}
			}

			return $this->instance->merge($system_items);
		}

		private function buildMenuItem($row)
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
	}

	class MenuMerge extends BaseMerge
	{
		private $menu;
		private $database_items = array();
		private $system_items = array();

		protected function prepareFromDatabase(DB $db)
		{
			$data = $this->instance->asArray();

			$this->menu = $data['id'];
			$this->system_items = $data['items'];

			unset($data);

			$query = $db::query("SELECT `mi`.*, `r`.`alias` AS `route` FROM ".Env::get("db")['database'].".`lcms_menus` AS `mi` JOIN ".Env::get("db")['database'].".`lcms_routes` AS `r` ON(`r`.`id` = `mi`.`route_id`) WHERE `mi`.`deleted_at` IS NULL AND `menu`='".$this->menu."' ORDER BY `mi`.`parent_id` ASC, `mi`.`id` ASC");

			if($db::num_rows($query) == 0)
			{
				return $this;
			}

			while($row = $db::fetch_assoc($query))
			{
				$this->database_items[$row['id']] = $this->buildMenuItem($row);
			}

			return $this;
		}

		private function pair($system_menu_item)
		{
			$snapshot = array('title' => $system_menu_item['title'], 'route' => $system_menu_item['route']);

			foreach($this->database_items AS $k => $lcms_menu_item)
			{
				if(empty($lcms_menu_item['snapshot']) || $lcms_menu_item['snapshot'] != $snapshot)
				{
					continue;
				}

				unset($this->database_items[$k]); // Remove this entry from LCMS

				return array_merge($system_menu_item, $lcms_menu_item);
			}

			return $system_menu_item;
		}

		private function createMenuItem(DB $db, $_menu_item)
		{
			$item = array(
				'menu' 			=> $this->menu,
				'parent_id'		=> (isset($_menu_item['parent'])) ? $this->system_items[$_menu_item['parent']]['id'] : null,
				'title'			=> array(Locale::getLanguage() => $_menu_item['title']),
				'route_id'		=> Route::asItem($_menu_item['route'])['id'] ?? null,
				'parameters'	=> (isset($_menu_item['parameters']) && !empty($_menu_item['parameters'])) ? $_menu_item['parameters'] : null,
				'snapshot'		=> array('title' => $_menu_item['title'], 'route' => $_menu_item['route']),
				'order'			=> $_menu_item['order'] ?? 99
			);

			$db::insert(Env::get("db")['database'].".`lcms_menus`", $item);

			$item['id'] = DB::last_insert_id();

			return $item;
		}

		protected function execute($_storage) : Menu
		{	
			if(empty($this->system_items))
			{
				return $this->instance;
			}

			$relations = array();

			foreach($this->system_items AS $key => $menu_item)
			{
				if(!empty($this->database_items))
				{
					$this->system_items[$key] = $this->pair($menu_item);
				}

				if(!isset($this->system_items[$key]['id']))
				{
					$this->system_items[$key] = array_merge($this->system_items[$key], $this->createMenuItem($menu_item));
				}

				$relations[$this->system_items[$key]['id']] = $key;
			}

			if(empty($relations))
			{
				return $this->instance;
			}

			if(empty($this->database_items))
			{
				return $this->instance->merge($this->system_items);
			}

			foreach($this->database_items AS $k => $menu_item)
			{
				$key = count($this->system_items);
				$relations[$menu_item['id']] = $key;

				$this->system_items[] = array_merge($menu_item, array('key' => $key));

				if(!empty($menu_item['parent_id']))
				{
					if(!isset($this->system_items[$relations[$menu_item['parent_id']]]['children']))
					{
						$this->system_items[$relations[$menu_item['parent_id']]]['children'] = array();
					}

					$this->system_items[$key]['parent'] = $relations[$menu_item['parent_id']];
					$this->system_items[$relations[$menu_item['parent_id']]]['children'][] = $key;
				}

				unset($this->database_items[$k]);
			}

			return $this->instance->merge($this->system_items);
		}

		private function buildMenuItem($row)
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
	}

	class EnvMerge extends BaseMerge
	{
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
			$query = $db::query("SELECT * FROM ".Env::get("db")['database'].".`lcms_settings`");

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