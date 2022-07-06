<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;
	use \Exception;

	class Redirect
	{
		use \LCMS\Utils\Singleton;

		private static $to;
		private static $code;

		public static function to($_url = null): Self
		{
			self::$to = (isset(parse_url($_url)['scheme'])) ? $_url : "/" . ltrim($_url, "/");

			return self::getInstance($_url);
		}

		public static function route($_route_alias, $_arguments = null): Self
		{
			self::$to = Route::url($_route_alias, $_arguments);

			return self::getInstance();
		}

		public static function back(): Self
		{
			self::$to = Request::headers()->get('referer') ?? Env::get("app_path");

			return self::getInstance();
		}

		/**
		 *
		 */
		public static function with($key, $value): Self
		{
			if(is_array($key))
			{
				foreach($key AS $k => $v)
				{
					Request::session()->flash($k, $v);
				}
			}
			else
			{
				Request::session()->flash($key, $value);
			}

			return self::getInstance();
		}

		public static function code(int $_code): Self
		{
			self::$code = $_code;

			return self::getInstance();
		}

		public static function dispatch()
		{
			if(self::$code)
			{
				http_response_code(self::$code);
			}

			Header("Location: " . self::$to);
			exit();
		}

		function __toString()
		{
			return "__toString() -> FromWithinRedirectClass";
		}
	}
?>
