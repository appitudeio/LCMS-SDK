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

		public static function to($_url = null)
		{
			self::$to = (isset(parse_url($_url)['scheme'])) ? $_url : "/" . ltrim($_url, "/");

			return self::getInstance($_url);
		}

		public static function route($_route_alias, $_arguments = null)
		{
			self::$to = Route::url($_route_alias, $_arguments);

			return self::getInstance();
		}

		public static function back()
		{
			self::$to = Request::headers()->get('referer') ?? Env::get("app_path");

			return self::getInstance();
		}

		/**
		 *
		 */
		public static function with($key, $value)
		{
			Request::session()->flash($key, $value);

			return self::getInstance();
		}

		public static function dispatch()
		{
			Header("Location: " . self::$to);
			exit();
		}

		function __toString()
		{
			return "FromWithingRedirectClass";
		}
	}
?>
