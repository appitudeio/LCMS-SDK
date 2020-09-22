<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use \Exception;

	class Redirect
	{
		private static $instance;

		public static function getInstance()
		{
			if(self::$instance == null)
			{
				self::$instance = new static();
			}

			return self::$instance;
		}

		public static function to($_url = null)
		{
			if(!empty($_url))
			{
				return self::send($_url);
			}

			return self::getInstance();
		}

		public static function route($_route_alias)
		{
			$route_url = Route::url($_route_alias);

			return self::send($route_url);
		}

		/**
		 *
		 */
		public static function with($key, $value)
		{
			Session::flash($key, $value);

			return self::getInstance();
		}

		private static function send($_to_url)
		{
			Header("Location: " . $_to_url);
			exit();
		}

		function __toString()
		{
			return "FromWithingRedirectClass";
		}
	}
?>