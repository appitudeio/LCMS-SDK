<?php
	/**
	 *	Node ("Sections") editable through Admin GUI
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2018-10-16
	 * 
	 * 	Changelog
	 * 	- 2023-04-05: Added Enum Types + minor fixes for PHP 8.2
	 */
	namespace LCMS\Core;

	use LCMS\Core\Route;
	use LCMS\Core\Locale;
	use LCMS\Util\Singleton;
	use LCMS\Util\Arr;
	use LCMS\Util\Toolset;

	use \Iterator;
	use \Exception;

	enum NodeType: int
	{
		case TEXT 		= 1;
		case HTML 		= 2;
		case TEXTAREA 	= 3;
		//case BOOL 	= 4;
		case BOOLEAN 	= 4;
		case ARRAY 		= 6;
		case IMAGE 		= 10;
		case BACKGROUND = 11;
		case FILE 		= 15;
		case ROUTE 		= 20;
		case HYPERLINK 	= 25;
		case LOOP 		= 30;
	};
	
	class Node
	{
		use Singleton {
			Singleton::__construct as private SingletonConstructor;
		}

		public const TYPE_TEXT 		= NodeType::TEXT;
		public const TYPE_HTML 		= NodeType::HTML;
		public const TYPE_TEXTAREA 	= NodeType::TEXTAREA;
		public const TYPE_BOOL 		= NodeType::BOOLEAN;
		public const TYPE_BOOLEAN 	= NodeType::BOOLEAN;
		public const TYPE_ARRAY 	= NodeType::ARRAY;
		public const TYPE_IMAGE 	= NodeType::IMAGE;
		public const TYPE_BACKGROUND = NodeType::BACKGROUND;
		public const TYPE_FILE 		= NodeType::FILE;
		public const TYPE_ROUTE 	= NodeType::ROUTE;
		public const TYPE_HYPERLINK	= NodeType::HYPERLINK;
		public const TYPE_LOOP		= NodeType::LOOP;

		public array | null $namespace = null;
		public array $type_properties;
		private array $parameters;
		private array $nodes = array();
		private array $properties = array();
		private array $private_properties = array(
			'image_endpoint' => "https://static.logicalcms.com/"
		);
		private array $added_dynamically = array();

		function __construct()
		{
			$this->SingletonConstructor();

			$this->type_properties = array(
				NodeType::IMAGE->value => array(
					'alt' 	=> null,
					'title'	=> null,
					'width' => null,
					'height' => null,
					'src' => null
				),
				NodeType::ROUTE->value => array(
					'route' => null,
					'title' => null
				),
				NodeType::HYPERLINK->value => array(
					'href' => null,
					'title' => null
				)
			);
		}

		function __get(string $_key): mixed
		{
			return $this->private_properties[$_key] ?? null;
		}

		function __set(string $_key, mixed $_value): void
		{
			$this->private_properties[$_key] = $_value;
		}

		public static function init(string $_image_endpoint): void
		{
			self::getInstance()->private_properties['image_endpoint'] .= $_image_endpoint;
		}

		public function setNamespace(array $_namespace): void
		{
			$this->namespace = array_filter($_namespace);
		}

		/**
		 *	@return Array of all nodes
		 */
		public static function getAll(): array
		{
			return self::getInstance()->nodes;
		}

		public static function getParameters(): array
		{
			return self::getInstance()->parameters ?? array();
		}

		/**
		 *	Prepares all Nodes we want to load before we use them in the document
		 */
		public static function prepare(string $_identifier, NodeType $_type, array $_params = array()): NodeObject | bool | array
		{
			if($_type == NodeType::LOOP)
			{
				$loop_items = $loop_items_flat = array();
				
				foreach($_params AS $key => $value)
				{
					if(!($value instanceof NodeType) && (!is_array($value) || !($value[0] instanceof NodeType)))
					{
						continue;
					}

					$type = ($value instanceof NodeType) ? $value->value : $value[0]->value;

					$loop_items[] = array_filter(array(
						'type' => $type,
						'identifier' => $key, 
						'properties' => ((is_array($value) && isset($value[1])) || isset(self::getInstance()->type_properties[$type])) ? array_replace_recursive(self::getInstance()->type_properties[$type] ?? array(), (is_array($value)) ? $value[1] ?? array() : array()) : null
					));

					$loop_items_flat[] = $key;

					unset($_params[$key]);
				}
			}

			// If it already exists, try to update (if new Items)
			if(self::exists($_identifier) && $node = self::get($_identifier))
			{
				if(isset($loop_items)) // is Loop
				{
					$props = ($node instanceof NodeObject) ? array_column($node->asArray()['parameters'] ?? $node->asArray()['properties'], "identifier") : array_keys($node[array_key_first($node)]);
					$has_items = ($node instanceof NodeObject && isset($node->asArray()['items'])) ? true : false;

					// If something hasnt changed, just return it
					if(count($loop_items_flat) == count($props) && !array_diff($loop_items_flat, $props))
					{
						return ($has_items) ? $node : false;
					}
				}
				else
				{
					return $node;
				}
			}

			// Add this new Node
			self::set($_identifier, $loop_items ?? "", $_params + ['type' => $_type->value]);

			if(isset($has_items, $node) && $has_items)
			{
				return $node;
			}

			return false;
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
			$id = (self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier;
			$is_local = true; // Check local namespace first with Global as fallback

			if(is_bool($node = Arr::get(self::getInstance()->nodes, $id, false)) && is_bool($props = Arr::get(self::getInstance()->properties, $id, false)))
			{
				if(is_bool($node = Arr::get(self::getInstance()->nodes, "global." . $_identifier, false)))
				{
					return false;
				}

				$is_local = false;
			}

			// Look for properties
			$properties = (empty(self::getInstance()->properties)) ? null : (($is_local) ? $props ?? Arr::get(self::getInstance()->properties, $id) : Arr::get(self::getInstance()->properties, "global." . $_identifier));

			// Loop or Array?
			if(is_array($node) || (!is_string($node) && is_array($properties)))
			{
				return (is_array($node) && !array_is_list($node)) ? $node : new NodeObject($_identifier, $node ?: array(), (is_array($properties)) ? $properties : array());
			}

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

			return new NodeObject($_identifier, $node);
		}

		/**
		 *	Check local namespace, with global as fallback
		 */
		public static function set(string $_identifier, mixed $_value, array $_params = array()): self
		{
			$identifier = $_identifier;

			if(is_bool($_params['global'] ?? null) && $_params['global'])
			{
				$identifier = "global" . $_identifier;
			}
			elseif(self::getInstance()->namespace != null)
			{
				$identifier =  (self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier;
			}

			//$_params['type'] = $_params['type'] ?? ((is_array($_value)) ? NodeType::LOOP->value : NodeType::TEXT->value);
			$type = $_params['type'] ?? Node::TYPE_TEXT;
			unset($_params['global'], $_params['type']); // Remove, this is decided separately

			// Append properties into Loop, if found
			if((is_array($_value) && !empty($_value)) || !empty($_params))
			{
				$arr = $props = array();
				$ins = (is_array($_value)) ? ((empty($_value)) ? ['type' => NodeType::LOOP->value] : $_value) : $_params;
				
				Arr::unflatten($arr, $identifier, (is_array($_value)) ? array() : "");
				Arr::unflatten($props, $identifier, $ins);

				self::getInstance()->nodes = array_replace_recursive(self::getInstance()->nodes, $arr);

				// Remove if any 'old' loop layes here
				Arr::forget(self::getInstance()->properties, $identifier);
				self::getInstance()->properties = array_replace_recursive(self::getInstance()->properties, $props);
			}
			elseif(!is_array($_value))
			{
				$props = array();
				$ins = array_merge($_params ?? array(), ['type' => $type]);
				
				Arr::unflatten(self::getInstance()->nodes, $identifier, $_value);
				Arr::unflatten($props, $identifier, $ins);
				self::getInstance()->properties = array_replace_recursive(self::getInstance()->properties, $props);
			}

			// If not found merged before (Exclude 'meta')
			if(!str_contains($_identifier, "meta."))
			{
				self::getInstance()->added_dynamically[] = $_identifier; // Let future Merger's know about this
			}

			return self::getInstance();
		}

		public static function has(string $_identifier): bool
		{
			return self::getInstance()->exists($_identifier);
		}

		// Check Local namespace first, then as fallback Global"
		public static function exists(string $_identifier): bool
		{
			// Check from namespaced node
			$id = (self::getInstance()->namespace['alias'] ?? self::getInstance()->namespace['pattern']) . "." . $_identifier;

			if(self::getInstance()->namespace != null && (null === $node = Arr::get(self::getInstance()->nodes, $id)))
			{
				return false;
			}
			elseif(Arr::has(self::getInstance()->nodes, $_identifier))
			{
				return true;
			}
			elseif(self::getInstance()->namespace == null)
			{
				return false;
			}

			return Arr::has(self::getInstance()->nodes, $id) || Arr::has(self::getInstance()->properties, $id);
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
				$this->properties = array_replace_recursive($this->properties ?? array(), $_properties);
			}
			
			/**
			 *	Merge data with strings
			 */
			if(empty($this->nodes) || empty($this->parameters) || (($parameters = array_filter($this->parameters, fn($v) => !is_array($v))) && empty($parameters)))
			{
				return $this;
			}
			
			// Convert ['static_path' => "https://..."] => ['{{static_path}}' => "https://..."]
			if($parameters = array_combine(array_map(fn($key) => "{{" . $key . "}}", array_keys($parameters)), $parameters))
			{
				array_walk_recursive($this->nodes, fn(&$item) => (!empty($item) && str_contains($item, "{{")) ? $item = strtr($item, $parameters) : $item);
				
				if(!empty($this->properties) && !empty($parameters))
				{
					array_walk_recursive($this->properties, fn(&$item) => (!empty($item) && str_contains($item, "{{")) ? $item = strtr($item, $parameters) : $item);	
				}
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

		public static function getAdded()
		{
			return self::getInstance()->added_dynamically;
		}

		public static function createNodeObject(mixed $_identifier = null, array $_node): NodeObject
		{
			if(!empty(self::getInstance()->parameters))
			{
				$_node['parameters'] = self::getInstance()->parameters;
			}

			return new NodeObject($_identifier, $_node);
		}
	}

	class NodeObject implements Iterator
	{
		private mixed 	$identifier;
		private int 	$type;
		private array 	$node;
		private array 	$properties;
		private array 	$parameters = [];
		private array 	$return_as = ["text"]; // Default
		private int 	$index = 0; // for the iterator
		private mixed 	$items = false;

		function __construct(string $_identifier = null, array | string $_node, array $_properties = null)
		{
			$this->identifier = $_identifier;

			if(is_string($_node))
			{
				$_node = array('content' => $_node);
			}
			elseif(array_is_list($_node)) // Store Loop structure as 'parameters'
			{
				// Convert node to proper loop structure (instead of [identifier => "value") => [identifier => ['content' => "value]])
				if(($_node = array_map(fn($node) => array_combine(array_keys($node), array_map(fn($val, $id) => ['identifier' => $id] + ((!empty($val)) ? ['content' => $val] : []), $node, array_keys($node))), $_node)) && !empty($_properties))
				{
					// Merge with properties
					foreach($_properties AS $row => $props)
					{
						if(($id = array_key_first($props)) && !isset($_node[$row][$id]))
						{
							continue;
						}

						$_node[$row][$id]['properties'] = $props[$id];
					}

					// If "Root" Loop, and properties differs from Nodes [Parameters = Loop structure]
					$this->parameters = array_map(fn($n) => array_merge($_node[0][$n], ((isset($_properties[0][$n])) ? ['properties' => $_properties[0][$n]] : [])), array_keys($_node[0]));
				}

				$_properties = (!empty($_properties)) ? $_properties : ((!empty($_node)) ? array_values($_node[array_key_first($_node)]) : null);
			}

			$this->node = $_node;
			$this->properties = (!empty($_properties)) ? $_properties : $this->node['properties'] ?? array();

			if(array_is_list($_node))
			{
				$this->type = NodeType::LOOP->value;
				$this->items = $this->loop();
			}
			else
			{
				$this->type = (isset($this->properties['type'])) ? (($this->properties['type'] instanceof NodeType) ? $this->properties['type']->value : (int) $this->properties['type']) : NodeType::TEXT->value; // default
			}

			unset($this->properties['type'], $this->properties['identifier'], $this->node['properties']['type']);
		}

		public function extend(string $_key, mixed $_value)
		{
			if($this->type == NodeType::LOOP->value)
			{
				$this->items[$this->index][$_key] = $_value;
			}
			else
			{
				// not yet(?) [TODO]
			}
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
			$this->return_as = [__FUNCTION__];

			// Any params we should replace 
			$forbidden_keys = array('name', 'type', 'content', 'as');

			// If route or hyperlink, the 'content' is inside a property
			if(isset($this->node['content']) && !empty($this->node['content']) && str_contains($this->node['content'], "{{") && $_parameters = array_filter(array_replace_recursive($this->node['parameters'] ?? array(), $_parameters), fn($key) => in_array($key, $forbidden_keys), ARRAY_FILTER_USE_KEY))
			{
				// Convert ['static_path' => "https://..."] => ['{{static_path}}' => "https://..."]
				$_parameters = array_combine(array_map(fn($key) => "{{" . $key . "}}", array_keys($_parameters)), $_parameters);
	
				//$this->node['parameters'] = array_merge($this->node['parameters'] ?? [], $_parameters);
				$this->node['content'] = strtr($this->node['content'], $_parameters);
			}

			$this->return_as[] = $this->node['content'] ?? "";

			return $this;
		}

		/**
		 * 	
		 */
		public function image(int $_width = null, int $_height = null): self
		{
			$this->return_as = [__FUNCTION__];

			// If empty image
			if(!$src = $this->properties['src'] ?? $this->node['content'] ?? false)
			{
				return $this;
			}
			elseif(str_starts_with($src, "http") || $src[0] == "/")
			{
				// "local" images cant be resized
				$image_url = $src;
			}
			else
			{
				$size = (!empty($_width) && !empty($_height)) ? $_width . "x" . $_height : ((!empty($_width)) ? $_width : null);
				$image_url = Node::getInstance()->image_endpoint . $src;
				$image_url = (!empty($size)) ? Toolset::resize($image_url, $size) : $image_url;
			}

			$image_props = "";

			if($properties = (isset($this->node['properties']) && !empty($this->node['properties'])) ? array_filter($this->node['properties'], fn($k) => !in_array($k, ['width', 'height']), ARRAY_FILTER_USE_BOTH) ?? null : null)
			{
				$image_props = implode(" ", array_map(fn($key) => $key . '="' . $properties[$key] . '"', array_keys($properties)));
			}

			$this->return_as[] = "<img src='".$image_url."'" . $image_props . " />";

			return $this;
		}

		/**
		 *	
		 */
		public function picture(int $_width = null, int $_height = null): self
		{
			$this->return_as = [__FUNCTION__];

			// If empty image
			if(!$src = $this->properties['src'] ?? $this->node['content'] ?? false)
			{
				return $this;
			}
			elseif(str_starts_with($src, "http") || $src[0] == "/")
			{
				return $this->image($_width, $_height);
			}
			
			$image_url = Node::getInstance()->image_endpoint . $src;

			$size = (!empty($_width) && !empty($_height)) ? $_width . "x" . $_height : ((!empty($_width)) ? $_width : null);

			$this->node['properties'] = (isset($this->node['properties']) && !empty($this->node['properties'])) ? array_filter(array_filter($this->node['properties']), fn($k) => !in_array($k, ['width', 'height']), ARRAY_FILTER_USE_KEY) ?? null : null;

			$this->return_as[] = Toolset::picture($image_url, $this->node['properties'] ?? array(), $size);

			return $this;
		}

		/**
		 * 	
		 */
		public function background(int $_width = null, int $_height = null): self
		{
			$this->return_as = [__FUNCTION__];

			// If empty image
			if(!$src = $this->properties['src'] ?? $this->node['content'] ?? false)
			{
				return $this;
			}
			elseif(str_starts_with($src, "http") || $src[0] == "/")
			{
				// "local" images cant be resized
				$image_url = $src;
			}
			else
			{
				$size = (!empty($_width) && !empty($_height)) ? $_width . "x" . $_height : ((!empty($_width)) ? $_width : null);
				$image_url = Node::getInstance()->image_endpoint . $src;
				$image_url = (!empty($size)) ? Toolset::resize($image_url, $size) : $image_url;
			}

			$this->return_as[] = $image_url;

			return $this;
		}

		/**
		 *  
		 */
		public function href(): self
		{
			$this->return_as = [__FUNCTION__];

			// Any params we should replace
			if(!$href = $this->node['properties']['href'] ?? $this->node['content'] ?? false)
			{
				return $this;
			}

			//$forbidden_properties = array('name', 'type', 'as');

			// If route or hyperlink, the 'content' is inside a property
			if(str_contains($href, "{{") && $_parameters = (isset($this->node['parameters']) && !empty($this->node['parameters'])) ? array_combine(array_map(fn($key) => "{{" . $key . "}}", array_keys($this->node['parameters'])), $this->node['parameters']) : array()) // && $_parameters = array_filter(array_replace_recursive($this->node['parameters'] ?? array(), $_parameters), fn($key) => in_array($key, $forbidden_properties), ARRAY_FILTER_USE_KEY))
			{
				$href = $this->node['properties']['href'] = strtr($href, $_parameters);
			}

			$this->return_as[] = $href;

			return $this;
		}

		/**
		 * 	
		 */
		public function route(array $_properties = array()): self
		{
			$this->return_as = [__FUNCTION__];

			if(($route = $this->node['content'] ?? $this->node['properties']['route']) && $route != "#")
			{
				$this->node['properties']['href'] = Route::url($route);
			}
			else
			{
				$this->node['properties']['href'] = "#";
			}

			$this->return_as[] = $this->node['properties']['href'];

			return $this;
		}

		public function prop(string $_property): self
		{
			$this->return_as = [__FUNCTION__, $this->properties[$_property] ?? ""];

			return $this;
		}

		/**
		 * 	Convert all 'items' in loop into NodeObjects
		 */
		public function loop(): array
		{
			if($this->type != NodeType::LOOP->value || (is_array($this->items) && !empty($this->items)))
			{
				return $this->items;
			}
			
			// Pair properties to it's node
			$props = $this->properties; //array_combine(array_column($this->properties, "identifier"), $this->properties);

			$items = array_values(
						array_map(fn($n) => 
							array_combine(
								array_keys($n),
									array_map(fn($v, $k) => new NodeObject($k, $v, $props[$k] ?? null), $n, array_keys($n))), $this->node));

			// If only one item, maybe it's not meant to be used
			if(count($items) == 1 && !array_filter(array_map(fn($n) => (string) $n, $items[array_key_first($items)])))
			{
				return array();
			}

			return $items;
		}

		public function asArray(): array
		{
			//$this->items = $this->loop();

			$node = ($this->type == NodeType::LOOP->value) ? array() : $this->node;
			
			return array_filter($node + ['properties' => $this->properties, 'identifier' => $this->identifier, 'type' => $this->type, 'items' => $this->items, 'parameters' => $this->parameters]);
		}

		function __toString(): string
		{
			return $this->return_as[1] ?? $this->node['content'] ?? "";
		}

		public function rewind(): void { $this->index = 0; $this->items = $this->loop(); }
		public function current(): mixed { return $this->items[$this->index]; }
		public function key(): mixed { return $this->index; }
		public function next(): void { ++$this->index; }
		public function valid(): bool { return isset($this->items[$this->index]); }
	}
?>