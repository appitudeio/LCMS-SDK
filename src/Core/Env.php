<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use \Exception;

	class Env
	{
		const TYPE_INPUT		= 1;
		const TYPE_BOOL 		= 2;
		const TYPE_PROTECTED 	= 999;

		private static $instance;
		private $parameters = array();

		public static function getInstance()
		{
			if(self::$instance == null)
			{
				self::$instance = new static();
			}

			return self::$instance;
		}

		public static function get($_key)
		{
			$_key = strtolower($_key);
			
			if(!isset(self::getInstance()->parameters[$_key]))
			{
				return "";
			}

			if(is_string(self::getInstance()->parameters[$_key]))
			{
				return self::getInstance()->parameters[$_key];
			}

			return (isset(self::getInstance()->parameters[$_key][0]) && is_array(self::getInstance()->parameters[$_key][0])) ? self::getInstance()->parameters[$_key][0] : self::getInstance()->parameters[$_key];
		}

		public function merge($_params)
		{
			foreach($_params AS $k => $v)
			{
				self::getInstance()->parameters[strtolower($k)] = $v;
			}
			
			return self::getInstance();
		}

		/**
		 *	Never return DB
		 */
		public static function getAll()
		{
			$return = self::getInstance()->parameters;
			unset($return['db']);

			return $return;
		}
	}
?>