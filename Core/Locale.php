<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;

	class Locale
	{
		private static $language = "en";
		private static $instance;

		public static function getInstance()
		{
			if(self::$instance == null)
			{
				self::$instance = new static();
			}

			return self::$instance;
		}

		public static function setLanguage($_language)
		{
			self::$language = $_language;
		}

		/**
		 *	Parse Locale from URL
		 */
		public static function setFrom(Request $_request, $_callback = null)
		{
			$segments = $_request->segments();

			if(!empty($segments) && strlen($segments[0]) == 2 && is_file(ROOT_PATH . "/i18n/" . strtolower($segments[0]).".ini"))
			{
				self::$language = strtolower($segments[0]);

				if(gettype($_callback) == "object")
				{
					return $_callback(self::$language);
				}
			}
		}

		public static function getLanguage()
		{
			return self::$language;
		}
	}
?>