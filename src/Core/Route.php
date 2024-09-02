<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\DI;
	use LCMS\Core\Request;
	use LCMS\Core\Response;
	use LCMS\Core\Redirect;
	use LCMS\Core\Locale;
	use LCMS\Backbone\View;
	use LCMS\Util\Singleton;
	use \Exception;

	class Route
	{
		use Singleton {
			Singleton::__construct as private SingletonConstructor;
		}

		public $routes = array();
		public $map = array();
		public $current;

		private $namespace = "App\\";
		private $parent;
		private $relations = array();
		private $db_relations = array();
		private $mapped = array();
		private $has_mapped = false;
		private $current_matched;
		private $request;

		function __construct(Request $_request)
		{
			$this->SingletonConstructor();

			$this->request = $_request;
		}

		public static function add(null | string $_url_pattern, mixed $_caller): string
		{
			/**
			 *	Determine what to do when this Route is in use
			 */
			$route = array(
				'key'		=> count(self::getInstance()->routes),
				'pattern' 	=> $_url_pattern
			);

			/**
			 *
			 */
			if(!is_array($_caller) && is_callable($_caller))
			{
				$route['callback'] 		= $_caller;
			}
			elseif(is_array($_caller))
			{
				$route['controller'] 	= $_caller[0];
				$route['action'] 		= $_caller[1] ?? "Index";
			}
			else
			{
				$caller_parts = explode("@", $_caller);

				$route['controller'] 	= self::getInstance()->getNamespace() . "Controllers\\" . $caller_parts[0];
				$route['action'] 		= $caller_parts[1] ?? "Index";
			}

			/**
			 *	Inherit parent settings
			 */
			if(self::getInstance()->parent)
			{
				$route['parent'] = self::getInstance()->parent['key'];

				if(!isset(self::getInstance()->routes[self::getInstance()->parent['key']]['children']))
				{
					self::getInstance()->routes[self::getInstance()->parent['key']]['children'] = array();
				}

				self::getInstance()->routes[self::getInstance()->parent['key']]['children'][] = $route['key'];

				if(empty($_url_pattern))
				{
					$route['pattern'] = self::getInstance()->parent['pattern'];
				}
				else
				{
					$route['pattern'] = ltrim(self::getInstance()->parent['pattern'] . "/" . $route['pattern'], "/");
				}
			}
			elseif(empty($route['pattern']))
			{
				$route['pattern'] = "/";
			}

			// Add to queue
			self::getInstance()->routes[] = $route;

			return $route['key'];
		}

		/**
		 *	Register subRoutes from within the Controller
		 */
		private function addControllerRoutes($_parent_key)
		{
			$route = $this->routes[$_parent_key];

			if(!isset($route['controller']) || !class_exists($route['controller']) || !method_exists($route['controller'], "router") || in_array($route['controller'], $this->mapped))
			{
				return;
			}

			$class = $this->routes[$_parent_key]['controller'];

			$this->mapped[] = $class;

			// Store current parent (May be null)
			$last_current = $this->current;

			$this->current = $this->routes[$_parent_key];

				$this->group(fn($self) => $class::router($self));

			$this->current = $last_current;

			return $_parent_key;
		}

		public static function bindControllerRoutes()
		{
			if(self::getInstance()->has_mapped)
			{
				return;
			}

			array_walk_recursive(self::getInstance()->map, fn($key) => self::getInstance()->addControllerRoutes(self::getInstance()->routes[$key]['key']));

			self::getInstance()->has_mapped = true;
		}		

		private function getCurrent(): array
		{
			return $this->routes[$this->getCurrentKey()];
		}

		private function getCurrentKey(): int
		{
			if($this->current)
			{
				return $this->current['key'];
			}

			return $this->getLastKey();
		}

		private function getLastKey(): int
		{
			return count($this->routes) - 1;
		}

		/**
		 *
		 */
		public static function alias(string $_alias): self
		{
			self::getInstance()->routes[self::getInstance()->current['key']]['alias'] = $_alias;

			self::getInstance()->relations[$_alias] = self::getInstance()->current['key'];

			return self::getInstance();
		}

		public static function get(string | null $_url_pattern, string | array $_caller): self
		{
			return self::getInstance()->map($_url_pattern, $_caller, Request::METHOD_GET);
		}

		public static function post(string | null $_url_pattern, string | array $_caller): self
		{
			return self::getInstance()->map($_url_pattern, $_caller, Request::METHOD_POST);
		}

		public static function ajax(string | null $_url_pattern, string | array $_caller): self
		{
			return self::getInstance()->map($_url_pattern, $_caller, Request::METHOD_AJAX);
		}

		public static function any(array $_methods, string $_url_pattern, string | array $_caller): self
		{
			$key = self::getInstance()->add($_url_pattern, $_caller);

			$_methods = (empty(!$_methods)) ? $_methods : array(Request::METHOD_GET, Request::METHOD_POST);

			foreach($_methods AS $method)
			{
				if(!isset(self::getInstance()->map[$method]))
				{
					self::getInstance()->map[$method] = array();
				}

				self::getInstance()->map[$method][] = $key;
			}

			return self::getInstance();
		}

		private function map(string | null $_url_pattern, string | array $_caller, string $_method): self
		{
			$key = $this->add($_url_pattern, $_caller);

			if(!isset($this->map[$_method]))
			{
				$this->map[$_method] = array();
			}

			$this->map[$_method][] = (int) $key;

			$this->current = $this->routes[$key];

			return $this;
		}

		public function group(mixed $_callback): self
		{
			$last_current = $this->current;
			$last_parent = $this->parent;

			$this->parent = $last_current;

			$_callback($this);

			$this->current = $last_current; // go back
			$this->parent = $last_parent;

			return $this;
		}

		/**
		 *
		 */
		public function require(array $_inputs): self
		{
			$this->routes[$this->getLastKey()]['required_parameters'] = array();

			foreach($_inputs AS $key => $value)
			{
				$this->routes[$this->getLastKey()]['required_parameters'][] = (is_numeric($key)) ? $value : $key;

				if(!is_numeric($key))
				{
					$this->routes[$this->getLastKey()]['required_specific_parameters'][$key] = $value;
				}
			}

			return $this;
		}

		private function parsePattern(string $_pattern): string
		{
	        // Convert the route to a regular expression: escape forward slashes
	        $_pattern = preg_replace('/\//', '\\/', $_pattern);

	        // Convert variables e.g. {controller} (Allows %+. for urlencoded strings)
	        $_pattern = preg_replace('/\{([%a-z_]+)\}/', '(?P<\1>[%a-z0-9-.]+)', $_pattern);

	        // Convert variables with custom regular expressions e.g. {id:\d+}
	      	//  $_pattern = preg_replace('/\{([a-z]+):([^\}]+)\}/', '(?P<\1>\2)', $_pattern);
	        $_pattern = preg_replace('/\{([a-z_]+):([^\']+)\}/', '(?P<\1>\2)', $_pattern);

	        // Add start and end delimiters, and case insensitive flag
	      	return '/^' . $_pattern . '$/i';
		}

		/**
		 *
		 */
		public function compile(mixed $_route): array | Response | Redirect | View
		{
			if(!$_route)
			{
				throw new Exception("No routes initialized");
			}

			// If the route doesnt have any controller, get it from the parent
			if(isset($_route['parent']) && !empty($_route['parent']))
			{
				$parent = $this->routes[$_route['parent']];
			}

			if(empty($_route['controller']))
			{
				if(!isset($parent))
				{
					throw new Exception("Cant have a parameter to startpage");
				}

				$_route['controller'] 	= $parent['controller'];
				$_route['action']		= (empty($_route['action'])) ? $parent['action'] : $_route['action'];
			}

			if(!class_exists($_route['controller']))
			{
				throw new Exception("Controller class " . $_route['controller'] . " not found");
			}
			
			/**
			 *	Create the Controller
			 */
			$_route['controller'] = DI::get($_route['controller']);

			/**
			 *	Prepare Nodes to be used
			 */
			DI::call([$_route['controller'], "first"]);

			/**
			 *	Check Middleware
			 */
			$middleware = DI::call([$_route['controller'], "middleware"], [$_route['action'] ?? null, $_route['parameters'] ?? array()]);

            if($middleware === false || ($middleware instanceof Response || $middleware instanceof Redirect || $middleware instanceof View))
            {
            	return $middleware;
            }

			return $_route;
		}

		/**
		 * Dispatch the route, creating the controller object and running the
		 * action method
		 *
		 * @param string $url The route URL
		 *
		 * @return array
		 */
		public function dispatch(Request $request, Locale $locale): array
		{
			/**
			 * 	Remove Localization from URL
			 */
			$url = $request->path();
			
			if($locale->getLanguage() && $lowercase_url = strtolower($url))
			{
				$locale_test = strtolower(str_replace("_", "-", $locale->getLocale()));

				if(in_array($lowercase_url, [$locale_test, $locale->getLanguage()]))
				{
					$url = "";
				}
				elseif(str_starts_with($lowercase_url, $locale_test . "/"))
				{
					$url = substr($url, 6);
				}
				elseif(str_starts_with($lowercase_url, $locale->getLanguage()))
				{
					$url = substr($url, 3);
				}
			}

			$url = ($url == "") ? "/" : $url; // If no url, set as / to identify root

			if(!$route_array = $this->match($url, $request->getMethod(), $request->ajax()))
			{
				throw new Exception('No route matched', 404);
			}

			$this->current = (array) $route_array;

			return $this->current;
		}

		/**
		 * Match the route to the routes in the routing table, setting the $params
		 * property if a route is found.
		 *
		 * @param string $_url The route URL
		 *
		 * @return boolean  true if a match found, false otherwise
		 */
		public function match(string $_url, string $_method = "GET", bool $_is_ajax_request = false): array | false
		{
			/**
			 *	Map all children routes, now when the rest is done
			 */
			$this->bindControllerRoutes();

			if(!isset($this->map[$_method]) && ($_is_ajax_request && !isset($this->map[Request::METHOD_AJAX])))
			{
				return false;
			}

			$maps = (isset($this->map[$_method])) ? array($_method => $this->map[$_method]) : array();
			$maps += ($_is_ajax_request && isset($this->map[Request::METHOD_AJAX])) ? array(Request::METHOD_AJAX => $this->map[Request::METHOD_AJAX]) : array();
			$maps = array_reverse($maps); // Ajax first

			foreach($maps AS $map_keys)
			{
				// Prioritizes routes from Controllers (Based on if having requirements)
				if(!$routes = array_combine($map_keys, array_map(fn($route_key) => $this->routes[$route_key], $map_keys)))
				{
					continue;
				}

				// Prioritize routes with parameter requirements
				if($routes_w_requirements = array_filter($routes, fn($r) => isset($r['required_parameters'])))
				{
					$routes = $routes_w_requirements + array_filter($routes, fn($r) => !isset($r['required_parameters']));
				}

				// Prioritize routes with pattern-fallback {} to be last
				/*if($routes_w_fallbacks = array_filter($routes, fn($r) => !empty($r['pattern']) && $r['pattern'][0] == "{"))
				{
					$routes = array_filter($routes, fn($r) => empty($r['pattern']) || $r['pattern'][0] != "{") + $routes_w_fallbacks;
				}*/

				foreach(array_filter($routes, fn($r) => !empty($r['pattern'])) AS $route_key => $route)
				{
					$pattern = $this->parsePattern($route['pattern']);

					if(!in_array($route['key'], $map_keys) || !preg_match($pattern, $_url, $matches))
					{
						continue;
					}
					elseif(isset($route['required_parameters']) && array_diff($route['required_parameters'], array_keys($this->request->all())))
					{
						continue;
					}
					elseif(isset($route['required_specific_parameters']))
					{
						$findings = array_filter($route['required_specific_parameters'], fn($v, $k) => ($this->request->all()[$k] == $v), ARRAY_FILTER_USE_BOTH);

						if(count($findings) < count($route['required_specific_parameters']))
						{
							continue;
						}
					}

					// If we captured any value from e.g {product_id}, store the catch for this matched route ['parameters' => ['product_id' => {product_id}]]
					if($params = array_map(fn($m) => $m, array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY)))
					{
						$this->routes[$route_key]['parameters'] = (isset($this->routes[$route_key]['parameters'])) ? array_merge($this->routes[$route_key]['parameters'], $params) : $params;
					}

					return $this->current_matched = $this->routes[$route_key];
				}
			}

			return false;
		}

		public function getCurrentMatched(): array | bool
		{
			return $this->current_matched ?? false;
		}

		public function getNamespace(): string
		{
			return $this->namespace;
		}

		public function setNamespace(string $_namespace): string
		{
			return $this->namespace = rtrim($_namespace, "\\") . "\\";
		}

		/**
		 *
		 */
		public static function url(string $_to_alias, array $_arguments = null, bool $_absolute = true): string | false
		{
			// Search Database-routes
			if(is_numeric($_to_alias) && !isset(self::getInstance()->db_relations[$_to_alias]) && !isset(self::getInstance()->relations[$_to_alias]))
			{
				throw new Exception("No RouteAliasId found (" . $_to_alias . ")");
			}
			elseif(!is_numeric($_to_alias) && !isset(self::getInstance()->relations[$_to_alias]))
			{
				throw new Exception("No RouteAlias found (" . $_to_alias . ")");
			}

			// Fallback, Route probably deleted from LCMS
			if(!$url = (is_numeric($_to_alias) && isset(
				self::getInstance()->db_relations[$_to_alias], self::getInstance()->routes[self::getInstance()->db_relations[$_to_alias]]
			)) ? self::getInstance()->routes[self::getInstance()->db_relations[$_to_alias]]['pattern'] : self::getInstance()->routes[self::getInstance()->relations[$_to_alias]]['pattern'] ?? false)
			{
				return false;
			}

			/**
			 *	If found pattern to replace from $_arguments
			 */
			if(preg_match_all("/{\K[^}]*(?=})/m", $url, $matches) && !empty($matches[0]))
			{
				if(empty($_arguments))
				{
					throw new Exception("RouteAlias (".$_to_alias.") requires arguments (".implode(", ", $matches[0]).")");
				}

				foreach($matches[0] AS $pattern)
				{
					$match = preg_split('/[^[:alnum:]\_]+/', $pattern)[0];

					if(!isset($_arguments[$match]))
					{
						throw new Exception("RouteAlias (".$_to_alias.") requires argument (".$match.")");
					}

					$url = str_replace("{".$pattern."}", $_arguments[$match], $url);

					unset($_arguments[$match]);
				}
			}

			/**
			 * 	Should we prepend language? (If available in current URL)
			 */
			$url_parts = explode("/", DI::get(Request::class)->path());
			$new_url = array();

			if(!empty($url_parts[0]) 
				&& ((strlen($url_parts[0]) == 2 && $url_parts[0] == Locale::getLanguage()) 
					|| strlen($url_parts[0]) == 5 && $url_parts[0] == strtolower(str_replace("_", "-", Locale::getLocale()))))
			{
				$new_url[] = $url_parts[0];
			}
			
			if(!empty($url) && $url != "/")
			{
				$new_url[] = trim(strtolower($url));
			}
			
			$url = rtrim("/" . implode("/", $new_url), "/");

			if(!empty($_arguments) && $_arguments = array_filter($_arguments))
			{
				$url .= "?" . http_build_query($_arguments);
			}

			if(!$_absolute)
			{
				return $url;
			}

			return self::getInstance()->request->root() . "/" . ltrim($url, "/");
		}

		/**
		 *	Try to rewerse engineer the Route from the URL
		 */
		public static function getRouteFromUrl(string $_url): mixed
		{
			return self::getInstance()->match(ltrim(parse_url($_url)['path'], "/"), "GET", false, false);
		}

		public static function asItem(string $_alias): array
		{
			if(!isset(self::getInstance()->relations[$_alias]))
			{
				throw new Exception("No RouteAlias found: " . $_alias);
			}

			return self::getInstance()->routes[self::getInstance()->relations[$_alias]];
		}

		// Pair everything as a tree based on parent/children
		public function asTree(bool $_strict = true)
		{
			// Root
			$root = array();

			foreach($this->routes AS $r)
			{
				if(isset($r['parent']) || ($_strict && !in_array($r['key'], $this->map[Request::METHOD_GET])))
				{
					continue;
				}

				$route = $this->recursiveChildren($r);

				unset($route['key']);

				$root[] = $route;
			}

			return $root;
		}

		/**
		 * 	Used in Navigation(s)
		 * 		Recursivly travels through given route and returns all aliases
		 */
		public function asTreeAliases(array $route, array $aliases = array()): array
		{
			if(isset($route['alias']))
			{
				$aliases[] = $route['alias'];
			}

			if(isset($route['parent']))
			{
				return $this->asTreeAliases($this->routes[$route['parent']], $aliases);
			}

			return $aliases;
		}

		private function recursiveChildren(mixed $self): mixed
		{
			if(!isset($self['children']))
			{
				return $self;
			}

			$children = array();

			foreach($self['children'] AS $key)
			{
				$route = $this->routes[$key];

				if(!in_array($route['key'], $this->map[Request::METHOD_GET]))
				{
					continue;
				}

				unset($route['key'], $route['parent']);

				$children[$key] = $this->recursiveChildren($route);
			}

			if(empty($children))
			{
				unset($self['children']);

				return $self;
			}

			$self['children'] = array_values($children);

			return $self;
		}

		public function asArray(): array
		{
			return $this->routes;
		}

		public function merge($_routes): self
		{
			foreach($_routes AS $k => $r)
			{
				if(isset($r['settings'], $r['settings'][Locale::getLanguage()], $r['settings'][Locale::getLanguage()]['disabled']) && $r['settings'][Locale::getLanguage()]['disabled']['value'])
				{
					// Remove this route from the mapping too
					foreach(array_filter($this->map, fn($keys) => in_array($k, $keys)) AS $map => $keys)
					{
						unset($this->map[$map][array_search($k, $keys)]);
					}
				}
				elseif(!isset($r['key']) && !in_array($k, $this->map[Request::METHOD_GET]))
				{
					$this->map[Request::METHOD_GET][] = $k;
				}

				if(isset($r['id']))
				{
					$this->db_relations[$r['id']] = $k;
				}

				$this->routes[$k] = (isset($this->routes[$k])) ? array_merge($this->routes[$k], $r) : $r;

				if(!isset($this->routes[$k]['key']))
				{
					$this->routes[$k]['key'] = $k;
				}

				if(!empty($r['alias']) && !isset($this->relations[$r['alias']]))
				{
					$this->relations[$r['alias']] = $k;
				}

				if(isset($r['parent_id']) && !empty($r['parent_id']) && !isset($this->routes[$k]['parent']))
				{
					$this->routes[$k]['parent'] = $this->db_relations[$r['parent_id']];
				}
			}

			return $this;
		}
	}
?>