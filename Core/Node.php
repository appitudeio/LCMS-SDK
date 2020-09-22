<?php
	/**
	 *	Node ("Sections") editable through Admin GUI
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2018-10-16
	 */
	namespace LCMS\Core;

	use LCMS\Core\Route;
	use LCMS\Core\Locale;
	use LCMS\Core\Database AS DB;
	use \Exception;
	
	class Node
	{
		public static $image_endpoint = "https://static.logicalcms.com/";

		public const TYPE_TEXT 		= 1;
		public const TYPE_HTML 		= 2;
		public const TYPE_TEXTAREA 	= 3;
		public const TYPE_BOOL 		= 4;
		public const TYPE_BOOLEAN 	= 4;
		public const TYPE_IMAGE 	= 10;
		public const TYPE_FILE 		= 15;
		public const TYPE_ROUTE 	= 20;
		public const TYPE_LOOP		= 30;

		public static $namespace;
		public static $type_properties = array(
			Node::TYPE_IMAGE => array(
				'title' => null, 'alt' => null, 'width' => null, 'height' => null
			),
			Node::TYPE_ROUTE => array(
				//'route' => null
			)
		);
		private static $parameters;
		private static $nodes;
		private static $instance;
		private static $ini_file;

		public static function init($_image_endpoint)
		{
			self::$image_endpoint .= $_image_endpoint;
		}

		public static function getInstance($_data = null)
		{
			if(self::$instance == null)
			{
				self::$instance = new static();
			}

			if($_data instanceof Route)
			{
				self::$instance->route = $_data;
			}

			return self::$instance;
		}

		public static function setNamespace($_route_id, $_namespace)
		{
			self::$namespace = array($_route_id, $_namespace); // namespace == route_alias
		}

		public function create($_params)
		{
			DB::insert(Env::get("db")['database'].".`lcms_nodes`", $_params);

			return DB::last_insert_id();
		}


		public function update($_node_id, $_params)
		{
			DB::update(WEB_DATABASE.".`core_nodes`", $_params, array('id' => $_node_id));

			return true;
		}

		/**
		 *	Actually loads all nodes, based on preparations
		 */
		/*public function load()
		{
			if(empty($this->nodes))
			{

			}
			else
			{

			}
			if(empty($this->nodes))
			{
				return true;
			}

			$aliases = array();

			foreach($this->nodes AS $n)
			{
				if(empty($n['alias']))
				{
					continue;
				}

				$aliases[] = $n['alias'];
			}

			$conditions = "";

			if(!empty($aliases))
			{
				$aliases = array_unique($aliases);

				$conditions .= " AND (`alias` IN('".implode("', '", $aliases)."') OR `alias` IS NULL)";
			}

			$query = DB::query("SELECT * FROM ".WEB_DATABASE.".`core_nodes` WHERE `identifier` IN('".implode("', '", array_keys($this->nodes))."') ".$conditions." AND `language_id`=".$this->language_id." ORDER BY `alias` ASC");

			if(DB::num_rows($query) > 0)
			{
				while($row = DB::fetch_assoc($query))
				{
					$this->nodes[$row['identifier']]['id'] 		= $row['id'];
					$this->nodes[$row['identifier']]['content'] = $row['content'];
					$this->nodes[$row['identifier']]['data'] 	= (!empty($row['data'])) ? json_decode($row['data'], true) : null;

					if(!empty($this->global_data_params))
					{
						foreach($this->global_data_params AS $key => $value)
						{
							$this->nodes[$row['identifier']]['content'] = str_replace('{'.$key.'}', $value, $this->nodes[$row['identifier']]['content']);
						}
					}

					// Inline-route used?
					$parsed_routes = $this->get_string_between($this->nodes[$row['identifier']]['content'], "{route:", "}");

					if(!empty($parsed_routes))
					{
						foreach($parsed_routes AS $route)
						{
							$this->nodes[$row['identifier']]['content'] = str_replace($route[0], Route::get($route[1]), $this->nodes[$row['identifier']]['content']);
						}
					}					
				}
			}

			// Find out which Nodes that didnt get an ID from the DB; create those
			foreach($this->nodes AS $identifier => $node)
			{
				if(isset($node['id']))
				{
					continue;
				}

				DB::insert(WEB_DATABASE.".`core_nodes`", $node);
			}

			return true;
		}

		/**
		 *
		 */
		public static function get($_identifier)
		{
			/**
			 *	Check local namespace, with global as fallback
			 */
			if(self::$namespace != null)
			{
				$node = self::array_get(self::$nodes, self::$namespace[1] . "." . $_identifier, false);

				if(is_bool($node) && !$node)
				{
					$node = self::array_get(self::$nodes, "global." . $_identifier, false);
				}
			}
			else
			{
				$node = self::array_get(self::$nodes, "global." . $_identifier, false);
			}

			if(is_bool($node) && !$node)
			{
				return false;
			}
			elseif(is_array($node) && empty($node))
			{
				// Loop
				return new NodeObject(array());
			}

			$node = (is_string($node)) ? array('content' => $node) : $node;

			$node['image_endpoint'] = self::$image_endpoint;
			$node['properties'] 	= (isset($node['properties'], $node['properties'][Locale::getLanguage()])) ? $node['properties'][Locale::getLanguage()] : null;

			if(empty($node['content']) || (!is_string($node['content']) && !isset($node['content'][Locale::getLanguage()])))
			{
				$node['content'] = "";
			}
			elseif(!is_string($node['content']))
			{
				$node['content'] = htmlspecialchars_decode($node['content'][Locale::getLanguage()]);
			}

			return new NodeObject($node);
		}

		public static function has($_identifier)
		{
			return self::exists($_identifier);
		}

		// Check Global namespace first, then as fallback the "local"
		public static function exists($_identifier)
		{
			// Check from namespaced node
			if(self::$namespace != null && !$node = self::array_get(self::$namespace[1] . "." . $_identifier))
			{
				return;
			}

			if(self::array_has(self::$nodes, $_identifier))
			{
				return true;
			}
			elseif(self::$namespace == null)
			{
				return false;
			}

			return self::array_has(self::$nodes, self::$namespace[1] . "." . $_identifier);
		}

		public static function array_has($array, $keys)
		{
			$keys = (array) $keys;

			if (! $array || $keys === []) 
			{
				return false;
			}

			foreach ($keys AS $key) 
			{
				$subKeyArray = $array;

				if (array_key_exists($key, $array)) 
				{
					continue;
				}

				foreach (explode('.', $key) AS $segment) 
				{
					if (is_array($subKeyArray) && array_key_exists($segment, $subKeyArray))
					{
						$subKeyArray = $subKeyArray[$segment];
					} 
					else 
					{
						return false;
					}
				}
			}

			return true;
		}

		private static function array_get($array, $key, $default = null)
		{
			if(!is_array($array))
			{
				return $default;
			}
			elseif(is_null($key)) 
			{
				return $array;
			}
			elseif(isset($array[$key])) 
			{
				return $array[$key];
			}
			elseif(strpos($key, '.') === false) 
			{
				return $array[$key] ?? $default;
			}

			foreach(explode('.', $key) AS $segment) 
			{
				if (is_array($array)) 
				{
					if(isset($array[$segment]))
					{
						$array = $array[$segment];
					}
					else
					{
						return false;
					}
				}
				else 
				{
					return $default;
				}
			}

			return $array;
		}		

		/**
		 *	Prepares all Nodes we want to load before we use them in the document or wherever
		 */
		/*public function prepare2($alias = null, $identifier, $node_type, $data = null)
		{
			self::validateType($node_type);

			$params = null;

			if(is_array($data))
			{
				if(isset($data['params']))
				{
					$params = $data['params'];
					unset($data['params']);
				}
			}

			$this->nodes[$identifier] = array(
				'alias'			=> (!empty($alias)) ? $alias : null,
				'type'			=> $node_type,
				'identifier'	=> $identifier,
				'data'			=> $data,
				'params'		=> $params,
				'content'		=> null,
				'language_id'	=> $this->language_id
			);
		}

		public function bulkPrepare($alias = null, $nodes)
		{
			foreach($nodes AS $identifier => $node_type)
			{
				self::validateType($node_type);

				$this->nodes[$identifier] = array(
					'alias'			=> (!empty($alias)) ? $alias : null,
					'type'			=> $node_type,
					'identifier'	=> $identifier,
					'content'		=> null,
					'language_id'	=> $this->language_id
				);
			}
		}

		private static function validateType($node_type)
		{
			if(!in_array($node_type, [self::TYPE_TEXT, self::TYPE_HTML, self::TYPE_TEXTAREA, self::TYPE_BOOL, self::TYPE_IMAGE, self::TYPE_FILE, self::TYPE_ROUTE]))
			{
				throw new Exception("Node type: " . $node_type . " not defined");
			}

			return true;
		}*/

		/**
		 *	Probably from Database, via Api\Merge
		 */
		public function merge($_nodes = null)
		{
			if(empty(self::$nodes) && !empty($_nodes))
			{
				self::$nodes = $_nodes;
			}
			elseif(!empty(self::$nodes) && !empty($_nodes))
			{
				self::$nodes = array_merge(self::$nodes, $_nodes);
			}

			/**
			 *	Merge data with strings
			 */
			if(empty(self::$parameters))
			{
				return $this;
			}

			foreach(self::$nodes AS $identifier => $node)
			{
				if(empty($node['content']) || !isset($node['content'][Locale::getLanguage()]))
				{
					continue;
				}

				foreach(self::$parameters AS $key => $value)
				{
					if(is_array($value))
					{
						continue;
					}

					self::$nodes[$identifier]['content'][Locale::getLanguage()] = str_replace('{{'.$key.'}}', $value, $node['content'][Locale::getLanguage()]);
				}
			}

			return $this;
		}

		public static function getParameter($_key)
		{
			return self::$parameters[$_key] ?? null;
		}

		public static function with($_key, $_string)
		{
			self::$parameters[$_key] = $_string;

			return self::getInstance();
		}
	}

	class NodeObject
	{
		private $node;

		function __construct($_node)
		{
			$this->node = $_node;
		}

		public function text($params = null)
		{
			// Any params we should replace 
			if(!empty($this->node['parameters']))
			{
				foreach($this->node['parameters'] AS $key => $value)
				{
					$this->node['content'] = str_replace('{{'.$key.'}}', $value, $this->node['content']);
				}
			}

			if(!empty($params))
			{
				foreach($params AS $key => $value)
				{
					$this->node['content'] = str_replace('{{'.$key.'}}', $value, $this->node['content']);
				}
			}

			return $this->node['content'];
		}

		public function image($_width = null, $_height = null)
		{
			// If empty image
			if(empty($this->node['content']))
			{
				return "";
			}

			$image_url = $this->node['image_endpoint'];

			if(!empty($_width) && !empty($_height))
			{
				$image_url .= $_width . "x" . $_height . "/";
			}
			elseif(!empty($_width))
			{
				$image_url .= $_width . "/";
			}

			$image_url .= $this->node['content'];

			$return_image = "<img src='" . $image_url . "' ";

			if(!empty($this->node['properties']))
			{
				foreach($this->node['properties'] AS $attribute => $value)
				{
					if(empty($value) || in_array($attribute, ['width', 'height']))
					{
						continue;
					}
					
					$return_image .= $attribute ."='".$value."' ";
				}
			}
			
			return $return_image . "/>";			
		}

		/**
		 *
		 */
		public function picture($width = null, $_height = null)
		{
			if(isWebp($this->node['content']))
			{
				return "<picture><source srcset='".$this->node['content']."' type='image/webp'>" . $this->image($_width, $_width) . "</picture>";
			}

			list($webp_image, $webp_type) = $this->to("webp", $this->node['content']);
			list($org_image, $org_type) = $this->to(null, $this->node['content']); 

			$picture = "<picture>";
				$picture .= "<source srcset='".$webp_image."' type='image/".$webp_type."' />";
				$picture .= "<source srcset='".$org_image."' type='image/".$org_type."' />";
				$picture .= $this->image($_width, $_height);

			return $picture . "</picture>";
		}

		public function route($params = null)
		{
			return (!empty($this->node['content']) && $this->node['content'] != "#") ? Route::url($this->node['content']) : "#";
		}

		public function loop()
		{
			return array_filter($this->node, function($k, $v)
			{
				return is_numeric($v);
			}, ARRAY_FILTER_USE_BOTH);
		}

		private function isWebp($_filename)
		{

		}

		private function to($_extension = null, $_basename)
		{

		}

		public function asArray()
		{
			return $this->node;
		}
	}


