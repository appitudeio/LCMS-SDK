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
	use LCMS\Util\Singleton;
	use LCMS\Util\Arr;
	use LCMS\Util\Toolset;
	use \Exception;
	
	class Node
	{
		use Singleton;

		public static $image_endpoint = "https://static.logicalcms.com/";

		public const TYPE_TEXT 		= 1;
		public const TYPE_HTML 		= 2;
		public const TYPE_TEXTAREA 	= 3;
		public const TYPE_BOOL 		= 4;
		public const TYPE_BOOLEAN 	= 4;
		public const TYPE_ARRAY 	= 6;
		public const TYPE_IMAGE 	= 10;
		public const TYPE_BACKGROUND 	= 11;
		public const TYPE_FILE 		= 15;
		public const TYPE_ROUTE 	= 20;
		public const TYPE_HYPERLINK	= 25;
		public const TYPE_LOOP		= 30;

		public $namespace;
		public static $type_properties = array(
			Node::TYPE_IMAGE => array(
				'alt' 	=> null,
				'title'	=> null,
				'width' => null,
				'height' => null
			),
			Node::TYPE_ROUTE => array(
				'route' => null
			),
			Node::TYPE_HYPERLINK => array(
				'href' => null
			)
		);
		private $parameters;
		private $nodes;
		private $properties;

		public static function init(string $_image_endpoint): void
		{
			self::getInstance()::$image_endpoint .= $_image_endpoint;
		}

		public function setNamespace(array $_namespace): void
		{
			$this->namespace = array_filter($_namespace);
		}

		/**
		 *	@return Array of all nodes
		 */
		public function getAll(): array
		{
			return $this->nodes;
		}

		public function getParameters(): array
		{
			return $this->parameters ?? array();
		}

		/**
		 * 	
		 * 	@return 
		 * 		- Boolean if not found
		 * 		- Array if a loop
		 * 		- NodeObject if a Node
		 */
		public static function get(string $_identifier): bool | array | NodeObject
		{
			// Check local namespace first with Global as fallback
			$is_local = true;

			if(is_bool($node = Arr::get(self::getInstance()->nodes, (self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier, false)))
			{
				if(is_bool($node = Arr::get(self::getInstance()->nodes, "global." . $_identifier, false)))
				{
					return false;
				}

				$is_local = false;
			}

			if(is_array($node))
			{
				if(empty($node)) // Loop
				{
					return new NodeObject(array());			
				}
				
				return $node;
			}

			// Look for properties
			$properties = (empty(self::getInstance()->properties)) ? null : (($is_local) ? Arr::get(self::getInstance()->properties, (self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier) : Arr::get(self::getInstance()->properties, "global." . $_identifier));

			$node = (is_string($node)) ? array('content' => $node) : $node;
			$node += array(
				'properties' => $properties ?: null
			);

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

		/**
		 *	Check local namespace, with global as fallback
		 */
		public static function set(string $_identifier, mixed $_value): void
		{
			if(self::getInstance()->namespace != null)
			{
				$_identifier =  (self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier;
			}
			else
			{
				$_identifier = "global." . $_identifier;
			}

			Arr::unflatten(self::getInstance()->nodes, $_identifier, $_value);
		}

		public static function has(string $_identifier): bool
		{
			return self::getInstance()->exists($_identifier);
		}

		// Check Local namespace first, then as fallback Global"
		public static function exists(string $_identifier): bool
		{
			// Check from namespaced node
			if(self::getInstance()->namespace != null && !$node = Arr::get((self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier))
			{
				return false;
			}

			if(Arr::has(self::getInstance()->nodes, $_identifier))
			{
				return true;
			}
			elseif(self::getInstance()->namespace == null)
			{
				return false;
			}

			return Arr::has(self::getInstance()->nodes,  (self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier);
		}	

		/**
		 *	Probably from Database, via Api\Merge
		 */
		public function merge(array $_nodes = array(), array $_properties = null, array $_unmergers = null): self
		{
			if(!empty($_unmergers) && !empty($this->nodes))
			{
				array_walk($_unmergers, fn($um) => Arr::forget($this->nodes, $um));
			}

			if(!empty($_nodes))
			{
				$this->nodes = array_replace_recursive($this->nodes ?? array(), $_nodes);
			}

			if(!empty($_properties))
			{
				$this->properties = array_replace_recursive($this->properties ?? array(), $_properties ?? array());
			}
			
			/**
			 *	Merge data with strings
			 */
			if(empty($this->nodes) || empty($this->parameters) || (($parameters = array_filter($this->parameters, fn($v) => !is_array($v))) && empty($parameters)))
			{
				return $this;
			}
			
			// Convert ['static_path' => "https://..."] => ['{{static_path}}' => "https://..."]
			$parameters = array_combine(array_map(fn($key) => "{{" . $key . "}}", array_keys($parameters)), $parameters);
			array_walk_recursive($this->nodes, fn(&$item) => (!empty($item) && str_contains($item, "{{")) ? $item = strtr($item, $parameters) : $item);
			
			if(!empty($this->properties))
			{
				array_walk_recursive($this->properties, fn(&$item) => (!empty($item) && str_contains($item, "{{")) ? $item = strtr($item, $parameters) : $item);	
			}
			
			return $this;
		}

		public function getParameter(string $_key): array | null
		{
			return $this->parameters[$_key] ?? null;
		}

		public static function with(string | array $_key, mixed $_value = null): self
		{
			if(is_array($_key))
			{
				foreach($_key AS $k => $v)
				{
					self::getInstance()->parameters[$k] = $v;
				}
			}
			else
			{
				self::getInstance()->parameters[$_key] = $_value;
			}

			return self::getInstance();
		}

		public static function createNodeObject(array $_node): NodeObject
		{
			if(!empty(self::getInstance()->parameters))
			{
				$_node['parameters'] = self::getInstance()->parameters;
			}

			return new NodeObject($_node);
		}
	}

	class NodeObject
	{
		private $node;
		private $image_endpoint;
		private $return_as;

		function __construct(array | string $_node)
		{
			$this->image_endpoint = Node::$image_endpoint;

			if(is_string($_node))
			{
				$_node = array('content' => $_node);
			}

			$this->node = $_node;
		}

		public function setProperties(array $_properties): self
		{
			$this->node['properties'] = array_merge($this->node['properties'] ?? array(), $_properties);

			return $this;
		}

		/**
		 * 	
		 */
		public function text(array $_parameters = array()): self
		{
			$this->return_as = __FUNCTION__;

			// Any params we should replace 
			$forbidden_keys = array('name', 'type', 'content', 'as');

			if(str_contains($this->node['content'], "{{") && $_parameters = array_filter(array_replace_recursive($this->node['parameters'] ?? array(), $_parameters), fn($key) => in_array($key, $forbidden_keys), ARRAY_FILTER_USE_KEY))
			{
				// Convert ['static_path' => "https://..."] => ['{{static_path}}' => "https://..."]
				$_parameters = array_combine(array_map(fn($key) => "{{" . $key . "}}", array_keys($_parameters)), $_parameters);
	
				$this->node['parameters'] = array_merge($this->node['parameters'] ?? [], $_parameters);

				$this->node['content'] = strtr($this->node['content'], $this->node['parameters']);
			}

			return $this;
		}

		/**
		 * 	
		 */
		public function image(int $_width = null, int $_height = null): self
		{
			$this->return_as = __FUNCTION__;

			// If empty image
			if(empty($this->node['content']))
			{
				return $this;
			}

			$size = (!empty($_width) && !empty($_height)) ? $_width . "x" . $_height : ((!empty($_width)) ? $_width : null);

			if(str_starts_with($this->node['content'], "http") || $this->node['content'][0] == "/")
			{
				$image_url = $this->node['content'];
			}
			else
			{
				$image_url = $this->image_endpoint . $this->node['content'];
			}

			$image_url = (!empty($size)) ? Toolset::resize($image_url, $size) : $image_url;

			$image_props = "";

			if($properties = (isset($this->node['properties']) && !empty($this->node['properties'])) ? array_filter($this->node['properties'], fn($k) => !in_array($k, ['width', 'height']), ARRAY_FILTER_USE_BOTH) ?? null : null)
			{
				$image_props = implode(" ", array_map(fn($key) => $key . '="' . $properties[$key] . '"', array_keys($properties)));
			}

			$this->node['content'] = "<img src='".$image_url."'" . $image_props . " />";

			return $this;
		}

		/**
		 *	
		 */
		public function picture(int $_width = null, int $_height = null): self
		{
			$this->return_as = __FUNCTION__;

			// If empty image
			if(empty($this->node['content']))
			{
				return $this;
			}

			if(str_starts_with($this->node['content'], "http") || $this->node['content'][0] == "/")
			{
				$image_url = $this->node['content'];
			}
			else
			{
				$image_url = $this->image_endpoint . $this->node['content'];
			}

			$size = (!empty($_width) && !empty($_height)) ? $_width . "x" . $_height : ((!empty($_width)) ? $_width : null);

			$this->node['properties'] = (isset($this->node['properties']) && !empty($this->node['properties'])) ? array_filter(array_filter($this->node['properties']), fn($k) => !in_array($k, ['width', 'height']), ARRAY_FILTER_USE_KEY) ?? null : null;
			$this->node['content'] = Toolset::picture($image_url, $this->node['properties'] ?? array(), $size);

			return $this;
		}

		/**
		 * 	
		 */
		public function background(int $_width = null, int $_height = null): self
		{
			$this->return_as = __FUNCTION__;

			// If empty image
			if(empty($this->node['content']))
			{
				return $this;
			}

			if(str_starts_with($this->node['content'], "http") || $this->node['content'][0] == "/")
			{
				return $this->node['content'];
			}

			$image_url = $this->image_endpoint;

			if(!empty($_width) && !empty($_height))
			{
				$image_url .= $_width . "x" . $_height . "/";
			}
			elseif(!empty($_width))
			{
				$image_url .= $_width . "/";
			}

			$this->node['content'] = $image_url .= $this->node['content'];

			return $this;
		}

		/**
		 *  
		 */
		public function href(): self
		{
			$this->return_as = __FUNCTION__;

			// Any params we should replace
			if(!isset($this->node['parameters']) || empty($this->node['parameters']))
			{
				return $this;
			}

			$forbidden_properties = array('name', 'type', 'as');
			$_parameters = array_combine(array_map(fn($key) => "{{" . $key . "}}", array_keys($this->node['parameters'])), $this->node['parameters']);
	
			foreach(array_filter($this->node['properties'], fn($v, $k) => !in_array($k, $forbidden_properties) && str_contains($v, "{{"), ARRAY_FILTER_USE_BOTH) AS $prop => $content)
			{
				$this->node['properties'][$prop] = strtr($content, $_parameters);
			}

			if(isset($this->node['content']) && str_contains($this->node['content'], "{{"))
			{
				$this->node['content'] = strtr($this->node['content'], $_parameters);
			}

			return $this;
		}

		/**
		 * 	
		 */
		public function route(array $_properties = array()): self
		{
			$this->return_as = __FUNCTION__;

			if(!isset($this->node['properties']))
			{
				$this->node['properties'] = array();
			}

			$this->node['properties']['href'] = $this->node['content'] = (!empty($this->node['content']) && $this->node['content'] != "#") ? Route::url($this->node['content']) : "#";

			return $this;
		}

		/**
		 * 	
		 */
		public function loop(): array
		{
			return array_filter($this->node, fn($k, $v) => is_numeric($v), ARRAY_FILTER_USE_BOTH);
		}

		public function asArray(): array
		{
			return $this->node;
		}

		function __toString(): string
		{
			if($this->return_as == "href")
			{
				return $this->node['properties']['href'] ?? $this->node['content'];
			}

			return $this->node['content'];
		}
	}
/*
	----Graveyard for later use:


	public function picture($width = null, $height = null)
	{
			/*
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
?>