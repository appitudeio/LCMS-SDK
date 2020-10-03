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
			/*if(!empty($_url))
			{
				return self::send($_url);
			}*/

			self::$to = $_url;

			return self::getInstance($_url);
		}

		public static function route($_route_alias)
		{
			self::$to = Route::url($_route_alias);

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