/*
	$section == "header" || array("header" => "hint text")


	Node::prepare("me", "hero.image"


	Node::set($alias);

	Node::prepare($node_group, $section, Node::TYPE_HTML, $params);

	Node::prepare("hero", array('header' => "Rubrik för ändamålet"), Node::TYPE_HTML, array('width' => 120, 'height' => 80));
	Node::prepare(['hero' => ['header' => "Rubrik för ändamålet"]], Node::TYPE_HTML, array('width' => 120, 'height' => 80));



		$LCMS->nodes->prepare("hero", "header", NODE_TYPE_HTML, array('global' => true));
		$LCMS->nodes->prepare("hero", array("header" => "Hint text"), NODE_TYPE_SELECT, array('values' => array(
			"one",
			"two"
		)));
		$LCMS->nodes->prepare("hero", "background", NODE_TYPE_IMAGE, array('width' => 500, 'height' => 300));


		echo $LCMS->nodes->get("hero", "header");
		echo $LCMS->nodes->get("hero", "background", array('default' => STATIC_PATH_IMAGES . "/default.jpg"));
		echo $LCMS->nodes->get("hero", "background", array('url' => true));



		class Nodes
		{
			private $page_id 			= null;
			private $initialized		= false;
			private $nodes 				= array("globals" = array(), "locals" => array());
			private $allowed_types 		= array(
				NODE_TYPE_TEXT,
				NODE_TYPE_HTML
			);
			private $global_data_params = array();
			private $s3_image_path		= STATIC_PATH_IMAGES;
			private $default_image_data	= array('alt' => '', 'title' => '');

			function __construct($_page_id)
			{
				$this->page_id = $_page_id;
			}

			/**
			 *
			 *
			public function setGlobalDataParam($key, $value)
			{
				$this->global_data_params[$key] = $value;
			}

			/**
			 *
			 *
			public function prepare($node_group_name, $section_identifier, $node_type, $params = null)
			{
				$hint 					= (is_array($section_identifier)) ? $section_identifier[1] : null;
				$section_identifier 	= (is_array($section_identifier)) ? $section_identifier[0] : $section_identifier;

				if(!in_array($node_type, $this->allowed_node_types))
				{
					throw new Exception("Not allowed node type (".$node_type.") for Node " . $section_identifier . " (" . $group_name.")");
				}

				$node_scope = (!empty($params) && isset($params['global'])) ? "globals" : "locals";

				// If this Group doesnt exist, create it
				if(!isset($this->nodes[$node_scope][$group_name]))
				{
					$this->nodes[$node_scope][$node_group_name] = array();
				}

				$this->nodes[$node_scope][$node_group_name][$section_identifier] = array(
					'type'	=> $node_type
				);

				if(!empty($hint))
				{
					$this->nodes[$node_scope][$node_group_name][$section_identifier]['hint'] = $hint;
				}

				// Only append $params if not only global
				if(!empty($params))
				{
					unset($params['global']);

					if(!empty($params))
					{
						$this->nodes[$node_scope][$node_group_name][$section_identifier]['params'] = $params;
					}
				}
			}

			/**
			 *
			 *
			public function load()
			{
				$this->initialized = true;

				if(empty($this->nodes))
				{
					return true;
				}

				if(DB::num_rows($query) > 0)
				{
					while($row = DB::fetch_assoc($query))
					{
						$node = array(
							'id'		=> $row['id'],
							'type'		=> $row['type'],
							'content'	=> htmlspecialchars_decode($row['content'])
						);

						if(!empty($row['params']))
						{
							$node['params'] = json_decode($row['params'], true);
						}

						$node_scope = (empty($row['page_id']) ? "globals" : "locals";

						$this->nodes[$node_scope][$row['group_name'][$row['identifier']] => $node);
					}
				}

				/** 
				 * Cool, we've attached the correct data to the already existing nodes.
				 *		- But; Should we create any new?
				 *
				 foreach($this->nodes AS $scope => $node_groups)
				 {
				 	foreach($node_groups AS $node_group => $nodes)
				 	{
				 		foreach($nodes AS $node_idenfitier => $node)
				 		{
				 			// If the ID-key exists, this Node has been loaded from the Database
				 			if(isset($node['id']))
				 			{
				 				continue;
				 			}

				 			$this->nodes[$scope][$node_group][$node_identifier] = $this->createNode($node);
				 		}
				 	}
				 }

				 return true;
			}

			/**
			 *
			 *
			private function createNode($_node)
			{
				// Make sure If Image, that it has "attributes"-data prepared
				if($_node['type'] == NODE_TYPE_IMAGE)
				{
					if(!isset($_node['params']))
					{
						$_node['params'] = array();
					}

					$_node['params']['data'] = $this->default_image_data;
				}

				$node = array(
					'page_id'		=> $this->page_id,
					'group'			=> $_node['group'],
					'identifier'	=> $_node['identifier'],
					'type'			=> $_node['type'],
					'hint'			=> (isset($_node['hint'])) ? $_node['hint'] : null,
					'content'		=> null,
					'params'		=> (isset($_node['params']) && is_array($_node['params'])) ? json_encode($_node['params']) : null
				);

				DB::insert(PROJECT_DATABASE.".core_nodes", $node);

				$node['id'] = DB::last_insert_id();

				return $node;
			}

			/**
			 *
			 *
			public function get($node_group_name, $section_identifier, $params = null)
			{
				/**
				 *	
				 *
				if(!$this->initialized)
				{
					throw new Exception("The Node has not been loaded through load()");
				}

				/**
				 *
				 *
				if((!isset($this->nodes['locals'][$node_group_name], $this->nodes['locals'][$node_group_name][$section_identifier]) || !isset(!isset($this->nodes['globals'][$node_group_name], $this->nodes['globals'][$node_group_name][$section_identifier]))
				{
					throw new Exception("Node " . $section_identifier . " (" . $node_group_name . ") not prepared");
				}

				$node = (empty($this->page_id)) ? $this->nodes['globals'][$node_group_name][$section_identifier] : $this->nodes['locals'][$node_group_name][$section_identifier];

				// No value
				if($node['hidden'] || empty($node['content']))
				{
					return (isset($params['default'])) ? $params['default'] : null;
				}

				// If a Route-type, return the URL
				if($node['type'] == NODE_TYPE_ROUTE)
				{
					return Route::get($node['content'], APP_PATH);
				}
				elseif($node['type'] == NODE_TYPE_IMAGE)
				{
					/**
					 *
					 *
					$image_url = $this->s3_image_path;

					if(isset($node['params']))
					{
						if(isset($node['params']['width'], $node['params']['height']) && is_numeric($node['params']['width']) && is_numeric($node['params']['height']))
						{
							$image_url .= $node['params']['width'] . "x" . $node['params']['height'] . "/";
						}
						elseif(isset($node['params']['width']) && is_numeric($node['params']['width']))
						{
							$image_url .= $node['params']['width'] . "/";
						}
					}

					$image_url .= $node['content'];

					/**
					 *
					 *
					if(isset($params, $params['url']))
					{
						return $image_url;
					}

					$return_image = "<img src='" . $image_url . "' ";

					if(isset($node['params'], $node['params']['data'])
					{
						foreach($node['params']['data'] AS $attribute => $value)
						{
							if(empty($value))
							{
								continue;
							}
							
							$return_image .= $attribute ."='".$value."' ";
						}
					}

					return $return_image . "/>";							
				}

				return $node['content'];
			}
		}
		*/
?>