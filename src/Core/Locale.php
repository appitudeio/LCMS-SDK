<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;

	class Locale
	{
		use \LCMS\Utils\Singleton;
		
		private $language = "en";
		private $is_default = false;
		
		public static function setLanguage($_language, $_is_default = false)
		{
			self::getInstance()->language = $_language;
			self::getInstance()->is_default = $_is_default;
		}

		/**
		 *	Parse Locale from URL
		 */
		public static function setFrom(Request $_request, string $i18n_path, \Closure $_callback = null)
		{
			$segments = $_request->segments();

			if(!empty($segments) && strlen($segments[0]) == 2 && is_file($i18n_path . "/" . strtolower($segments[0]).".ini"))
			{
				self::getInstance()->language = strtolower($segments[0]);

				if(gettype($_callback) == "object")
				{
					return $_callback(self::getInstance()->language);
				}
			}
		}

		public static function getLanguage(): String
		{
			return self::getInstance()->language;
		}

		public static function isDefault(): Bool
		{
			return self::getInstance()->is_default;
		}
	}
?>