<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;
	use LCMS\Core\Response;
	use LCMS\Core\Redirect;
	use LCMS\Core\Locale;
	use LCMS\Backbone\View;
	use \Exception;

	class Route
	{
		use \LCMS\Utils\Singleton;

		public static $routes = array();
		public static $map = array();
		public static $current;

		private static $namespace = "App\\";
		private static $parent;
		private static $relations = array();
		private static $db_relations = array();
		private static $mapped = array();
		private static $has_mapped = false;
		private static $current_matched;
		private $request;

		function __construct(Request $_request = null)
		{
			self::$instance = $this;

			if(!empty($_request))
			{
				self::$instance->request = $_request;
			}
			else
			{
				self::$instance->request = new Request();
			}
		}

		private static function add($_url_pattern, $_caller): string
		{
			/**
			 *	Determine what to do when this Route is in use
			 */
			$route = array(
				'key'		=> count(self::$routes),
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
			if(self::$parent)
			{
				$route['parent'] = self::$parent['key'];

				if(!isset(self::$routes[self::$parent['key']]['children']))
				{
					self::$routes[self::$parent['key']]['children'] = array();
				}

				self::$routes[self::$parent['key']]['children'][] = $route['key'];

				if(empty($_url_pattern))
				{
					$route['pattern'] = self::$parent['pattern'];
				}
				else
				{
					$route['pattern'] = self::$parent['pattern'] . "/" . $route['pattern'];
				}
			}

			// Add to queue
			self::$routes[] = $route;

			return $route['key'];
		}

		/**
		 *	Register subRoutes from within the Controller
		 */
		private static function addControllerRoutes($_parent_key)
		{
			$route = self::$routes[$_parent_key];

			if(!isset($route['controller']) || !class_exists($route['controller']) || !method_exists($route['controller'], "router") || /*(isset($route['parent']) &&*/ in_array($route['controller'], self::$mapped))
			{
				return;
			}

			$class = self::$routes[$_parent_key]['controller'];

			self::$mapped[] = $class;

			// Store current parent (May be null)
			$last_current = self::$current;

			self::$current = self::$routes[$_parent_key];

			self::getInstance()->group(fn($self) => $class::router($self));

			self::$current = $last_current;

			return $_parent_key;
		}

		public static function bindControllerRoutes()
		{
			if(self::$has_mapped)
			{
				return;
			}

			array_walk_recursive(self::$map, fn($key) => self::addControllerRoutes(self::$routes[$key]['key']));

			self::$has_mapped = true;
		}		

		private static function getCurrent(): array
		{
			return self::$routes[self::getCurrentKey()];
		}

		private static function getCurrentKey(): int
		{
			if(self::$current)
			{
				return self::$current['key'];
			}

			return self::getLastKey();
		}

		private static function getLastKey(): int
		{
			return count(self::$routes) - 1;
		}

		/**
		 *
		 */
		public function alias($_alias): self
		{
			self::$routes[self::$current['key']]['alias'] = $_alias;

			self::$relations[$_alias] = self::$current['key'];

			return $this->getInstance();
		}

		public static function get($_url_pattern, $_caller): self
		{
			return self::map($_url_pattern, $_caller, Request::METHOD_GET);
		}

		public static function post($_url_pattern, $_caller): self
		{
			return self::map($_url_pattern, $_caller, Request::METHOD_POST);
		}

		public static function ajax($_url_pattern, $_caller): self
		{
			return self::map($_url_pattern, $_caller, Request::METHOD_AJAX);
		}

		public static function any($_methods, $_url_pattern, $_caller): self
		{
			$key = self::add($_url_pattern, $_caller);

			$_methods = (empty(!$_methods)) ? $_methods : array(Request::METHOD_GET, Request::METHOD_POST);

			foreach($_methods AS $method)
			{
				if(!isset(self::$map[$method]))
				{
					self::$map[$method] = array();
				}

				self::$map[$method][] = $key;
			}

			return self::getInstance();
		}

		private static function map($_url_pattern, $_caller, $_method): self
		{
			$key = self::add($_url_pattern, $_caller);

			if(!isset(self::$map[$_method]))
			{
				self::$map[$_method] = array();
			}

			self::$map[$_method][] = $key;

			self::$current = self::$routes[$key];

			return self::getInstance();
		}

		public function group($_callback): self
		{
			$last_current = self::$current;
			$last_parent = self::$parent;

			self::$parent = $last_current;

			$_callback(self::getInstance());

			self::$current = $last_current; // go back
			self::$parent = $last_parent;

			return self::getInstance();
		}

		/**
		 *
		 */
		public function require(array $_inputs): self
		{
			self::$routes[self::getLastKey()]['required_parameters'] = array();

			foreach($_inputs AS $key => $value)
			{
				self::$routes[self::getLastKey()]['required_parameters'][] = (is_numeric($key)) ? $value : $key;

				if(!is_numeric($key))
				{
					self::$routes[self::getLastKey()]['required_specific_parameters'][$key] = $value;
				}
			}

			return self::getInstance();
		}

		private static function parsePattern($_pattern): string
		{
	        // Convert the route to a regular expression: escape forward slashes
	        $_pattern = preg_replace('/\//', '\\/', $_pattern);

	        // Convert variables e.g. {controller} (Allows %+. for urlencoded strings)
	        $_pattern = preg_replace('/\{([%a-z_]+)\}/', '(?P<\1>[%a-z0-9-.]+)', $_pattern);

	        // Convert variables with custom regular expressions e.g. {id:\d+}
	      	//  $_pattern = preg_replace('/\{([a-z]+):([^\}]+)\}/', '(?P<\1>\2)', $_pattern);
	        $_pattern = preg_replace('/\{([a-z]+):([^\']+)\}/', '(?P<\1>\2)', $_pattern);

	        // Add start and end delimiters, and case insensitive flag
	      	return '/^' . $_pattern . '$/i';
		}

		/**
		 *
		 */
		public static function compile($_route): array | Response | Redirect | View
		{
			// If the route doesnt have any controller, get it from the parent
			if(isset($_route['parent']) && !empty($_route['parent']))
			{
				$parent = self::$routes[$_route['parent']];
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
			$_route['controller'] = new $_route['controller']($_route, self::getInstance()->request);

			/**
			 *	Prepare Nodes to be used
			 */
            $_route['controller']->first();

			/**
			 *	Check Middleware
			 */
			$middleware = $_route['controller']->middleware($_route['action'] ?? null, $_route['parameters'] ?? array());

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
		 * @return void
		 */
		public static function dispatch(Request $_request): array
		{
			if(!$route_array = self::match($_request->path(), $_request->getMethod(), $_request->ajax()))
			{
				throw new Exception('No route matched', 404);
			}

			return self::$current = $route_array;
		}

		/**
		 * Match the route to the routes in the routing table, setting the $params
		 * property if a route is found.
		 *
		 * @param string $_url The route URL
		 *
		 * @return boolean  true if a match found, false otherwise
		 */
		public static function match($_url, $_method = "GET", $_is_ajax_request = false): bool | array
		{
			/**
			 *	Map all children routes, now when the rest is done
			 */
			self::bindControllerRoutes();

			if(!isset(self::$map[$_method]) && ($_is_ajax_request && !isset(self::$map[Request::METHOD_AJAX])))
			{
				return false;
			}

			$maps = (isset(self::$map[$_method])) ? array($_method => self::$map[$_method]) : array();
			$maps += ($_is_ajax_request && isset(self::$map[Request::METHOD_AJAX])) ? array(Request::METHOD_AJAX => self::$map[Request::METHOD_AJAX]) : array();
			$maps = array_reverse($maps); // Ajax first

			foreach($maps AS $map_group => $map_keys)
			{
				// Prioritizes routes from Controllers (Based on if having requirements)
				$routes = array_combine($map_keys, array_map(fn($route_key) => self::$routes[$route_key], $map_keys));
				$routes = array_filter($routes, fn($r) => !empty($r['pattern'])); // Remove all empty pattern-routes (Probably wrong because of merge)

				if(empty($routes))
				{
					continue;
				}

				// Prioritize routes with parameter requirements
				if($routes_w_requirements = array_filter($routes, fn($r) => isset($r['required_parameters'])))
				{
					$routes = $routes_w_requirements + array_filter($routes, fn($r) => !isset($r['required_parameters']));
				}

				// Prioritize routes with pattern-fallback {} to be last
				if($routes_w_fallbacks = array_filter($routes, fn($r) => $r['pattern'][0] == "{"))
				{
					$routes = array_filter($routes, fn($r) => $r['pattern'][0] != "{") + $routes_w_fallbacks;
				}

				foreach($routes AS $route_key => $route)
				{
					$pattern = self::parsePattern($route['pattern']);

					if(!in_array($route['key'], $map_keys) || !preg_match($pattern, $_url, $matches))
					{
						continue;
					}
					elseif(isset($route['required_parameters']) && array_diff($route['required_parameters'], array_keys(self::getInstance()->request->all())))
					{
						continue;
					}
					elseif(isset($route['required_specific_parameters']))
					{
						$findings = array_filter($route['required_specific_parameters'], fn($v, $k) => (self::getInstance()->request->all()[$k] == $v), ARRAY_FILTER_USE_BOTH);

						if(count($findings) < count($route['required_specific_parameters']))
						{
							continue;
						}
					}

					// If we captured any value from e.g {product_id}, store the catch for this matched route ['parameters' => ['product_id' => {product_id}]]
					if($params = array_map(fn($m) => $m, array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY)))
					{
						self::$routes[$route_key]['parameters'] = (isset(self::$routes[$route_key]['parameters'])) ? array_merge(self::$routes[$route_key]['parameters'], $params) : $params;
					}

					return self::$current_matched = self::$routes[$route_key];
				}
			}

			return false;
		}

		public static function getCurrentMatched(): array | bool
		{
			return self::$current_matched ?? false;
		}

		private static function getNamespace(): string
		{
			return self::$namespace;
		}

		public function setNamespace($_namespace): string
		{
			return self::$namespace = rtrim($_namespace, "\\") . "\\";
		}

		/**
		 *
		 */
		public static function url($_to_alias, $_arguments = null, $_absolute = true): string
		{
			// Search Database-routes
			if(is_numeric($_to_alias) && !isset(self::$db_relations[$_to_alias]) && !isset(self::$relations[$_to_alias]))
			{
				throw new Exception("No RouteAliasId found (" . $_to_alias . ")");
			}
			elseif(!is_numeric($_to_alias) && !isset(self::$relations[$_to_alias]))
			{
				throw new Exception("No RouteAlias found (" . $_to_alias . ")");
			}

			// Fallback, Route probably deleted from LCMS
			if(!$url = (is_numeric($_to_alias) && isset(self::$db_relations[$_to_alias], self::$routes[self::$db_relations[$_to_alias]])) ? self::$routes[self::$db_relations[$_to_alias]]['pattern'] : self::$routes[self::$relations[$_to_alias]]['pattern'] ?? false)
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

			// Construct URL
			$url_parts = array();

			if(!Locale::isDefault())
			{
				$url_parts[] = Locale::getLanguage();
			}
			
			if(!empty($url) && $url != "/")
			{
				$url_parts[] = trim(strtolower($url));
			}
			
			$url = rtrim("/" . implode("/", $url_parts), "/");

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
		public static function getRouteFromUrl($_url)
		{
			return self::match(ltrim(parse_url($_url)['path'], "/"), "GET", false, false);
		}

		public static function asItem($_alias)
		{
			if(!isset(self::$relations[$_alias]))
			{
				throw new Exception("No RouteAlias found: " . $_alias);
			}

			return self::$routes[self::$relations[$_alias]];
		}

		// Pair everything as a tree based on parent/children
		public function asTree($_strict = true)
		{
			// Root
			$root = array();

			foreach(self::$routes AS $r)
			{
				if(isset($r['parent']) || ($_strict && !in_array($r['key'], self::$map[Request::METHOD_GET])))
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
				return $this->asTreeAliases(self::$routes[$route['parent']], $aliases);
			}

			return $aliases;
		}

		private function recursiveChildren($self)
		{
			if(!isset($self['children']))
			{
				return $self;
			}

			$children = array();

			foreach($self['children'] AS $key)
			{
				$route = self::$routes[$key];

				if(!in_array($route['key'], self::$map[Request::METHOD_GET]))
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
			return self::$routes;
		}

		public function merge($_routes): self
		{
			foreach($_routes AS $k => $r)
			{
				if(isset($r['settings'], $r['settings'][Locale::getLanguage()], $r['settings'][Locale::getLanguage()]['disabled']) && $r['settings'][Locale::getLanguage()]['disabled']['value'])
				{
					// Remove this route from the mapping too
					foreach(array_filter(self::$map, fn($keys) => in_array($k, $keys)) AS $map => $keys)
					{
						unset(self::$map[$map][array_search($k, $keys)]);
					}

					//unset(self::$routes[$k]);
					//continue;
				}
				elseif(!isset($r['key']) && !in_array($k, self::$map[Request::METHOD_GET]))
				{
					self::$map[Request::METHOD_GET][] = $k;
				}

				if(isset($r['id']))
				{
					self::$db_relations[$r['id']] = $k;
				}

				self::$routes[$k] = (isset(self::$routes[$k])) ? array_merge(self::$routes[$k], $r) : $r;

				if(!isset(self::$routes[$k]['key']))
				{
					self::$routes[$k]['key'] = $k;
				}

				if(!empty($r['alias']) && !isset(self::$relations[$r['alias']]))
				{
					self::$relations[$r['alias']] = $k;
				}

				if(isset($r['parent_id']) && !empty($r['parent_id']) && !isset(self::$routes[$k]['parent']))
				{
					self::$routes[$k]['parent'] = self::$db_relations[$r['parent_id']];
				}
			}

			return self::$instance;
		}
	}
?>