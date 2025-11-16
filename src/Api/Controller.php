<?php
	/**
	 * LCMS API Base Controller with HMAC Signature Authentication
	 *
	 * This controller provides a secure base for API endpoints that receive requests
	 * from the LCMS CMS. It validates requests using HMAC-SHA256 signatures instead
	 * of IP allowlists, providing cryptographic security and replay protection.
	 *
	 * Security Features:
	 * - HMAC-SHA256 signature validation
	 * - Timestamp-based replay protection (5 minute window)
	 * - Timing-safe signature comparison
	 * - No IP dependency
	 *
	 * Usage:
	 * ```php
	 * class Api extends LCMS\Api\Controller {
	 *     // Inherits all standard methods and security
	 *     // Override methods as needed
	 * }
	 * ```
	 */
	namespace LCMS\Api;

	use LCMS\Controller as BaseController;
	use LCMS\Core\Route;
	use LCMS\Core\Response;
	use LCMS\Core\Redirect;
	use LCMS\Core\Request;
	use LCMS\Core\Env;
	use LCMS\Core\Node;
	use LCMS\Core\Navigations;
	use LCMS\Core\Locale;
	use LCMS\Core\View;
	use Exception;
	use Closure;

	abstract class Controller extends BaseController
	{
		protected string $api_secret;
		protected int $hmac_timestamp_tolerance = 300; // 5 minutes in seconds
		protected array $excludedControllers = ['Error', 'Api', 'Shortcut', 'Home'];
		protected array $excludedEndpoints = ['404', '500', 'sitemap.xml', 'robots.txt'];

		/**
		 * Constructor
		 *
		 * @param Request $request The incoming request object
		 * @throws Exception If LCMS API secret is not configured
		 */
		public function __construct(
			private Request $request
		)
		{
			// Get API secret from environment
			// This should match the api_key stored in the CMS database
			$this->api_secret = Env::get('lcms_api_key');

			if (!$this->api_secret) {
				throw new Exception('LCMS API secret not configured (lcms_api_key)');
			}
		}

		/**
		 * Middleware that selectively validates HMAC signature
		 *
		 * This method is automatically called before any controller action.
		 * It validates HMAC for CMS API methods (methods starting with "get").
		 * Public methods (Sitemap, Robots, Redirect, etc.) bypass authentication.
		 *
		 * @return bool|Response True if validation passes, Response with error if fails
		 */
		public function middleware(): bool|Response
		{
			// Determine which action is being called from the request path
			$path = trim($this->request->path(), '/');
			$pathParts = explode('/', $path);
			$endpoint = end($pathParts); // Last part of path (e.g., "getroutes", "sitemap.xml")

		// Check if this is a protected CMS API method
		if ($this->isProtectedMethod($endpoint)) {
			try {
				$this->validateHmacSignature();
				return true;
			} catch(Exception $e) {
				http_response_code(401);
				return Response::json(['error' => $e->getMessage()]);
			}
		}
			// Public endpoints - no authentication required
			return true;
		}

		/**
		 * Check if an endpoint is a protected CMS API method
		 *
		 * Uses reflection to check if a public method exists that matches the endpoint
		 * and starts with "get" (case-insensitive).
		 *
		 * @param string $endpoint The endpoint from the URL (e.g., "getroutes", "get-routes")
		 * @return bool True if this is a protected method requiring HMAC
		 */
		protected function isProtectedMethod(string $endpoint): bool
		{
			// Get all public methods using reflection
			$reflection = new \ReflectionClass($this);
			$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

			// Filter to only methods starting with "Get" and map to lowercase
			$protectedMethods = array_map(
				'strtolower',
				array_filter(
					array_map(fn($m) => $m->getName(), $methods),
					fn($name) => str_starts_with($name, 'Get')
				)
			);

			// Check if endpoint matches any protected method
			return in_array(strtolower($endpoint), $protectedMethods);
		}

		/**
		 * Validates the HMAC signature from CMS requests
		 *
		 * This method implements the HMAC-SHA256 signature validation:
		 * 1. Extracts timestamp and signature from headers
		 * 2. Validates timestamp is within tolerance window
		 * 3. Rebuilds signature string from request data
		 * 4. Calculates expected signature
		 * 5. Compares signatures using timing-safe comparison
		 *
		 * @throws Exception If headers are missing, timestamp expired, or signature invalid
		 */
		protected function validateHmacSignature(): void
		{
			// Extract authentication headers
			$timestamp = $this->request->header('X-LCMS-Timestamp');
			$signature = $this->request->header('X-LCMS-Signature');

			if (!$timestamp || !$signature) {
				throw new Exception('Missing authentication headers (X-LCMS-Timestamp or X-LCMS-Signature)');
			}

			// Validate timestamp format and convert to unix timestamp
			$requestTime = strtotime($timestamp);
			if ($requestTime === false) {
				throw new Exception('Invalid timestamp format. Expected ISO 8601 format');
			}

			// Check timestamp is within tolerance window (prevent replay attacks)
			$timeDiff = abs(time() - $requestTime);
			if ($timeDiff > $this->hmac_timestamp_tolerance) {
				throw new Exception("Request expired. Timestamp is {$timeDiff} seconds old (max: {$this->hmac_timestamp_tolerance})");
			}

			// Build signature string (must match CMS format exactly)
			// Format: METHOD\nPATH\nTIMESTAMP\nBODY
			$method = strtoupper($this->request->getMethod());
			$path = $this->request->path();
			$body = $this->getRequestBody();

			$signatureString = "{$method}\n{$path}\n{$timestamp}\n{$body}";

		// Calculate expected signature using HMAC-SHA256 with our secret
		// The secret is from config, NOT from the request (security!)
		$expectedSignature = hash_hmac('sha256', $signatureString, $this->api_secret);
			// Timing-safe comparison to prevent timing attacks
			if (!hash_equals($expectedSignature, $signature)) {
				throw new Exception('Invalid signature. Request authentication failed');
			}

			// Optional: You could add additional validation here
			// For example, verify the project_id and api_key against a database
		}

		/**
		 * Gets the request body in the same format used for signature calculation
		 *
		 * For POST requests, this rebuilds the form data as a query string
		 * with sorted keys to ensure consistent ordering for signature validation.
		 *
		 * @return string The request body as a query string
		 */
		protected function getRequestBody(): string
		{
			if ($this->request->getMethod() === 'POST') {
				// Get all form parameters
				$params = $this->request->all();

				// Sort by key to ensure consistent ordering

				// Build query string
				return http_build_query($params);
			}

			return '';
		}

		/**
		 * Get base URL with trailing slash
		 *
		 * Uses the injected Request object to dynamically generate the base URL
		 * instead of relying on static Env configuration.
		 *
		 * @return string Base URL with trailing slash (e.g., "https://example.com/")
		 */
		protected function getBaseUrl(): string
		{
			return $this->request->root() . '/';
		}

		/**
		 * Standard router setup for LCMS API endpoints
		 *
		 * Defines the standard API endpoints that frontends expose for the CMS.
		 * Override this method if you need custom routing.
		 *
		 * @param Route $_route The route instance
		 */
		public static function router(Route $_route): void
		{
			// Protected CMS API endpoints (POST with HMAC authentication)
			$_route->post("getroutes", [static::class, "GetRoutes"]);
			$_route->post("getnavigations", [static::class, "GetNavigations"]);
			$_route->post("getenv", [static::class, "GetEnv"]);
			$_route->post("getconfig", [static::class, "GetConfig"]);

			// Public endpoints (GET, no authentication required)
			$_route->get("sitemap.xml", [static::class, "Sitemap"]);
			$_route->get("robots.txt", [static::class, "Robots"]);
		}

		/**
		 *  Default to not allow GET /api
		 */
		protected function Index()
		{
			throw new Exception("No API endpoint");
		}

		/**
		 * Get routes configuration
		 *
		 * Returns the route tree structure for the application.
		 *
		 * @return Response JSON response with route tree
		 */
		public function GetRoutes(): Response
		{
			return Response::json(Route::getInstance()->asTree());
		}

		/**
		 * Get navigations configuration
		 *
		 * Returns the navigation tree structure for the application.
		 *
		 * @return Response JSON response with navigation tree
		 */
		public function GetNavigations(): Response
		{
			return Response::json(Navigations::getInstance()->asTree());
		}

		/**
		 * Get environment configuration
		 *
		 * Returns all environment variables for the application.
		 *
		 * @return Response JSON response with environment data
		 */
		public function GetEnv(): Response
		{
			return Response::json(Env::getAll());
		}

		/**
		 * Get controller configuration
		 *
		 * Returns metadata about available controllers and actions.
		 *
		 * @return Response JSON response with controller configuration
		 */
		public function GetConfig(): Response
		{
			return Response::json($this->getControllersConfig());
		}

		/**
		 * Get controllers configuration via reflection
		 *
		 * Scans all controller files in the application, uses reflection to discover
		 * public methods that return View types, and extracts metadata from file headers.
		 *
		 * @return array Controller configuration with namespaces, actions, and metadata
		 */
		protected function getControllersConfig(): array
		{
			// Get routes for reference
			$routes = Route::getInstance()->asArray();

			$controllers = array();

			// Scan all PHP files in Controllers directory
			foreach(glob(__DIR__ . "/../../App/Controllers/*.php") AS $controller)
			{
				$controller_name = explode(".", basename($controller))[0];

				// Skip reserved controllers
				if(in_array($controller_name, $this->excludedControllers))
				{
					continue;
				}

				$controllers[$controller_name] = $controller;
			}

			if(empty($controllers))
			{
				return array('error' => "empty");
			}

			// Parse controllers using reflection
			foreach($controllers AS $identifier => $controller)
			{
				// Determine the namespace - child class will have correct namespace
				$class = (new \ReflectionClass($this))->getNamespaceName() . "\\" . $identifier;

				try {
					$c = new \ReflectionClass($class);
				} catch (\ReflectionException $e) {
					// Skip if class doesn't exist
					continue;
				}

				$controllers[$identifier] = array(
					'controller'	=> $identifier,
					'namespace'		=> $c->getNamespaceName()
				);

				// Scan file headers for metadata
				$headers = (new ControllerScanner($controller))->scan();

				if(!empty($headers))
				{
					$controllers[$identifier]['headers'] = $headers;
				}

				// Find all public methods that return View
				foreach($c->getMethods(\ReflectionMethod::IS_PUBLIC) AS $method)
				{
					if(empty($method->getReturnType()))
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

					// Only store methods with View returned
					if(!in_array("View", $parts))
					{
						continue;
					}

					if(!isset($controllers[$identifier]['actions']))
					{
						$controllers[$identifier]['actions'] = array();
					}

					$parameters = array();

					foreach($method->getParameters() AS $p)
					{
						$parameters[] = $p->name;
					}

					$controllers[$identifier]['actions'][$method->getName()] = $parameters;
				}
			}

			return array('controllers' => $controllers);
		}

		/**
		 * Generate sitemap from routes
		 *
		 * Automatically generates a sitemap from the application's route tree,
		 * respecting robots.txt disallows and multilingual patterns.
		 *
		 * @return string XML sitemap
		 */
		public function Sitemap(): string
		{
			$unallows = array('/api/');

			$items = array();

			if(($robots = Node::get("robots")) && str_contains($robots->text(), "Disallow: /"))
			{
				$unallows += array_map(fn($row) => explode(": ", $row)[1], array_filter(explode("\n", $robots->text()), fn($r) => str_starts_with($r, "Disallow: /")));
			}

			if($robots && str_contains($robots->text(), "Disallow: /\n"))
			{
				throw new Exception("Sitemap does not exist", 404);
			}

			$routes = Route::getInstance()->asTree(false);

			foreach($this->recursiveSitemapRouteParser($routes, array(), $unallows ?? null) AS $page)
			{
				$items[] = array($page[0], $page[1] ?? null, $page[2] ?? null);
			}

			return $this->generateSitemap($items);
		}

		/**
		 * Generate robots.txt
		 *
		 * Reads robots.txt content from Node storage, cleans it up,
		 * and adds sitemap references.
		 *
		 * Override this method to add custom sitemap URLs.
		 *
		 * @return View|string Robots.txt content
		 */
		public function Robots(): View | string
		{
			if(!$robots = Node::get("robots"))
			{
				throw new Exception("Robots.txt does not exist", 404);
			}

			/**
			 *  Remove all "Noindex" since it should be represented as a <meta>-tag + remove double \n
			 * 	@https://developers.google.com/search/docs/advanced/crawling/block-indexing
			 */
			$robots = preg_replace("/\n\s*\n\s*\n/", "\n\n", preg_replace('/Noindex:[\s\S]+?\n/', '', $robots->text()));

			// Fix languages URL's
			$robots = str_replace(" /", " " . $this->getBaseUrl(), $robots);
			$robots = str_replace("Allow: " . $this->getBaseUrl(), "Allow: /", $robots);

			// Add sitemaps (base implementation - override to add more)
			$robots .= $this->getRobotsSitemaps();

			// Set content-type
			$this->request->headers->set("Content-type", "text/plain");

			return str_replace("\n", "\r", $robots);
		}

		/**
		 * Get sitemap URLs for robots.txt
		 *
		 * Override this method to add custom sitemap URLs.
		 *
		 * @return string Sitemap URLs
		 */
		protected function getRobotsSitemaps(): string
		{
			return "\nSitemap: " . $this->getBaseUrl() . "sitemap.xml";
		}

		/**
		 * Redirect helper
		 *
		 * @param string $_to Route name or URL
		 * @return Redirect Redirect response
		 */
		public function Redirect($_to): Redirect
		{
			return Redirect::to(Route::url($_to));
		}

		/**
		 * Recursively parse routes for sitemap generation
		 *
		 * @param array $routes Route tree
		 * @param array $return Accumulated results
		 * @param array|null $unallows URLs to exclude
		 * @return array Sitemap entries
		 */
		protected function recursiveSitemapRouteParser($routes, $return = array(), ?array $unallows = null): array
		{
			foreach(array_filter($routes, fn($r) => isset($r['org_pattern'])) AS $route)
			{
				// As last check, make sure the controller returns a View
				if(!$returnType = (new \ReflectionClass($route['controller']))->getMethod($route['action'])->getReturnType())
				{
					continue;
				}

				if($returnType instanceof \ReflectionUnionType)
				{
					$parts = array_unique(array_merge(...array_map(fn($type) => explode("\\", $type->getName()), $returnType->getTypes())));
				}
				else
				{
					$parts = explode("\\", $returnType->getName());
				}

				if(!in_array("View", $parts))
				{
					continue;
				}

				// Multilangual support
				$patterns = $route['org_pattern'] ?? array($route['pattern']);
				unset($patterns["*"]);

				if($route['pattern'] == "/")
				{
					$patterns[] = "/";
				}

				// Remove disabled patterns (Exclude startpage)
				$patterns = array_filter($patterns, fn($lang) => !($route['settings'][$lang]['disabled']['value'] ?? false) || $route['pattern'] == "/", ARRAY_FILTER_USE_KEY);

				if(empty($patterns))
				{
					continue;
				}

				foreach($patterns AS $lang => $pattern)
				{
					// If pattern contains "{" it means we have a "catch-all"-pattern which is not allowed in sitemap automatically (Lastly, check if excluded)
					if(str_contains($pattern, "{") || in_array($pattern, $this->excludedEndpoints)
						|| ($unallows && in_array("/" . $pattern, $unallows))
						|| ($unallows && (in_array($pattern, $unallows) || count(array_filter(explode("/", $pattern), fn($part) => in_array("/" . $part . "/", $unallows))) > 0))
						|| ($route['settings'][$lang]['disabled']['value'] ?? false)) // Disabled from LCMS
					{
						continue;
					}

					if(count($patterns) > 1 && $siblings_urls = array_combine(array_keys($patterns), array_fill(0, count($patterns), $patterns)))
					{
						if($default = $siblings_urls[Locale::getLanguage()] ?? false)
						{
							unset($siblings_urls[Locale::getLanguage()]);
							$siblings_urls = array(Locale::getLanguage() => $default) + $siblings_urls;
						}

						array_walk_recursive($siblings_urls, fn(&$pattern, $lang) => ($pattern = $this->getBaseUrl() . (($lang == Locale::getLanguage()) ? "" : $lang . "/") . $pattern));

						foreach($siblings_urls AS $sibling_patterns)
						{
							foreach($sibling_patterns AS $lang => $url)
							{
								$return[$url] = array($url, $route['updated_at'] ?? $route['created'] ?? null, array_filter($sibling_patterns, fn($lng) => $lng != $lang, ARRAY_FILTER_USE_KEY));
							}
						}
					}
					else
					{
						$url = $this->getBaseUrl() . (($pattern == "/") ? "" : (($lang == Locale::getLanguage()) ? "" : $lang . "/") . $pattern);
						$url = rtrim($url, "/");
						$return[$url] = array($url, $route['updated_at'] ?? $route['created'] ?? null);
					}
				}

				if(isset($route['children']))
				{
					$return += $this->recursiveSitemapRouteParser($route['children'], $return, $unallows);
				}
			}

			return $return;
		}

		/**
		 * Generate sitemap XML
		 *
		 * @param array $items Sitemap items
		 * @param Closure|null $item_callback Optional callback to transform items
		 * @return string XML sitemap
		 */
		protected function generateSitemap($items, ?Closure $item_callback = null): string
		{
			$sitemap = "";

			foreach($items AS $item)
			{
				if($item_callback)
				{
					$item = $item_callback($item);
				}

				$sitemap .= "<url><loc>".$item[0]."</loc>";

				if(isset($item[2]) && is_array($item[2]))
				{
					foreach($item[2] AS $language => $sibling_url)
					{
						$sitemap .= "<xhtml:link rel='alternate' hreflang='".$language."' href='".$sibling_url."' />";
					}
				}

				$sitemap .= ((isset($item[1]) && !empty($item[1])) ? "<lastmod>".date("Y-m-d", strtotime($item[1]))."</lastmod>" : "") . "</url>";
			}

			$this->request->headers->set("Content-type", "application/xml");

			return '<?xml version="1.0" encoding="UTF-8"?>
				<urlset
				xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
				xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
				xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
				http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd"
				xmlns:xhtml="http://www.w3.org/1999/xhtml">
			' . $sitemap . "</urlset>";
		}
	}

	/**
	 * Controller Scanner
	 *
	 * Scans controller files for metadata headers (WordPress-style).
	 * Reads the first 8KB of a file looking for specially formatted comments.
	 */
	class ControllerScanner
	{
		private $controller;
		private $file_headers = array(
			'Name'        => 'Theme Name',
			'ThemeURI'    => 'Theme URI',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Version'     => 'Version',
			'Template'    => 'Template',
			'Status'      => 'Status',
			'Tags'        => 'Tags',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path'
		);

		function __construct($_controller)
		{
			if(!is_file($_controller))
			{
				throw new Exception($_controller . " is not a file");
			}

			$this->controller = $_controller;
		}

		/**
		 * Retrieve metadata from a file.
		 *
		 * Searches for metadata in the first 8kiB of a file, such as a plugin or theme.
		 * Each piece of metadata must be on its own line. Fields can not span multiple
		 * lines, the value will get cut at the end of the first line.
		 *
		 * If the file data is not within that first 8kiB, then the author should correct
		 * their plugin file and move the data headers to the top.
		 *
		 * @return array File headers found
		 */
		public function scan(): array
		{
			$fp 			= fopen( $this->controller, 'r' );
			$file_data 		= fread( $fp, 8192 );

			fclose($fp);

			$file_data 		= str_replace( "\r", "\n", $file_data );

			$headers = array();

			foreach($this->file_headers AS $field => $regex)
			{
				if(preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match) && $match[1])
				{
					$headers[$field] = trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $match[1]));
				}
			}

			return $headers;
		}
	}
?>