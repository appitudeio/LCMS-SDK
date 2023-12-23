<?php
	/**
	*
	*
	*	With heavy inspiration from
	* 	- https://github.com/illuminate/http/blob/master/Request.php
	*	- https://github.com/symfony/http-foundation/blob/master/Request.php
	*	- https://github.com/symfony/http-foundation/blob/7.0/HeaderUtils.php
	*
	*	Update 2023-01-05: #2614 (Removed usage of Arr::flatten so arrays can be used in Request->get)
	*		   2023-12-23: Better Typing + proxy fixes (+HeaderUtils, +IpUtils)
	*/
	namespace LCMS\Core;

	use LCMS\Core\File;
	use LCMS\Util\Singleton;
	use LCMS\Util\Arr;
	use \Exception;
	use \SplFileInfo;

	class Request
	{
		use InteractsWithInput, Singleton;

		const HEADER_FORWARDED = 0b000001; // When using RFC 7239
		const HEADER_X_FORWARDED_FOR = 0b000010;
		const HEADER_X_FORWARDED_HOST = 0b000100;
		const HEADER_X_FORWARDED_PROTO = 0b001000;
		const HEADER_X_FORWARDED_PORT = 0b010000;
		const HEADER_X_FORWARDED_PREFIX = 0b100000;
	
		const HEADER_X_FORWARDED_AWS_ELB = 0b0011010; // AWS ELB doesn't send X-Forwarded-Host
		const HEADER_X_FORWARDED_TRAEFIK = 0b0111110; // All "X-Forwarded-*" headers sent by Traefik reverse proxy

		const METHOD_HEAD 		= "HEAD";
		const METHOD_GET 		= "GET";
		const METHOD_POST 		= "POST";
		const METHOD_PUT 		= "PUT";
		const METHOD_PATCH 		= "PATCH";
		const METHOD_DELETE 	= "DELETE";
		const METHOD_PURGE 		= "PURGE";
		const METHOD_OPTIONS 	= "OPTIONS";
		const METHOD_TRACE 		= "TRACE";
		const METHOD_CONNECT 	= "CONNECT";
		const METHOD_AJAX 		= "AJAX";

		public InputBag 	$request;
		public InputBag 	$query;
		public ParameterBag $attributes;
		public CookieBag 	$cookies;
		public SessionBag 	$session;
		public FileBag 		$files;
		public ServerBag 	$server;
		public HeaderBag 	$headers;
		public mixed 		$content;

        private ?string 	$languages 	= null;
        private ?string 	$pathInfo 	= null;
        private ?string 	$requestUri = null;
        private ?string 	$baseUrl 	= null;
        private ?string 	$basePath	= null;
        private ?string 	$method 	= null;
        private array 		$trustedProxies = [];
		private array		$trustedHostPatterns = [];
        private bool 		$httpMethodParameterOverride = false;
		private int 		$trustedHeaderSet = -1;
		private array 		$trustedHosts = [];
		private bool 		$isForwardedValid = true;
		private bool 		$isHostValid = true;
		private array 		$trustedValuesCache = [];
		protected array 	$convertedFiles = [];

		private const FORWARDED_PARAMS = [
			self::HEADER_X_FORWARDED_FOR => 'for',
			self::HEADER_X_FORWARDED_HOST => 'host',
			self::HEADER_X_FORWARDED_PROTO => 'proto',
			self::HEADER_X_FORWARDED_PORT => 'host',
		];
	
		/**
		 * Names for headers that can be trusted when
		 * using trusted proxies.
		 *
		 * The FORWARDED header is the standard as of rfc7239.
		 *
		 * The other headers are non-standard, but widely used
		 * by popular reverse proxies (like Apache mod_proxy or Amazon EC2).
		 */
		private const TRUSTED_HEADERS = [
			self::HEADER_FORWARDED => 'FORWARDED',
			self::HEADER_X_FORWARDED_FOR => 'X_FORWARDED_FOR',
			self::HEADER_X_FORWARDED_HOST => 'X_FORWARDED_HOST',
			self::HEADER_X_FORWARDED_PROTO => 'X_FORWARDED_PROTO',
			self::HEADER_X_FORWARDED_PORT => 'X_FORWARDED_PORT',
			self::HEADER_X_FORWARDED_PREFIX => 'X_FORWARDED_PREFIX',
		];		

		/**
		 * Sets the parameters for this request.
		 *
		 * This method also re-initializes all properties.
		 *
		 * @param array                $query      The GET parameters
		 * @param array                $request    The POST parameters
		 * @param array                $attributes The request attributes (parameters parsed from the PATH_INFO, ...)
		 * @param array                $cookies    The COOKIE parameters
		 * @param array                $files      The FILES parameters
		 * @param array                $server     The SERVER parameters
		 * @param string|resource|null $content    The raw body data
		 */
		function __construct(array $query = null, array $request = null, array $attributes = null, array $cookies = null, array $files = null, array $server = null, $content = null)
		{
			$query 		= $query ?? $_GET;
			$request 	= $request ?? $_REQUEST;
			$attributes = (empty($attributes)) ? array() : $attributes;
			$cookies 	= $cookies ?? $_COOKIE;
			$files 		= $files ?? $_FILES;
			$server 	= $server ?? $_SERVER;

			$this->initialize($this, $query, $request, $attributes, $cookies, $files, $server, $content);
		}

		protected static function initialize(object $instance, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null): void
		{
			$instance->query = new InputBag($query);
			$instance->attributes = new ParameterBag($attributes);
			$instance->cookies = new CookieBag($cookies);
			$instance->files = new FileBag($files);
			$instance->server = new ServerBag($server);
			$instance->headers = new HeaderBag($instance->server->getHeaders());

			if(isset($_SESSION))
			{
				$instance->session = new SessionBag($_SESSION);
			}
	
			if($instance->headers->get('CONTENT_TYPE') && str_contains($instance->headers->get('CONTENT_TYPE'), 'application/x-www-form-urlencoded') && in_array(strtoupper($instance->server->get('REQUEST_METHOD', 'GET')), ['PUT', 'DELETE', 'PATCH']))
			{
				parse_str($instance->getContent(), $data);
				$instance->request = new InputBag($data);
			}
			else
			{
				$instance->request = new InputBag($request);
			}

			$instance->cookies->setDomain($instance->getHost());
			$instance->cookies->setSecure($instance->isSecure());

			self::$instance = $instance;
		}

		/**
		 *	Strip first part of the segment
		 */
		protected function setLanguage(string $_language): void
		{
			$this->appendUrl($_language);
		}

		protected function appendUrl(string $_string): void
		{
			$this->server->set('REQUEST_URI', str_replace("/" . $_string, "", $this->server->get("REQUEST_URI")));

			$this->pathInfo = null; // So the pathInfo gets re-used
			$this->requestUri = null;
		}

		protected function setUrl(string $_string): void
		{
			$this->server->set('REQUEST_URI', $_string);

			$this->pathInfo = null; // So the pathInfo gets re-used
			$this->requestUri = null;
		}

		/**
		 * Get the root URL for the application.
		 *
		 * @return string
		 */
		protected function root(): string
		{
			return rtrim($this->getSchemeAndHttpHost().$this->getBaseUrl(), '/');
		}

		/**
		 * Get the URL (no query string) for the request.
		 *
		 * @return string
		 */
		protected static function url(): string
		{
			return rtrim(preg_replace('/\?.*/', '', self::getInstance()->getUri()), "/");
		}

		/**
		 * Get the full URL for the request.
		 *
		 * @return string
		 */
		protected static function fullUrl(): string
		{
			$query = self::getInstance()->getQueryString();

			return self::getInstance()->root() . $query;
		}

		/**
		 * Get the full URL for the request with the added query string parameters.
		 *
		 * @param  array  $query
		 * @return string
		 */
		protected function fullUrlWithQuery(array $query): string
		{
			$question = $this->getBaseUrl().$this->getPathInfo() === '/' ? '/?' : '?';

			return count($this->query()) > 0
				? $this->url().$question.Arr::query(array_merge($this->query(), $query))
					: $this->fullUrl().$question.Arr::query($query);
		}

		/**
		 * Get the current path info for the request.
		 *
		 * @return string
		 */
		protected function path(): string
		{
			$pattern = trim($this->getPathInfo(), '/');

			return $pattern == "" ? "/" : $pattern;
		}

		/**
		 * Get all of the segments for the request path.
		 *
		 * @return array
		 */
		protected function segments(): array
		{
			$segments = explode('/', $this->decodedPath());

			return array_values(array_filter($segments, fn($value) => $value !== ""));
		}

		/**
		 * Get the current decoded path info for the request.
		 *
		 * @return string
		 */
		protected function decodedPath(): string
		{
			return rawurldecode($this->path());
		}

		/**
		 * Gets the request "intended" method.
		 *
		 * If the X-HTTP-Method-Override header is set, and if the method is a POST,
		 * then it is used to determine the "real" intended HTTP method.
		 *
		 * The _method request parameter can also be used to determine the HTTP method,
		 * but only if enableHttpMethodParameterOverride() has been called.
		 *
		 * The method is always an uppercased string.
		 *
		 * @return string The request method
		 *
		 * @see getRealMethod()
		 */
		protected function getMethod(): string
		{
			if (null !== $this->method)
			{
				return $this->method;
			}

			$this->method = strtoupper($this->server->get('REQUEST_METHOD', 'GET'));

			if ('POST' !== $this->method)
			{
				return $this->method;
			}

			$method = $this->headers->get('X-HTTP-METHOD-OVERRIDE');

			if (!$method && $this->httpMethodParameterOverride)
			{
				$method = $this->request->get('_method', $this->query->get('_method', 'POST'));
			}

			if (!is_string($method))
			{
				return $this->method;
			}

			$method = strtoupper($method);

			if (in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'PATCH', 'PURGE', 'TRACE'], true))
			{
				return $this->method = $method;
			}

			if (!preg_match('/^[A-Z]++$/D', $method))
			{
				throw new Exception(sprintf('Invalid method override "%s".', $method));
			}

			return $this->method = $method;
		}

		/**
		 * Gets the "real" request method.
		 *
		 * @return string The request method
		 *
		 * @see getMethod()
		 */
		protected function getRealMethod(): string
		{
			return strtoupper($this->server->get('REQUEST_METHOD', 'GET'));
		}

		/**
		 * Checks if the request method is of specified type.
		 *
		 * @param string $method Uppercase request method (GET, POST etc)
		 *
		 * @return bool
		 */
		protected function isMethod(string $method): string
		{
			return $this->getMethod() === strtoupper($method);
		}

		/**
		 * Returns the request body content.
		 *
		 * @param bool $asResource If true, a resource will be returned
		 *
		 * @return string|resource The request body content or a resource to read the body stream
		 *
		 * @throws \LogicException
		 */
		protected function getContent(bool $asResource = false): mixed
		{
			$currentContentIsResource = is_resource($this->content);

			if (true === $asResource)
			{
				if ($currentContentIsResource)
				{
					rewind($this->content);

					return $this->content;
				}

				// Content passed in parameter (test)
				if (is_string($this->content))
				{
					$resource = fopen('php://temp', 'r+');
					fwrite($resource, $this->content);
					rewind($resource);

					return $resource;
				}

				$this->content = false;

				return fopen('php://input', 'rb');
			}

			if ($currentContentIsResource)
			{
				rewind($this->content);

				return stream_get_contents($this->content);
			}

			if (null === $this->content || false === $this->content)
			{
				$this->content = file_get_contents('php://input');
			}

			return $this->content;
		}

		/**
		 * Get the input source for the request.
		 *
		 * @return \Symfony\Component\HttpFoundation\ParameterBag
		 */
		protected function getInputSource(): mixed
		{
			/*if ($this->isJson())
			{
				return $this->json();
			}*/

			return in_array($this->getRealMethod(), ['GET', 'HEAD']) ? $this->query : $this->request;
		}

		/**
		 * Returns the root URL from which this request is executed.
		 *
		 * The base URL never ends with a /.
		 *
		 * This is similar to getBasePath(), except that it also includes the
		 * script filename (e.g. index.php) if one exists.
		 *
		 * @return string The raw URL (i.e. not urldecoded)
		 */
		protected function getBaseUrl(): string
		{
			if (null === $this->baseUrl)
			{
				$this->baseUrl = $this->prepareBaseUrl();
			}

			return $this->baseUrl;
		}

		/**
		 * Generates a normalized URI (URL) for the Request.
		 *
		 * @return string A normalized URI (URL) for the Request
		 *
		 * @see getQueryString()
		 */
		protected function getUri(): string
		{
			if (null !== $qs = $this->getQueryString())
			{
				$qs = '?'.$qs;
			}

			return $this->getSchemeAndHttpHost().$this->getBaseUrl().$this->getPathInfo().$qs;
		}

		/**
		 * Returns the path being requested relative to the executed script.
		 *
		 * The path info always starts with a /.
		 *
		 * Suppose this request is instantiated from /mysite on localhost:
		 *
		 *  * http://localhost/mysite              returns an empty string
		 *  * http://localhost/mysite/about        returns '/about'
		 *  * http://localhost/mysite/enco%20ded   returns '/enco%20ded'
		 *  * http://localhost/mysite/about?var=1  returns '/about'
		 *
		 * @return string The raw path (i.e. not urldecoded)
		 */
		protected function getPathInfo(): string
		{
			if (null === $this->pathInfo)
			{
				$this->pathInfo = $this->preparePathInfo();
			}

			return $this->pathInfo;
		}

		protected function resetPathInfo(): void
		{
			$this->pathInfo = null;
			$this->requestUri = null;
		}

		/**
		 * Generates the normalized query string for the Request.
		 *
		 * It builds a normalized query string, where keys/value pairs are alphabetized
		 * and have consistent escaping.
		 *
		 * @return string|null A normalized query string for the Request
		 */
		protected function getQueryString(): ?string
		{
			$qs = static::normalizeQueryString($this->server->get('QUERY_STRING'));

			return '' === $qs ? null : $qs;
		}

		/**
		 * Normalizes a query string.
		 *
		 * It builds a normalized query string, where keys/value pairs are alphabetized,
		 * have consistent escaping and unneeded delimiters are removed.
		 *
		 * @return string A normalized query string for the Request
		 */
		protected static function normalizeQueryString(?string $qs): string
		{
			if ('' === ($qs ?? ''))
			{
				return '';
			}

			parse_str($qs, $qs);
			ksort($qs);

			return http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
		}

		/**
		 * Prepares the path info.
		 *
		 * @return string path info
		 */
		protected function preparePathInfo(): string
		{
			if (null === ($requestUri = $this->getRequestUri()))
			{
				return '/';
			}

			// Remove the query string from REQUEST_URI
			if (false !== $pos = strpos($requestUri, '?'))
			{
				$requestUri = substr($requestUri, 0, $pos);
			}

			if ('' !== $requestUri && '/' !== $requestUri[0])
			{
				$requestUri = '/'.$requestUri;
			}

			if (null === ($baseUrl = $this->getBaseUrl()))
			{
				return $requestUri;
			}

			$pathInfo = substr($requestUri, strlen($baseUrl));

			if (false === $pathInfo || '' === $pathInfo)
			{
				// If substr() returns false then PATH_INFO is set to an empty string
				return '/';
			}

			return (string) $pathInfo;
		}

		/**
		 * The following methods are derived from code of the Zend Framework (1.10dev - 2010-01-24)
		 *
		 * Code subject to the new BSD license (https://framework.zend.com/license).
		 *
		 * Copyright (c) 2005-2010 Zend Technologies USA Inc. (https://www.zend.com/)
		 */
		protected function prepareRequestUri(): string
		{
			$requestUri = '';

			if ('1' == $this->server->get('IIS_WasUrlRewritten') && '' != $this->server->get('UNENCODED_URL'))
			{
				// IIS7 with URL Rewrite: make sure we get the unencoded URL (double slash problem)
				$requestUri = $this->server->get('UNENCODED_URL');
				$this->server->remove('UNENCODED_URL');
				$this->server->remove('IIS_WasUrlRewritten');
			}
			elseif ($this->server->has('REQUEST_URI'))
			{
				$requestUri = (string) $this->server->get('REQUEST_URI');
	
				if ('' !== $requestUri && '/' === $requestUri[0])
				{
					// To only use path and query remove the fragment.
					if (false !== $pos = strpos($requestUri, '#'))
					{
						$requestUri = substr($requestUri, 0, $pos);
					}
				}
				else
				{
					// HTTP proxy reqs setup request URI with scheme and host [and port] + the URL path,
					// only use URL path.
					$uriComponents = parse_url($requestUri);

					if (isset($uriComponents['path']))
					{
						$requestUri = $uriComponents['path'];
					}

					if (isset($uriComponents['query']))
					{
						$requestUri .= '?'.$uriComponents['query'];
					}
				}
			}
			elseif ($this->server->has('ORIG_PATH_INFO'))
			{
				// IIS 5.0, PHP as CGI
				$requestUri = $this->server->get('ORIG_PATH_INFO');

				if ('' != $this->server->get('QUERY_STRING'))
				{
					$requestUri .= '?'.$this->server->get('QUERY_STRING');
				}

				$this->server->remove('ORIG_PATH_INFO');
			}

			// normalize the request URI to ease creating sub-requests from this request
			$this->server->set('REQUEST_URI', $requestUri);

			return $requestUri;
		}

		protected function resetUri(): void
		{
			$this->requestUri = null;
		}

		/**
		 * Prepares the base URL.
		 *
		 * @return string
		 */
		protected function prepareBaseUrl(): string
		{
			$filename = basename($this->server->get('SCRIPT_FILENAME'));

			if (basename($this->server->get('SCRIPT_NAME')) === $filename)
			{
				$baseUrl = $this->server->get('SCRIPT_NAME');
			}
			elseif (basename($this->server->get('PHP_SELF')) === $filename)
			{
				$baseUrl = $this->server->get('PHP_SELF');
			}
			elseif (basename($this->server->get('ORIG_SCRIPT_NAME')) === $filename)
			{
				$baseUrl = $this->server->get('ORIG_SCRIPT_NAME'); // 1and1 shared hosting compatibility
			}
			else // Backtrack up the script_filename to find the portion matching
			{
				// php_self
				$path = $this->server->get('PHP_SELF', '');
				$file = $this->server->get('SCRIPT_FILENAME', '');
				$segs = explode('/', trim($file, '/'));
				$segs = array_reverse($segs);
				$index = 0;
				$last = count($segs);
				$baseUrl = '';

				do
				{
					$seg = $segs[$index];
					$baseUrl = '/'.$seg.$baseUrl;
					++$index;
				} while ($last > $index && (false !== $pos = strpos($path, $baseUrl)) && 0 != $pos);
			}

			// Does the baseUrl have anything in common with the request_uri?
			$requestUri = $this->getRequestUri();

			if ('' !== $requestUri && '/' !== $requestUri[0])
			{
				$requestUri = '/'.$requestUri;
			}

			if ($baseUrl && null !== $prefix = $this->getUrlencodedPrefix($requestUri, $baseUrl))
			{
				// full $baseUrl matches
				return $prefix;
			}

			if ($baseUrl && null !== $prefix = $this->getUrlencodedPrefix($requestUri, rtrim(dirname($baseUrl), '/'.DIRECTORY_SEPARATOR).'/'))
			{
				// directory portion of $baseUrl matches
				return rtrim($prefix, '/'.DIRECTORY_SEPARATOR);
			}

			$truncatedRequestUri = $requestUri;

			if (false !== $pos = strpos($requestUri, '?'))
			{
				$truncatedRequestUri = substr($requestUri, 0, $pos);
			}

			$basename = basename($baseUrl);

			if (empty($basename) || !strpos(rawurldecode($truncatedRequestUri), $basename))
			{
				// no match whatsoever; set it blank
				return '';
			}

			// If using mod_rewrite or ISAPI_Rewrite strip the script filename
			// out of baseUrl. $pos !== 0 makes sure it is not matching a value
			// from PATH_INFO or QUERY_STRING
			if (strlen($requestUri) >= strlen($baseUrl) && (false !== $pos = strpos($requestUri, $baseUrl)) && 0 !== $pos)
			{
				$baseUrl = substr($requestUri, 0, $pos + \strlen($baseUrl));
			}

			return rtrim($baseUrl, '/'.\DIRECTORY_SEPARATOR);
		}

		/**
		 * Returns the prefix as encoded in the string when the string starts with
		 * the given prefix, null otherwise.
		 */
		private function getUrlencodedPrefix(string $string, string $prefix): ?string
		{
			if (0 !== strpos(rawurldecode($string), $prefix))
			{
				return null;
			}

			$len = strlen($prefix);

			if (preg_match(sprintf('#^(%%[[:xdigit:]]{2}|.){%d}#', $len), $string, $match))
			{
				return $match[0];
			}

			return null;
		}

		/**
		 * Returns the requested URI (path and query string).
		 *
		 * @return string The raw URI (i.e. not URI decoded)
		 */
		protected function getRequestUri(): string
		{
			if (null === $this->requestUri)
			{
				$this->requestUri = $this->prepareRequestUri();
			}

			return $this->requestUri;
		}

		/**
		 * Gets the request's scheme.
		 *
		 * @return string
		 */
		protected function getScheme(): string
		{
			return $this->isSecure() ? 'https' : 'http';
		}

		/**
		 * Returns the port on which the request is made.
		 *
		 * This method can read the client port from the "X-Forwarded-Port" header
		 * when trusted proxies were set via "setTrustedProxies()".
		 *
		 * The "X-Forwarded-Port" header must contain the client port.
		 *
		 * @return int|string can be a string if fetched from the server bag
		 */
		protected function getPort(): int | string | null
		{
			if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_PORT))
			{
				$host = $host[0];
			}
			elseif ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST))
			{
				$host = $host[0];
			}
			elseif (!$host = $this->headers->get('HOST'))
			{
				return $this->server->get('SERVER_PORT');
			}

			if ('[' === $host[0])
			{
				$pos = strpos($host, ':', strrpos($host, ']'));
			}
			else
			{
				$pos = strrpos($host, ':');
			}

			if (false !== $pos && $port = substr($host, $pos + 1))
			{
				return (int) $port;
			}

			return 'https' === $this->getScheme() ? 443 : 80;
		}

		/**
		 * Gets the scheme and HTTP host.
		 *
		 * If the URL was called with basic authentication, the user
		 * and the password are not added to the generated string.
		 *
		 * @return string The scheme and HTTP host
		 */
		protected function getSchemeAndHttpHost(): string
		{
			return $this->getScheme().'://'.$this->getHttpHost();
		}

		/**
		 * Returns the HTTP host being requested.
		 *
		 * The port name will be appended to the host if it's non-standard.
		 *
		 * @return string
		 */
		protected function getHttpHost(): string
		{
			$scheme = $this->getScheme();
			$port = $this->getPort();

			if (('http' == $scheme && 80 == $port) || ('https' == $scheme && 443 == $port))
			{
				return $this->getHost();
			}

			return $this->getHost().':'.$port;
		}

		/**
		 * Checks whether the request is secure or not.
		 *
	 	 * This method can read the client protocol from the "X-Forwarded-Proto" header
		 * when trusted proxies were set via "setTrustedProxies()".
		 *
		 * The "X-Forwarded-Proto" header must contain the protocol: "https" or "http".
		 *
		 * @return bool
		 */
		protected function isSecure(): bool
		{
			if ($this->isFromTrustedProxy() && $proto = $this->getTrustedValues(self::HEADER_X_FORWARDED_PROTO))
			{
				return in_array(strtolower($proto[0]), ['https', 'on', 'ssl', '1'], true);
			}

			return (bool) (($this->server->get("HTTP_X_FORWARDED_PROTO") && $this->server->get("HTTP_X_FORWARDED_PROTO") == "https")
				|| ($this->server->get("HTTP_X_FORWARDED_SSL") && $this->server->get("HTTP_X_FORWARDED_SSL") == "on")
				|| ($this->server->get("HTTPS") && $this->server->get("HTTPS") == "on"));
		}

	   /**
	     * Returns the host name.
	     *
	     * This method can read the client host name from the "X-Forwarded-Host" header
	     * when trusted proxies were set via "setTrustedProxies()".
	     *
	     * The "X-Forwarded-Host" header must contain the client host name.
	     *
	     * @return string
	     *
	     * @throws SuspiciousOperationException when the host name is invalid or not trusted
	     */
	    protected function getHost(): string
	    {
			if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST))
			{
				$host = $host[0];
			}
			elseif (!$host = $this->headers->get('HOST'))
			{
				if (!$host = $this->server->get('SERVER_NAME'))
				{
					$host = $this->server->get('SERVER_ADDR', '');
				}
			}

			// trim and remove port number from host
			// host is lowercase as per RFC 952/2181
			$host = strtolower(preg_replace('/:\d+$/', '', trim($host)));

			// as the host can come from the user (HTTP_HOST and depending on the configuration, SERVER_NAME too can come from the user)
			// check that it does not contain forbidden characters (see RFC 952 and RFC 2181)
			// use preg_replace() instead of preg_match() to prevent DoS attacks with long host names
			if ($host && '' !== preg_replace('/(?:^\[)?[a-zA-Z0-9-:\]_]+\.?/', '', $host))
			{
				if (!$this->isHostValid)
				{
					return '';
				}

				$this->isHostValid = false;

				throw new Exception(sprintf('Invalid Host "%s".', $host));
			}

			/*if (count(self::$trustedHostPatterns) > 0)
			{
				// to avoid host header injection attacks, you should provide a list of trusted host patterns
				if (in_array($host, self::$trustedHosts))
				{
					return $host;
				}

				foreach (self::$trustedHostPatterns AS $pattern)
				{
					if (preg_match($pattern, $host))
					{
						self::$trustedHosts[] = $host;

						return $host;
					}
				}

				if (!$this->isHostValid)
				{
					return '';
				}

				$this->isHostValid = false;

				throw new SuspiciousOperationException(sprintf('Untrusted Host "%s".', $host));
			}*/

			return $host;
	    }

		/**
		 * Determine if the current request URI matches a pattern.
		 *
		 * @param  mixed  ...$patterns
		 * @return bool
		 */
		protected function is(...$patterns): bool
		{
			$path = $this->decodedPath();

			foreach ($patterns AS $pattern)
			{
				if (Str::is($pattern, $path))
				{
					return true;
				}
			}

			return false;
		}

		/**
		 * Determine if the request is the result of an AJAX call.
		 *
		 * @return bool
		 */
		protected function ajax(): bool
		{
			return $this->isXmlHttpRequest();
		}

	    /**
	     * Returns true if the request is a XMLHttpRequest.
	     *
	     * It works if your JavaScript library sets an X-Requested-With HTTP header.
	     * It is known to work with common JavaScript frameworks:
	     *
	     * @link http://en.wikipedia.org/wiki/List_of_Ajax_frameworks#JavaScript
	     *
	     * @return bool true if the request is an XMLHttpRequest, false otherwise
	     *
	     * @api
	     */
	    protected function isXmlHttpRequest(): bool
	    {
	        return 'XMLHttpRequest' == $this->headers->get('X-Requested-With');
	    }

		/**
		 * Determine if the request is the result of an prefetch call.
		 *
		 * @return bool
		 */
		protected function prefetch(): bool
		{
			return strcasecmp($this->server->get('HTTP_X_MOZ'), 'prefetch') === 0 ||
				strcasecmp($this->headers->get('Purpose'), 'prefetch') === 0;
		}

		/**
		 * Determine if the request is over HTTPS.
		 *
		 * @return bool
		 */
		protected function secure(): bool
		{
			return $this->isSecure();
		}

		/**
		 * Get the client IP address.
		 *
		 * @return string|null
		 */
		protected function ip(): ?string
		{
			return $this->getClientIp();
		}

		/**
		 * Get the client IP addresses.
		 *
		 * @return array
		 */
		protected function ips(): array
		{
			return $this->getClientIps();
		}

		/**
		 * Get the client user agent.
		 *
		 * @return string
		 */
		protected function userAgent(): string
		{
			return $this->headers->get('User-Agent');
		}

		/**
		 * Merge new input into the current request's input array.
		 *
		 * @param  array  $input
		 * @return $this
		 */
		protected function merge(array $input): self
		{
			$this->getInputSource()->add($input);

			return $this;
		}

		/**
		 * Replace the input for the current request.
		 *
		 * @param  array  $input
		 * @return $this
		 */
		protected function replace(array $input): self
		{
			$this->getInputSource()->replace($input);

			return $this;
		}

		/**
		 * Returns the client IP addresses. [CloudFlare-fix]
		 *
		 * In the returned array the most trusted IP address is first, and the
		 * least trusted one last. The "real" client IP address is the last one,
		 * but this is also the least trusted one. Trusted proxies are stripped.
		 *
		 * Use this method carefully; you should use getClientIp() instead.
		 *
		 * @return array The client IP addresses
		 *
		 * @see getClientIp()
		 */
		protected function getClientIps(): array
		{
	        if($this->server->get("HTTP_CF_CONNECTING_IP"))
	        {
	            $this->server->set("REMOTE_ADDR", $this->server->get("HTTP_CF_CONNECTING_IP"));
	            $this->server->set("HTTP_CLIENT_IP", $this->server->get("HTTP_CF_CONNECTING_IP"));
	        }

	        $client  = $this->server->get('HTTP_CLIENT_IP');
	        $forward = $this->server->get('HTTP_X_FORWARDED_FOR');
	        $ip  	 = $this->server->get('REMOTE_ADDR');

	        if(filter_var($client, FILTER_VALIDATE_IP))
	        {
	            $ip = $client;
	        }
	        elseif(filter_var($forward, FILTER_VALIDATE_IP))
	        {
	            $ip = $forward;
	        }

			if (!$this->isFromTrustedProxy())
			{
				return [$ip];
			}

			return $this->getTrustedValues(self::HEADER_X_FORWARDED_FOR, $ip) ?: [$ip];
		}

		/**
		 * Returns the client IP address.
		 *
		 * This method can read the client IP address from the "X-Forwarded-For" header
		 * when trusted proxies were set via "setTrustedProxies()". The "X-Forwarded-For"
		 * header value is a comma+space separated list of IP addresses, the left-most
		 * being the original client, and each successive proxy that passed the request
		 * adding the IP address where it received the request from.
		 *
		 * If your reverse proxy uses a different header name than "X-Forwarded-For",
		 * ("Client-Ip" for instance), configure it via the $trustedHeaderSet
		 * argument of the Request::setTrustedProxies() method instead.
		 *
		 * @return string|null The client IP address
		 *
		 * @see getClientIps()
		 * @see https://wikipedia.org/wiki/X-Forwarded-For
		 */
		protected function getClientIp(): ?string
		{
			return $this->getClientIps()[0];
		}

		/**
		* Indicates whether this request originated from a trusted proxy.
		*
		* This can be useful to determine whether or not to trust the
		* contents of a proxy-specific header.
		*
		* @return bool true if the request came from a trusted proxy, false otherwise
		*/
		protected function isFromTrustedProxy(): bool
		{
			return $this->trustedProxies && IpUtils::checkIp($this->server->get('REMOTE_ADDR', ""), $this->trustedProxies);
		}

		/**
		 * Sets a list of trusted proxies.
		 *
		 * You should only list the reverse proxies that you manage directly.
		 *
		 * @param array $proxies          A list of trusted proxies, the string 'REMOTE_ADDR' will be replaced with $_SERVER['REMOTE_ADDR']
		 * @param int   $trustedHeaderSet A bit field of Request::HEADER_*, to set which headers to trust from your proxies
		 */
		protected function setTrustedProxies(array $proxies, int $trustedHeaderSet = null): void
		{
			$this->trustedProxies = array_reduce($proxies, function ($proxies, $proxy) 
			{
				if ('REMOTE_ADDR' !== $proxy) 
				{
					$proxies[] = $proxy;
				} 
				elseif ($this->server->get("REMOTE_ADDR", false))
				{
					$proxies[] = $this->server->get("REMOTE_ADDR");
				}

				return $proxies;
			}, []);

			$this->trustedHeaderSet = $trustedHeaderSet ?? self::HEADER_X_FORWARDED_HOST;
		}

		/**
		 * Gets the list of trusted proxies.
		 *
		 * @return string[]
		 */
		protected function getTrustedProxies(): array
		{
			return $this->trustedProxies;
		}

		/**
		 * Sets a list of trusted host patterns.
		 *
		 * You should only list the hosts you manage using regexs.
		 *
		 * @param array $hostPatterns A list of trusted host patterns
		 */
		protected function setTrustedHosts(array $hostPatterns): void
		{
			$this->trustedHostPatterns = array_map(fn ($hostPattern) => sprintf('{%s}i', $hostPattern), $hostPatterns);
			
			// we need to reset trusted hosts on trusted host patterns change
			$this->trustedHosts = [];
		}

		/**
		 * Gets the list of trusted host patterns.
		 *
		 * @return string[]
		 */
		protected function getTrustedHosts(): array
		{
			return $this->trustedHostPatterns;
		}		

		/**
		 * This method is rather heavy because it splits and merges headers, and it's called by many other methods such as
		 * getPort(), isSecure(), getHost(), getClientIps(), getBaseUrl() etc. Thus, we try to cache the results for
		 * best performance.
		 */		
		private function getTrustedValues(int $type, string $ip = null): array
		{
			$cacheKey = $type."\0".(($this->trustedHeaderSet & $type) ? $this->headers->get(self::TRUSTED_HEADERS[$type]) : '');
			$cacheKey .= "\0".$ip."\0".$this->headers->get(self::TRUSTED_HEADERS[self::HEADER_FORWARDED]);
	
			if (isset($this->trustedValuesCache[$cacheKey])) 
			{
				return $this->trustedValuesCache[$cacheKey];
			}
	
			$clientValues = [];
			$forwardedValues = [];
	
			if (($this->trustedHeaderSet & $type) && $this->headers->has(self::TRUSTED_HEADERS[$type])) 
			{
				foreach (explode(',', $this->headers->get(self::TRUSTED_HEADERS[$type])) AS $v) 
				{
					$clientValues[] = (self::HEADER_X_FORWARDED_PORT === $type ? '0.0.0.0:' : '').trim($v);
				}
			}
	
			if (($this->trustedHeaderSet & self::HEADER_FORWARDED) && (isset(self::FORWARDED_PARAMS[$type])) && $this->headers->has(self::TRUSTED_HEADERS[self::HEADER_FORWARDED])) 
			{
				$forwarded = $this->headers->get(self::TRUSTED_HEADERS[self::HEADER_FORWARDED]);
				$parts = HeaderUtils::split($forwarded, ',;=');
				$param = self::FORWARDED_PARAMS[$type];

				foreach ($parts AS $subParts) 
				{
					if (null === $v = HeaderUtils::combine($subParts)[$param] ?? null) 
					{
						continue;
					}
					elseif (self::HEADER_X_FORWARDED_PORT === $type) 
					{
						if (str_ends_with($v, ']') || false === $v = strrchr($v, ':')) 
						{
							$v = $this->isSecure() ? ':443' : ':80';
						}

						$v = '0.0.0.0'.$v;
					}

					$forwardedValues[] = $v;
				}
			}
	
			if (null !== $ip) 
			{
				$clientValues = $this->normalizeAndFilterClientIps($clientValues, $ip);
				$forwardedValues = $this->normalizeAndFilterClientIps($forwardedValues, $ip);
			}
			elseif ($forwardedValues === $clientValues || !$clientValues) 
			{
				return $this->trustedValuesCache[$cacheKey] = $forwardedValues;
			}
			elseif (!$forwardedValues) 
			{
				return $this->trustedValuesCache[$cacheKey] = $clientValues;
			}
			elseif (!$this->isForwardedValid) 
			{
				return $this->trustedValuesCache[$cacheKey] = null !== $ip ? ['0.0.0.0', $ip] : [];
			}

			$this->isForwardedValid = false;

			throw new Exception(sprintf('The request has both a trusted "%s" header and a trusted "%s" header, conflicting with each other. You should either configure your proxy to remove one of them, or configure your project to distrust the offending one.', self::TRUSTED_HEADERS[self::HEADER_FORWARDED], self::TRUSTED_HEADERS[$type]));
	
			//throw new ConflictingHeadersException(sprintf('The request has both a trusted "%s" header and a trusted "%s" header, conflicting with each other. You should either configure your proxy to remove one of them, or configure your project to distrust the offending one.', self::TRUSTED_HEADERS[self::HEADER_FORWARDED], self::TRUSTED_HEADERS[$type]));
		}

		private function normalizeAndFilterClientIps(array $clientIps, string $ip): array
		{
			if (!$clientIps)
			{
				return [];
			}

			$clientIps[] = $ip; // Complete the IP chain with the IP the request actually came from
			$firstTrustedIp = null;

			foreach ($clientIps AS $key => $clientIp)
			{
				if (strpos($clientIp, '.'))
				{
					// Strip :port from IPv4 addresses. This is allowed in Forwarded
					// and may occur in X-Forwarded-For.
					$i = strpos($clientIp, ':');

					if ($i)
					{
						$clientIps[$key] = $clientIp = substr($clientIp, 0, $i);
					}
				}
				elseif (0 === strpos($clientIp, '['))
				{
					// Strip brackets and :port from IPv6 addresses.
					$i = strpos($clientIp, ']', 1);
					$clientIps[$key] = $clientIp = substr($clientIp, 1, $i - 1);
				}

				if (!filter_var($clientIp, FILTER_VALIDATE_IP))
				{
					unset($clientIps[$key]);

					continue;
				}
				elseif (IpUtils::checkIp($clientIp, $this->trustedProxies))
				{
					unset($clientIps[$key]);

					// Fallback to this when the client IP falls into the range of trusted proxies
					if (null === $firstTrustedIp)
					{
						$firstTrustedIp = $clientIp;
					}
				}
			}

			// Now the IP chain contains only untrusted proxies and the client IP
			return $clientIps ? array_reverse($clientIps) : [$firstTrustedIp];
		}

		/**
		 * Get the route handling the request.
		 *
		 * @param  string|null  $param
		 * @param  mixed  $default
		 * @return \Illuminate\Routing\Route|object|string|null
		 */
		protected function route($param = null, $default = null): mixed
		{
			$route = call_user_func($this->getRouteResolver());

			if (is_null($route) || is_null($param))
			{
				return $route;
			}

			return $route->parameter($param, $default);
		}

		/**
		 * Check if an input element is set on the request.
		 *
		 * @param  string  $key
		 * @return bool
		 */
		public function __isset($key): bool
		{
			return ! is_null($this->__get($key));
		}

		/**
		 * Get an input element from the request.
		 *
		 * @param  string  $key
		 * @return mixed
		 */
		public function __get($key): mixed
		{
			return Arr::flatten($this->all(), $key, fn($key) => $this->route($key));
		}

		/**
		 * Gets a "parameter" value from any bag.
		 *
		 * This method is mainly useful for libraries that want to provide some flexibility. If you don't need the
		 * flexibility in controllers, it is better to explicitly get request parameters from the appropriate
		 * public property instead (attributes, query, request).
		 *
		 * Order of precedence: PATH (routing placeholders or custom attributes), GET, BODY
		 *
		 * @param mixed $default The default value if the parameter key does not exist
		 *
		 * @return mixed
		 */
		protected function get(string $key, $default = null): mixed
		{
			if ($this !== $result = $this->attributes->get($key, $this))
			{
				return $result;
			}

			if ($this->query->has($key))
			{
				return $this->query->all()[$key];
			}

			if ($this->request->has($key))
			{
				return $this->request->all()[$key];
			}

			return $default;
		}

		protected function set(string $key, $value): mixed
		{
			return $this->attributes->set($key, $value);
		}		

		/**
		 * Clones a request and overrides some of its parameters.
		 *
		 * @param array $query      The GET parameters
		 * @param array $request    The POST parameters
		 * @param array $attributes The request attributes (parameters parsed from the PATH_INFO, ...)
		 * @param array $cookies    The COOKIE parameters
		 * @param array $files      The FILES parameters
		 * @param array $server     The SERVER parameters
		 *
		 * @return static
		 */
		protected function duplicate(array $query = null, array $request = null, array $attributes = null, array $cookies = null, array $files = null, array $server = null): self
		{
			$dup = clone $this;

			if (null !== $query)
			{
				$dup->query = new ParameterBag($query);
			}

			if (null !== $request)
			{
				$dup->request = new ParameterBag($request);
			}

			if (null !== $attributes)
			{
				$dup->attributes = new ParameterBag($attributes);
			}

			if (null !== $cookies)
			{
				$dup->cookies = new ParameterBag($cookies);
			}

			if (null !== $files)
			{
				$dup->files = new FileBag($files);
			}

			if (null !== $server)
			{
				$dup->server = new ServerBag($server);
				$dup->headers = new HeaderBag($dup->server->getHeaders());
			}

			$dup->languages = null;
			$dup->charsets = null;
			$dup->encodings = null;
			$dup->acceptableContentTypes = null;
			$dup->pathInfo = null;
			$dup->requestUri = null;
			$dup->baseUrl = null;
			$dup->basePath = null;
			$dup->method = null;
			$dup->format = null;

			return $dup;
		}

		/**
		 * Clones the current request.
		 *
		 * Note that the session is not cloned as duplicated requests
		 * are most of the time sub-requests of the main one.
		 */
		protected function __clone(): void
		{
			$this->query 		= clone $this->query;
			$this->request 		= clone $this->request;
			$this->attributes 	= clone $this->attributes;
			$this->cookies 		= clone $this->cookies;
			$this->files 		= clone $this->files;
			$this->server 		= clone $this->server;
			$this->headers 		= clone $this->headers;
		}
	}

	/**
	 *	Bags
	 */
	class ParameterBag
	{
		protected $parameters = array();

		function __construct(array $parameters = array())
		{
			$this->parameters = $parameters;
		}

		public function all(): array
		{
			return $this->parameters;
		}

		public function keys(): array
		{
			return array_keys($this->parameters);
		}

		public function add(array $parameters = array()): void
		{
			$this->parameters = array_replace($this->parameters, $parameters);
		}

		public function replace(array $parameters = array()): void
		{
			$this->parameters = $parameters;
		}

		/**
		 * Returns a parameter by name.
		 *
		 * @param string $path    The key
		 * @param mixed  $default The default value if the parameter key does not exist
		 * @param bool   $deep    If true, a path like foo[bar] will find deeper items
		 *
		 * @return mixed
		 *
		 * @throws \InvalidArgumentException
		 */
		public function get(string $path, mixed $default = null, bool $deep = false): mixed
		{
			if (!$deep || false === $pos = strpos($path, '['))
			{
				if(array_key_exists($path, $this->parameters))
				{
					if(!is_string($this->parameters[$path]))
					{
						return $this->parameters[$path];
					}
					
					$as_json = json_decode($this->parameters[$path], true);
					return ($as_json !== null) ? $as_json : $this->parameters[$path];
				}

				return $default;
			}

			$root = substr($path, 0, $pos);

			if (!array_key_exists($root, $this->parameters))
			{
				return $default;
			}

			$value = $this->parameters[$root];
			$currentKey = null;

			for ($i = $pos, $c = strlen($path); $i < $c; ++$i)
			{
				$char = $path[$i];

				if ('[' === $char)
				{
					if (null !== $currentKey)
					{
						throw new Exception(sprintf('Malformed path. Unexpected "[" at position %d.', $i));
					}

					$currentKey = '';
				}
				elseif (']' === $char)
				{
					if (null === $currentKey)
					{
						throw new Exception(sprintf('Malformed path. Unexpected "]" at position %d.', $i));
					}

					if (!is_array($value) || !array_key_exists($currentKey, $value))
					{
						return $default;
					}

					$value = $value[$currentKey];
					$currentKey = null;
				}
				else
				{
					if (null === $currentKey)
					{
						throw new Exception(sprintf('Malformed path. Unexpected "%s" at position %d.', $char, $i));
					}

					$currentKey .= $char;
				}
			}

			if (null !== $currentKey)
			{
				throw new Exception(sprintf('Malformed path. Path must end with "]".'));
			}

			return $value;
		}

		public function set(string $key, mixed $value): void
		{
			$this->parameters[$key] = $value;
		}

		public function has(string $key): bool
		{
			return array_key_exists($key, $this->parameters);
		}

		public function remove(string $key): void
		{
			unset($this->parameters[$key]);
		}

		/**
		 * Returns the alphabetic characters of the parameter value.
		 *
		 * @param string $key     The parameter key
		 * @param string $default The default value if the parameter key does not exist
		 * @param bool   $deep    If true, a path like foo[bar] will find deeper items
		 *
		 * @return string The filtered value
		 */
		public function getAlpha(string $key, string $default = '', bool $deep = false): string
		{
			return preg_replace('/[^[:alpha:]]/', '', $this->get($key, $default, $deep));
		}

		/**
		 * Returns the alphabetic characters and digits of the parameter value.
		 *
		 * @param string $key     The parameter key
		 * @param string $default The default value if the parameter key does not exist
		 * @param bool   $deep    If true, a path like foo[bar] will find deeper items
		 *
		 * @return string The filtered value
		 */
		public function getAlnum(string $key, string $default = '', bool $deep = false): string
		{
			return preg_replace('/[^[:alnum:]]/', '', $this->get($key, $default, $deep));
		}

		/**
		 * Returns the digits of the parameter value.
		 *
		 * @param string $key     The parameter key
		 * @param string $default The default value if the parameter key does not exist
		 * @param bool   $deep    If true, a path like foo[bar] will find deeper items
		 *
		 * @return string The filtered value
		 */
		public function getDigits(string $key, string $default = '', bool $deep = false): string
		{
			// we need to remove - and + because they're allowed in the filter
			return str_replace(array('-', '+'), '', $this->filter($key, $default, $deep, FILTER_SANITIZE_NUMBER_INT));
		}

		/**
		 * Returns the parameter value converted to integer.
		 *
		 * @param string $key     The parameter key
		 * @param int    $default The default value if the parameter key does not exist
		 * @param bool   $deep    If true, a path like foo[bar] will find deeper items
		 *
		 * @return int The filtered value
		 */
		public function getInt(string $key, int $default = 0, bool $deep = false): int
		{
			return (int) $this->get($key, $default, $deep);
		}

		/**
		 * Filter key.
		 *
		 * @param string $key     Key.
		 * @param mixed  $default Default = null.
		 * @param bool   $deep    Default = false.
		 * @param int    $filter  FILTER_* constant.
		 * @param mixed  $options Filter options.
		 *
		 * @see http://php.net/manual/en/function.filter-var.php
		 *
		 * @return mixed
		 */
		public function filter(string $key, mixed $default = null, bool $deep = false, int $filter = FILTER_DEFAULT, array $options = array()): mixed
		{
			$value = $this->get($key, $default, $deep);

			// Always turn $options into an array - this allows filter_var option shortcuts.
			if (!is_array($options) && $options)
			{
				$options = array('flags' => $options);
			}

			// Add a convenience check for arrays.
			if (is_array($value) && !isset($options['flags']))
			{
				$options['flags'] = FILTER_REQUIRE_ARRAY;
			}

			return filter_var($value, $filter, $options);
		}

		/**
		 * Returns an iterator for parameters.
		 *
		 * @return \ArrayIterator An \ArrayIterator instance
		 */
		public function getIterator(): \ArrayIterator
		{
			return new \ArrayIterator($this->parameters);
		}

		public function count(): int
		{
			return count($this->parameters);
		}
	}

	class FileBag extends ParameterBag
	{
		private static $fileKeys = array('error', 'name', 'size', 'tmp_name', 'type', 'full_path');

		function __construct(array $parameters = array())
		{
			if(!empty($parameters))
			{
				foreach($parameters AS $k => $v)
				{
					$this->set($k, $v);
				}
			}
		}

		public function set(string $key, mixed $value): void
		{
			if (!is_array($value) && !$value instanceof UploadedFile)
			{
				throw new Exception('An uploaded file must be an array or an instance of UploadedFile.');
			}

			parent::set($key, $this->convertFileInformation($value));
		}

		public function add(array $files = array()): void
		{
			foreach ($files AS $key => $file)
			{
				$this->set($key, $file);
			}
		}

		/**
		 * Converts uploaded files to UploadedFile instances.
		 *
		 * @param array|UploadedFile $file A (multi-dimensional) array of uploaded file information
		 *
		 * @return array A (multi-dimensional) array of UploadedFile instances
		 */
		protected function convertFileInformation(mixed $file): array
		{
			if ($file instanceof UploadedFile)
			{
				return $file;
			}

			$file = $this->fixPhpFilesArray($file);

			if(is_array($file))
			{
				$keys = array_keys($file);
				sort($keys);

				if(!array_diff($keys, self::$fileKeys))
				{
					if (UPLOAD_ERR_NO_FILE == $file['error'])
					{
						$file = null;
					}
					else
					{
						$file = new UploadedFile($file['tmp_name'], $file['name'], $file['error']);
					}
				}
				else
				{
					$file = array_map(array($this, 'convertFileInformation'), $file);
				}
			}
			
			return $file;
		}

		/**
		 * Fixes a malformed PHP $_FILES array.
		 *
		 * PHP has a bug that the format of the $_FILES array differs, depending on
		 * whether the uploaded file fields had normal field names or array-like
		 * field names ("normal" vs. "parent[child]").
		 *
		 * This method fixes the array to look like the "normal" $_FILES array.
		 *
		 * It's safe to pass an already converted array, in which case this method
		 * just returns the original array unmodified.
		 *
		 * @param array $data
		 *
		 * @return array
		 */
		protected function fixPhpFilesArray(mixed $data): array
		{
			if (!is_array($data))
			{
				return $data;
			}

			$keys = array_keys($data);
			sort($keys);

			if (count(array_diff_key(self::$fileKeys, $keys)) > 0 || !isset($data['name']) || !is_array($data['name']))
			{
				return $data;
			}

			$files = $data;

			foreach (self::$fileKeys AS $k)
			{
				unset($files[$k]);
			}

			foreach ($data['name'] AS $key => $name)
			{
				$files[$key] = $this->fixPhpFilesArray(array(
					'error' 	=> $data['error'][$key],
					'name' 		=> $name,
					'type' 		=> $data['type'][$key],
					'tmp_name' 	=> $data['tmp_name'][$key],
					'size' 		=> $data['size'][$key],
				));
			}

			return $files;
		}
	}

	class SessionBag extends ParameterBag
	{
		function __construct(array $parameters = array())
		{
			parent::__construct($parameters);

			if($this->has("_flash.new"))
			{
				$this->forget($this->get("_flash.new"));
				$this->forget("_flash.new");
			}
		}

		public function flash(string $key, mixed $value = true): void
		{
			$this->set($key, $value);

			$this->push("_flash.new", $key);
		}

		public function push(string $key, mixed $value): void
		{
			if($this->has($key))
			{
				$values = (array) $this->get($key);
				$this->set($key, array_unique(array_merge($values, array($key, $value))));
			}
			else
			{
				$this->set($key, $value);
			}
		}

		public function set(string $key, mixed $value): void
		{
			$_SESSION[$key] = $value;

			parent::set($key, $value);
		}

		public function forget(mixed $key): void
		{
			if(is_array($key))
			{
				foreach($key AS $k)
				{
					$this->forget($k);
				}
			}
			else
			{
				unset($_SESSION[$key]);
			}
		}

		public function destroy(): void
		{
			session_write_close();
		}
	}

	class CookieBag extends ParameterBag
	{
		private $defaults = array(
			'expires' 	=> "",
			'path'		=> "/",
			'domain' 	=> "",
			'secure'	=> true,
			'samesite'	=> 'Lax'
		);

		function __construct($parameters)
		{
			parent::__construct($parameters);

			$this->defaults = array_merge($this->defaults, array(
				'expires' 	=> time() + 3600/*,
				'domain'	=> ".".Env::get("domain")*/
			));
		}

		public function setDomain($_domain): void
		{
			// Remove subdomain
			$_domain = implode('.', array_slice(explode('.', parse_url($_domain)['path']), -2));

			$this->defaults['domain'] = "." . $_domain;
		}

		public function setSecure($_secure): void
		{
			$this->defaults['secure'] = $_secure;
		}

		public function set(string $key, mixed $value, array $options = array()): void
		{
			$value = (is_array($value)) ? json_encode($value) : $value;

			setCookie($key, $value, array_merge($this->defaults, $options));

			parent::set($key, $value);
		}

		public function forget(string $key, array $options = array()): void
		{
			setCookie($key, "", array_merge($this->defaults, $options, array(
				'expires' => time() - 3600
			)));

			$this->remove($key);
		}
	}

	class ServerBag extends ParameterBag
	{
		/**
		 * Gets the HTTP headers.
		 *
		 * @return array
		 */
		public function getHeaders(): array
		{
			$headers = array();
			$contentHeaders = array('CONTENT_LENGTH' => true, 'CONTENT_MD5' => true, 'CONTENT_TYPE' => true);

			foreach ($this->parameters AS $key => $value)
			{
				if (0 === strpos($key, 'HTTP_'))
				{
					$headers[substr($key, 5)] = $value;
				}
				elseif (isset($contentHeaders[$key])) // CONTENT_* are not prefixed with HTTP_
				{
					$headers[$key] = $value;
				}
			}

			if (isset($this->parameters['PHP_AUTH_USER']))
			{
				$headers['PHP_AUTH_USER'] = $this->parameters['PHP_AUTH_USER'];
				$headers['PHP_AUTH_PW'] = isset($this->parameters['PHP_AUTH_PW']) ? $this->parameters['PHP_AUTH_PW'] : '';
			}
			else
			{
				/**
				 *	Use Http-authenticator with Nginx
				 *	https://www.digitalocean.com/community/tutorials/how-to-set-up-basic-http-authentication-with-nginx-on-ubuntu-14-04
				 */
				$authorizationHeader = null;

				if (isset($this->parameters['HTTP_AUTHORIZATION']))
				{
					$authorizationHeader = $this->parameters['HTTP_AUTHORIZATION'];
				}
				elseif (isset($this->parameters['REDIRECT_HTTP_AUTHORIZATION']))
				{
					$authorizationHeader = $this->parameters['REDIRECT_HTTP_AUTHORIZATION'];
				}

				if ($authorizationHeader !== null)
				{
					if (stripos($authorizationHeader, 'basic ') === 0)
					{
						// Decode AUTHORIZATION header into PHP_AUTH_USER and PHP_AUTH_PW when authorization header is basic
						$exploded = explode(':', base64_decode(substr($authorizationHeader, 6)), 2);

						if (count($exploded) == 2)
						{
							list($headers['PHP_AUTH_USER'], $headers['PHP_AUTH_PW']) = $exploded;
						}
					}
					elseif (empty($this->parameters['PHP_AUTH_DIGEST']) && (0 === stripos($authorizationHeader, 'digest ')))
					{
						// In some circumstances PHP_AUTH_DIGEST needs to be set
						$headers['PHP_AUTH_DIGEST'] = $authorizationHeader;

						$this->parameters['PHP_AUTH_DIGEST'] = $authorizationHeader;

					}
					elseif (0 === stripos($authorizationHeader, 'bearer '))
					{
						/*
						* XXX: Since there is no PHP_AUTH_BEARER in PHP predefined variables,
						*      I'll just set $headers['AUTHORIZATION'] here.
						*      http://php.net/manual/en/reserved.variables.server.php
						*/
						$headers['AUTHORIZATION'] = $authorizationHeader;
					}
				}
			}

			if (isset($headers['AUTHORIZATION']))
			{
				return $headers;
			}

			// PHP_AUTH_USER/PHP_AUTH_PW
			if (isset($headers['PHP_AUTH_USER']))
			{
				$headers['AUTHORIZATION'] = 'Basic '.base64_encode($headers['PHP_AUTH_USER'].':'.$headers['PHP_AUTH_PW']);
			}
			elseif (isset($headers['PHP_AUTH_DIGEST']))
			{
				$headers['AUTHORIZATION'] = $headers['PHP_AUTH_DIGEST'];
			}

			return $headers;
		}
	}

	class HeaderBag
	{
		protected $headers = array();
		protected $cacheControl = array();

		function __construct(array $headers = array())
		{
			foreach ($headers AS $key => $values)
			{
				$this->set($key, $values);
			}
		}

		/**
		 * Returns the headers as a string.
		 *
		 * @return string The headers
		 */
		public function __toString(): string
		{
			if (empty($this->headers))
			{
				return '';
			}

			$max = max(array_map('strlen', array_keys($this->headers))) + 1;

			$content = '';

			ksort($this->headers);

			foreach ($this->headers AS $name => $values)
			{
				$name = implode('-', array_map('ucfirst', explode('-', $name)));

				foreach ($values AS $value)
				{
					$content .= sprintf("%-{$max}s %s\r\n", $name.':', $value);
				}
			}

			return $content;
		}

		public function all(): array
		{
			return $this->headers;
		}

		public function keys(): array
		{
			return array_keys($this->headers);
		}

		public function add(array $headers): void
		{
			foreach ($headers AS $key => $values)
			{
				$this->set($key, $values);
			}
		}

		/**
		 * Returns a header value by name.
		 *
		 * @param string $key     The header name
		 * @param mixed  $default The default value
		 * @param bool   $first   Whether to return the first value or all header values
		 *
		 * @return string|array The first header value if $first is true, an array of values otherwise
		 */
		public function get(string $key, mixed $default = null, bool $first = true): mixed
		{
			$key = str_replace('_', '-', strtolower($key));

			if (!array_key_exists($key, $this->headers))
			{
				if (null === $default)
				{
					return $first ? null : array();
				}

				return $first ? $default : array($default);
			}

			if ($first)
			{
				return count($this->headers[$key]) ? $this->headers[$key][0] : $default;
			}

			return $this->headers[$key];
		}

		/**
		 * Sets a header by name.
		 *
		 * @param string       $key     The key
		 * @param string|array $values  The value or an array of values
		 * @param bool         $replace Whether to replace the actual value or not (true by default)
		 */
		public function set(string $key, mixed $values, bool $replace = true): void
		{
			$key = str_replace('_', '-', strtolower($key));

			$values = array_values((array) $values);

			if (true === $replace || !isset($this->headers[$key]))
			{
				$this->headers[$key] = $values;
			}
			else
			{
				$this->headers[$key] = array_merge($this->headers[$key], $values);
			}

			if ('cache-control' === $key)
			{
				$this->cacheControl = $this->parseCacheControl($values[0]);
			}
		}

		public function has(string $key): bool
		{
			return array_key_exists(str_replace('_', '-', strtolower($key)), $this->headers);
		}

		public function contains(string $key, mixed $value): bool
		{
			return in_array($value, $this->get($key, null, false));
		}

		public function remove(string $key): void
		{
			$key = str_replace('_', '-', strtolower($key));

			unset($this->headers[$key]);

			if ('cache-control' === $key)
			{
				$this->cacheControl = array();
			}
		}

		/**
		 * Returns the number of headers.
		 *
		 * @return int The number of headers
		 */
		public function count(): int
		{
			return count($this->headers);
		}

		/**
		 * Parses a Cache-Control HTTP header.
		 *
		 * @param string $header The value of the Cache-Control HTTP header
		 *
		 * @return array An array representing the attribute values
		 */
		protected function parseCacheControl(string $header): array
		{
			$cacheControl = array();

			preg_match_all('#([a-zA-Z][a-zA-Z_-]*)\s*(?:=(?:"([^"]*)"|([^ \t",;]*)))?#', $header, $matches, PREG_SET_ORDER);

			foreach ($matches AS $match)
			{
				$cacheControl[strtolower($match[1])] = isset($match[3]) ? $match[3] : (isset($match[2]) ? $match[2] : true);
			}

			return $cacheControl;
		}
	}

	final class InputBag extends ParameterBag
	{
		/**
		 * Returns a string input value by name.
		 *
		 * @param string|null $default The default value if the input key does not exist
		 *
		 * @return string|null
		 */
		public function get(string $key, mixed $default = null, bool $deep = false): ?string
		{
			$value = parent::get($key, $this);

			return $this === $value ? $default : $value;
		}

		/**
		* Returns the inputs.
		*
		* @param string|null $key The name of the input to return or null to get them all
		*/
		public function all(string $key = null): array
		{
			if (null === $key)
			{
				return $this->parameters;
			}

			$value = $this->parameters[$key] ?? [];

			if (!is_array($value))
			{
				throw new Exception(sprintf('Unexpected value for "%s" input, expecting "array", got "%s".', $key, get_debug_type($value)));
			}

			return $value;
		}

		/**
		* Replaces the current input values by a new set.
		*/
		public function replace(array $inputs = []): void
		{
			$this->parameters = [];
			$this->add($inputs);
		}

		/**
		* Adds input values.
		*/
		public function add(array $inputs = []): void
		{
			foreach ($inputs AS $input => $value)
			{
				$this->set($input, $value);
			}
		}

		/**
		* {@inheritdoc}
		*/
		/*public function filter(string $key, $default = null, int $filter = FILTER_DEFAULT, $options = [])
		{
			$value = $this->has($key) ? $this->all()[$key] : $default;

			// Always turn $options into an array - this allows filter_var option shortcuts.
			if (!is_array($options) && $options)
			{
				$options = ['flags' => $options];
			}

			/*if (is_array($value) && !(($options['flags'] ?? 0) & (FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY)))
			{
			trigger_deprecation('symfony/http-foundation', '5.1', 'Filtering an array value with "%s()" without passing the FILTER_REQUIRE_ARRAY or FILTER_FORCE_ARRAY flag is deprecated', __METHOD__);

			if (!isset($options['flags'])) {
			$options['flags'] = FILTER_REQUIRE_ARRAY;
			}
			}*

			return filter_var($value, $filter, $options);
		}

		public function filter($key, $default = null, $deep = false, $filter = FILTER_DEFAULT, $options = array())
		{
			$value = $this->get($key, $default, $deep);

			// Always turn $options into an array - this allows filter_var option shortcuts.
			if (!is_array($options) && $options)
			{
				$options = array('flags' => $options);
			}

			// Add a convenience check for arrays.
			if (is_array($value) && !isset($options['flags']))
			{
				$options['flags'] = FILTER_REQUIRE_ARRAY;
			}

			return filter_var($value, $filter, $options);
		}		*/
	}

	class UploadedFile extends File
	{
	    /**
	     * Begin creating a new file fake.
	     *
	     * @return \Illuminate\Http\Testing\FileFactory
	     */
	    public static function fake(): FileFactory
	    {
	        return new FileFactory;
	    }

	    /**
	     * Store the uploaded file on a filesystem disk.
	     *
	     * @param  string  $path
	     * @param  array|string  $options
	     * @return string|false
	     */
	    public function store(string $path, array | string $options = []): string | false
	    {
	        return $this->storeAs($path, $this->hashName(), $this->parseOptions($options));
	    }

	    /**
	     * Store the uploaded file on a filesystem disk with public visibility.
	     *
	     * @param  string  $path
	     * @param  array|string  $options
	     * @return string|false
	     */
	    public function storePublicly(string $path, array | string $options = []): string | false
	    {
	        $options = $this->parseOptions($options);

	        $options['visibility'] = 'public';

	        return $this->storeAs($path, $this->hashName(), $options);
	    }

	    /**
	     * Store the uploaded file on a filesystem disk with public visibility.
	     *
	     * @param  string  $path
	     * @param  string  $name
	     * @param  array|string  $options
	     * @return string|false
	     */
	    public function storePubliclyAs(string $path, string $name, array | string $options = []): string | false
	    {
	        $options = $this->parseOptions($options);

	        $options['visibility'] = 'public';

	        return $this->storeAs($path, $name, $options);
	    }

	    /**
	     * Store the uploaded file on a filesystem disk.
	     *
	     * @param  string  $path
	     * @param  string  $name
	     * @param  array|string  $options
	     * @return string|false
	     */
	    public function storeAs(string $path, string $name, array | string $options = []): string | false
	    {
	        $options = $this->parseOptions($options);

	        $disk = Arr::pull($options, 'disk');

	        return Container::getInstance()->make(FilesystemFactory::class)->disk($disk)->putFileAs(
	            $path, $this, $name, $options
	        );
	    }

	    /**
	     * Get the contents of the uploaded file.
	     *
	     * @return bool|string
	     *
	     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
	     */
	    public function get(): bool | string
	    {
	        if (! $this->isValid()) {
	            throw new FileNotFoundException("File does not exist at path {$this->getPathname()}.");
	        }

	        return file_get_contents($this->getPathname());
	    }

	    /**
	     * Get the file's extension supplied by the client.
	     *
	     * @return string
	     */
	    public function clientExtension(): string
	    {
	        return $this->guessClientExtension();
	    }

	    /**
	     * Create a new file instance from a base instance.
	     *
	     * @param  \Symfony\Component\HttpFoundation\File\UploadedFile  $file
	     * @param  bool  $test
	     * @return static
	     */
	    public static function createFromBase(File $file, bool $test = false): self
	    {
	        return $file instanceof static ? $file : new static(
	            $file->getPathname(),
	            $file->getClientOriginalName(),
	            $file->getClientMimeType(),
	            $file->getError(),
	            $test
	        );
	    }

	    /**
	     * Parse and format the given options.
	     *
	     * @param  array|string  $options
	     * @return array
	     */
	    protected function parseOptions(array | string $options): array
	    {
	        if (is_string($options)) {
	            $options = ['disk' => $options];
	        }

	        return $options;
	    }
	}

	class HeaderUtils
	{
		public const DISPOSITION_ATTACHMENT = 'attachment';
		public const DISPOSITION_INLINE = 'inline';
	
		/**
		 * This class should not be instantiated.
		 */
		private function __construct(){}
	
		/**
		 * Splits an HTTP header by one or more separators.
		 *
		 * Example:
		 *
		 *     HeaderUtils::split('da, en-gb;q=0.8', ',;')
		 *     // => ['da'], ['en-gb', 'q=0.8']]
		 *
		 * @param string $separators List of characters to split on, ordered by
		 *                           precedence, e.g. ',', ';=', or ',;='
		 *
		 * @return array Nested array with as many levels as there are characters in
		 *               $separators
		 */
		public static function split(string $header, string $separators): array
		{
			if ('' === $separators) {
				throw new \InvalidArgumentException('At least one separator must be specified.');
			}
	
			$quotedSeparators = preg_quote($separators, '/');
	
			preg_match_all('
				/
					(?!\s)
						(?:
							# quoted-string
							"(?:[^"\\\\]|\\\\.)*(?:"|\\\\|$)
						|
							# token
							[^"'.$quotedSeparators.']+
						)+
					(?<!\s)
				|
					# separator
					\s*
					(?<separator>['.$quotedSeparators.'])
					\s*
				/x', trim($header), $matches, \PREG_SET_ORDER);
	
			return self::groupParts($matches, $separators);
		}
	
		/**
		 * Combines an array of arrays into one associative array.
		 *
		 * Each of the nested arrays should have one or two elements. The first
		 * value will be used as the keys in the associative array, and the second
		 * will be used as the values, or true if the nested array only contains one
		 * element. Array keys are lowercased.
		 *
		 * Example:
		 *
		 *     HeaderUtils::combine([['foo', 'abc'], ['bar']])
		 *     // => ['foo' => 'abc', 'bar' => true]
		 */
		public static function combine(array $parts): array
		{
			$assoc = [];
			foreach ($parts as $part) {
				$name = strtolower($part[0]);
				$value = $part[1] ?? true;
				$assoc[$name] = $value;
			}
	
			return $assoc;
		}
	
		/**
		 * Decodes a quoted string.
		 *
		 * If passed an unquoted string that matches the "token" construct (as
		 * defined in the HTTP specification), it is passed through verbatim.
		 */
		public static function unquote(string $s): string
		{
			return preg_replace('/\\\\(.)|"/', '$1', $s);
		}

		private static function groupParts(array $matches, string $separators, bool $first = true): array
		{
			$separator = $separators[0];
			$separators = substr($separators, 1) ?: '';
			$i = 0;
	
			if ('' === $separators && !$first) {
				$parts = [''];
	
				foreach ($matches as $match) {
					if (!$i && isset($match['separator'])) {
						$i = 1;
						$parts[1] = '';
					} else {
						$parts[$i] .= self::unquote($match[0]);
					}
				}
	
				return $parts;
			}
	
			$parts = [];
			$partMatches = [];
	
			foreach ($matches as $match) {
				if (($match['separator'] ?? null) === $separator) {
					++$i;
				} else {
					$partMatches[$i][] = $match;
				}
			}
	
			foreach ($partMatches as $matches) {
				$parts[] = '' === $separators ? self::unquote($matches[0][0]) : self::groupParts($matches, $separators, false);
			}
	
			return $parts;
		}
	}

	class IpUtils
	{
		public const PRIVATE_SUBNETS = [
			'127.0.0.0/8',    // RFC1700 (Loopback)
			'10.0.0.0/8',     // RFC1918
			'192.168.0.0/16', // RFC1918
			'172.16.0.0/12',  // RFC1918
			'169.254.0.0/16', // RFC3927
			'0.0.0.0/8',      // RFC5735
			'240.0.0.0/4',    // RFC1112
			'::1/128',        // Loopback
			'fc00::/7',       // Unique Local Address
			'fe80::/10',      // Link Local Address
			'::ffff:0:0/96',  // IPv4 translations
			'::/128',         // Unspecified address
		];
	
		private static array $checkedIps = [];
	
		/**
		 * This class should not be instantiated.
		 */
		private function __construct(){}
	
		/**
		 * Checks if an IPv4 or IPv6 address is contained in the list of given IPs or subnets.
		 *
		 * @param string|array $ips List of IPs or subnets (can be a string if only a single one)
		 */
		public static function checkIp(string $requestIp, string|array $ips): bool
		{
			if (!\is_array($ips)) {
				$ips = [$ips];
			}
	
			$method = substr_count($requestIp, ':') > 1 ? 'checkIp6' : 'checkIp4';
	
			foreach ($ips as $ip) {
				if (self::$method($requestIp, $ip)) {
					return true;
				}
			}
	
			return false;
		}
	
		/**
		 * Compares two IPv4 addresses.
		 * In case a subnet is given, it checks if it contains the request IP.
		 *
		 * @param string $ip IPv4 address or subnet in CIDR notation
		 *
		 * @return bool Whether the request IP matches the IP, or whether the request IP is within the CIDR subnet
		 */
		public static function checkIp4(string $requestIp, string $ip): bool
		{
			$cacheKey = $requestIp.'-'.$ip.'-v4';
			if (null !== $cacheValue = self::getCacheResult($cacheKey)) {
				return $cacheValue;
			}
	
			if (!filter_var($requestIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
				return self::setCacheResult($cacheKey, false);
			}
	
			if (str_contains($ip, '/')) {
				[$address, $netmask] = explode('/', $ip, 2);
	
				if ('0' === $netmask) {
					return self::setCacheResult($cacheKey, false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
				}
	
				if ($netmask < 0 || $netmask > 32) {
					return self::setCacheResult($cacheKey, false);
				}
			} else {
				$address = $ip;
				$netmask = 32;
			}
	
			if (false === ip2long($address)) {
				return self::setCacheResult($cacheKey, false);
			}
	
			return self::setCacheResult($cacheKey, 0 === substr_compare(sprintf('%032b', ip2long($requestIp)), sprintf('%032b', ip2long($address)), 0, $netmask));
		}
	
		/**
		 * Compares two IPv6 addresses.
		 * In case a subnet is given, it checks if it contains the request IP.
		 *
		 * @author David Soria Parra <dsp at php dot net>
		 *
		 * @see https://github.com/dsp/v6tools
		 *
		 * @param string $ip IPv6 address or subnet in CIDR notation
		 *
		 * @throws \RuntimeException When IPV6 support is not enabled
		 */
		public static function checkIp6(string $requestIp, string $ip): bool
		{
			$cacheKey = $requestIp.'-'.$ip.'-v6';
			if (null !== $cacheValue = self::getCacheResult($cacheKey)) {
				return $cacheValue;
			}
	
			if (!((\extension_loaded('sockets') && \defined('AF_INET6')) || @inet_pton('::1'))) {
				throw new \RuntimeException('Unable to check Ipv6. Check that PHP was not compiled with option "disable-ipv6".');
			}
	
			// Check to see if we were given a IP4 $requestIp or $ip by mistake
			if (!filter_var($requestIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
				return self::setCacheResult($cacheKey, false);
			}
	
			if (str_contains($ip, '/')) {
				[$address, $netmask] = explode('/', $ip, 2);
	
				if (!filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
					return self::setCacheResult($cacheKey, false);
				}
	
				if ('0' === $netmask) {
					return (bool) unpack('n*', @inet_pton($address));
				}
	
				if ($netmask < 1 || $netmask > 128) {
					return self::setCacheResult($cacheKey, false);
				}
			} else {
				if (!filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
					return self::setCacheResult($cacheKey, false);
				}
	
				$address = $ip;
				$netmask = 128;
			}
	
			$bytesAddr = unpack('n*', @inet_pton($address));
			$bytesTest = unpack('n*', @inet_pton($requestIp));
	
			if (!$bytesAddr || !$bytesTest) {
				return self::setCacheResult($cacheKey, false);
			}
	
			for ($i = 1, $ceil = ceil($netmask / 16); $i <= $ceil; ++$i) {
				$left = $netmask - 16 * ($i - 1);
				$left = ($left <= 16) ? $left : 16;
				$mask = ~(0xFFFF >> $left) & 0xFFFF;
				if (($bytesAddr[$i] & $mask) != ($bytesTest[$i] & $mask)) {
					return self::setCacheResult($cacheKey, false);
				}
			}
	
			return self::setCacheResult($cacheKey, true);
		}
	
		/**
		 * Anonymizes an IP/IPv6.
		 *
		 * Removes the last byte for v4 and the last 8 bytes for v6 IPs
		 */
		public static function anonymize(string $ip): string
		{
			$wrappedIPv6 = false;
			if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
				$wrappedIPv6 = true;
				$ip = substr($ip, 1, -1);
			}
	
			$packedAddress = inet_pton($ip);
			if (4 === \strlen($packedAddress)) {
				$mask = '255.255.255.0';
			} elseif ($ip === inet_ntop($packedAddress & inet_pton('::ffff:ffff:ffff'))) {
				$mask = '::ffff:ffff:ff00';
			} elseif ($ip === inet_ntop($packedAddress & inet_pton('::ffff:ffff'))) {
				$mask = '::ffff:ff00';
			} else {
				$mask = 'ffff:ffff:ffff:ffff:0000:0000:0000:0000';
			}
			$ip = inet_ntop($packedAddress & inet_pton($mask));
	
			if ($wrappedIPv6) {
				$ip = '['.$ip.']';
			}
	
			return $ip;
		}
	
		private static function getCacheResult(string $cacheKey): ?bool
		{
			if (isset(self::$checkedIps[$cacheKey])) {
				// Move the item last in cache (LRU)
				$value = self::$checkedIps[$cacheKey];
				unset(self::$checkedIps[$cacheKey]);
				self::$checkedIps[$cacheKey] = $value;
	
				return self::$checkedIps[$cacheKey];
			}
	
			return null;
		}
	
		private static function setCacheResult(string $cacheKey, bool $result): bool
		{
			if (1000 < \count(self::$checkedIps)) {
				// stop memory leak if there are many keys
				self::$checkedIps = \array_slice(self::$checkedIps, 500, null, true);
			}
	
			return self::$checkedIps[$cacheKey] = $result;
		}
	}

	trait InteractsWithInput
	{
	    /**
	     * Retrieve a server variable from the request.
	     *
	     * @param  string|null  $key
	     * @param  string|array|null  $default
	     * @return string|array|null
	     */
	    public function server(?string $key = null, mixed $default = null): mixed
	    {
	        return $this->retrieveItem('server', $key, $default);
	    }

	    /**
	     * Determine if a header is set on the request.
	     *
	     * @param  string  $key
	     * @return bool
	     */
	    public function hasHeader(string $key): bool
	    {
	        return ! is_null($this->header($key));
	    }

	    /**
	     * Retrieve a header from the request.
	     *
	     * @param  string|null  $key
	     * @param  string|array|null  $default
	     * @return string|array|null
	     */
	    public function header(?string $key = null, mixed $default = null): mixed
	    {
	        return $this->retrieveItem('headers', $key, $default);
	    }

	    /**
	     * Get the bearer token from the request headers.
	     *
	     * @return string|null
	     */
	    public function bearerToken(): ?string
	    {
	        $header = $this->header('Authorization', '');

	        if (Str::startsWith($header, 'Bearer ')) {
	            return Str::substr($header, 7);
	        }
	    }

	    /**
	     * Determine if the request contains a given input item key.
	     *
	     * @param  string|array  $key
	     * @return bool
	     */
	    public function exists(string $key): bool
	    {
	        return $this->has($key);
	    }

	    /**
	     * Determine if the request contains a given input item key.
	     *
	     * @param  string|array  $key
	     * @return bool
	     */
	    public function has(string $key): bool
	    {
	        $keys = is_array($key) ? $key : func_get_args();

	        $input = $this->all();

	        foreach ($keys as $value) {
	            if (! array_has($input, $value)) {
	                return false;
	            }
	        }

	        return true;
	    }

	    /**
	     * Determine if the request contains any of the given inputs.
	     *
	     * @param  string|array  $keys
	     * @return bool
	     */
	    public function hasAny(string | array $keys): bool
	    {
	        $keys = is_array($keys) ? $keys : func_get_args();

	        $input = $this->all();

	        return Arr::hasAny($input, $keys);
	    }

	    /**
	     * Determine if the request contains a non-empty value for an input item.
	     *
	     * @param  string|array  $key
	     * @return bool
	     */
	    public function filled(string | array $key): bool
	    {
	        $keys = is_array($key) ? $key : func_get_args();

	        foreach ($keys as $value) {
	            if ($this->isEmptyString($value)) {
	                return false;
	            }
	        }

	        return true;
	    }

	    /**
	     * Determine if the request contains a non-empty value for any of the given inputs.
	     *
	     * @param  string|array  $keys
	     * @return bool
	     */
	    public function anyFilled(string | array $keys): bool
	    {
	        $keys = is_array($keys) ? $keys : func_get_args();

	        foreach ($keys as $key) {
	            if ($this->filled($key)) {
	                return true;
	            }
	        }

	        return false;
	    }

	    /**
	     * Determine if the request is missing a given input item key.
	     *
	     * @param  string|array  $key
	     * @return bool
	     */
	    public function missing(string | array $key): bool
	    {
	        $keys = is_array($key) ? $key : func_get_args();

	        return ! $this->has($keys);
	    }

	    /**
	     * Determine if the given input key is an empty string for "has".
	     *
	     * @param  string  $key
	     * @return bool
	     */
	    protected function isEmptyString(string $key): bool
	    {
	        $value = $this->input($key);

	        return ! is_bool($value) && ! is_array($value) && trim((string) $value) === '';
	    }

	    /**
	     * Get the keys for all of the input and files.
	     *
	     * @return array
	     */
	    public function keys(): array
	    {
	        return array_merge(array_keys($this->input()), $this->files->keys());
	    }

	    /**
	     * Get all of the input and files for the request.
	     *
	     * @param  array|mixed|null  $keys
	     * @return array
	     */
	    public function all(mixed $keys = null): array
	    {
	        $input = array_replace_recursive($this->input(), $this->allFiles());

	        if (! $keys) {
	            return $input;
	        }

	        $results = [];

	        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
	            Arr::set($results, $key, Arr::get($input, $key));
	        }

	        return $results;
	    }

	    /**
	     * Retrieve an input item from the request.
	     *
	     * @param  string|null  $key
	     * @param  mixed  $default
	     * @return mixed
	     */
	    public function input(string | null $key = null, mixed $default = null): mixed
	    {
			return $this->getInputSource()->all() + $this->query->all();

	        /*return Arr::flatten(
	            $this->getInputSource()->all() + $this->query->all(), $key, $default
	        );*/
	    }

	    /**
	     * Retrieve input as a boolean value.
	     *
	     * Returns true when value is "1", "true", "on", and "yes". Otherwise, returns false.
	     *
	     * @param  string|null  $key
	     * @param  bool  $default
	     * @return bool
	     */
	    public function boolean(?string $key = null, bool $default = false): bool
	    {
	        return filter_var($this->input($key, $default), FILTER_VALIDATE_BOOLEAN);
	    }

	    /**
	     * Get a subset containing the provided keys with values from the input data.
	     *
	     * @param  array|mixed  $keys
	     * @return array
	     */
	    public function only(mixed $keys): array
	    {
	        $results = [];

	        $input = $this->all();

	        $placeholder = new stdClass();

	        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
	            $value = data_get($input, $key, $placeholder);

	            if ($value !== $placeholder) {
	                Arr::set($results, $key, $value);
	            }
	        }

	        return $results;
	    }

	    /**
	     * Get all of the input except for a specified array of items.
	     *
	     * @param  array|mixed  $keys
	     * @return array
	     */
	    public function except(mixed $keys): array
	    {
	        $keys = is_array($keys) ? $keys : func_get_args();

	        $results = $this->all();

	        Arr::forget($results, $keys);

	        return $results;
	    }

	    /**
	     * Retrieve a query string item from the request.
	     *
	     * @param  string|null  $key
	     * @param  string|array|null  $default
	     * @return string|array|null
	     */
	    public function query(?string $key = null, mixed $default = null): mixed
	    {
	        return $this->retrieveItem('query', $key, $default);
	    }

	    /**
	     * Retrieve a request payload item from the request.
	     *
	     * @param  string|null  $key
	     * @param  string|array|null  $default
	     * @return string|array|null
	     */
	    public function post(?string $key = null, mixed $default = null): mixed
	    {
	        return $this->retrieveItem('request', $key, $default);
	    }

	    /**
	     * Determine if a cookie is set on the request.
	     *
	     * @param  string  $key
	     * @return bool
	     */
	    public function hasCookie(string $key): bool
	    {
	        return ! is_null($this->cookie($key));
	    }

	    /**
	     * Retrieve a cookie from the request.
	     *
	     * @param  string|null  $key
	     * @param  string|array|null  $default
	     * @return string|array|null
	     */
	    public function cookie(?string $key = null, mixed $default = null): mixed
	    {
	        return $this->retrieveItem('cookies', $key, $default);
	    }

	    /**
	     * Get an array of all of the files on the request.
	     *
	     * @return array
	     */
	    public function allFiles(): array
	    {
	        $files = $this->files->all();

	        return $this->convertedFiles = $this->convertedFiles ?? $this->convertUploadedFiles($files);
	    }

	    /**
	     * Convert the given array of Symfony UploadedFiles to custom Laravel UploadedFiles.
	     *
	     * @param  array  $files
	     * @return array
	     */
	    protected function convertUploadedFiles(array $files): array
	    {
			if($this->is_multi($files))
			{
				return array_map([$this, "convertUploadedFiles"], $files);
			}

	        return array_map(function($file)
	        {
	            if (is_null($file) || (is_array($file) && empty(array_filter($file))))
	            {
	                return $file;
	            }

				$file = (is_array($file)) ? new File($file['tmp_name'], $file['name'], $file['error']) : $file;

	            return is_array($file)
	                        ? $this->convertUploadedFiles($file)
	                        : UploadedFile::createFromBase($file);
	        }, $files);
	    }

	    /**
	     * Determine if the uploaded data contains a file.
	     *
	     * @param  string  $key
	     * @return bool
	     */
	    public function hasFile(string $key): bool
	    {
	        if (! is_array($files = $this->file($key))) {
	            $files = [$files];
	        }

	        foreach ($files as $file) {
	            if ($this->isValidFile($file)) {
	                return true;
	            }
	        }

	        return false;
	    }

	    /**
	     * Check that the given file is a valid file instance.
	     *
	     * @param  mixed  $file
	     * @return bool
	     */
	    protected function isValidFile(mixed $file): bool
	    {
	        return $file instanceof SplFileInfo && $file->getPath() !== '';
	    }

	    /**
	     * Retrieve a file from the request.
	     *
	     * @param  string|null  $key
	     * @param  mixed  $default
	     * @return \Illuminate\Http\UploadedFile|\Illuminate\Http\UploadedFile[]|array|null
	     */
	    public function file(?string $key = null, mixed $default = null): mixed
	    {
	        return data_get($this->allFiles(), $key, $default);
	    }

	    /**
	     * Retrieve a parameter item from a given source.
	     *
	     * @param  string  $source
	     * @param  string  $key
	     * @param  string|array|null  $default
	     * @return string|array|null
	     */
	    protected function retrieveItem(string $source, string $key, mixed $default): mixed
	    {
	        if (is_null($key)) 
			{
	            return $this->$source->all();
	        }

	        return $this->$source->get($key, $default);
	    }

		protected function is_multi(array $_array): bool
		{
			$rv = array_filter($_array, 'is_array');
			  
			if(count($rv) == 0)
			{
				return false;
			}
			  
			return true;
		}
	}
?>