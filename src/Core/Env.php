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
			elseif(is_array(self::getInstance()->parameters[$_key]))
			{
				/**
				 * 	Return first item if string, array or bool
				 */
				if(isset(self::getInstance()->parameters[$_key][0]) && in_array(gettype(self::getInstance()->parameters[$_key][0]), ["string", "boolean", "array"]))
				{
					return self::getInstance()->parameters[$_key][0];
				}
			}

			return self::getInstance()->parameters[$_key];

			//return (isset(self::getInstance()->parameters[$_key][0]) && is_array(self::getInstance()->parameters[$_key][0])) ? self::getInstance()->parameters[$_key][0] : self::getInstance()->parameters[$_key];
		}

		public static function set($_key, $_value)
		{
			self::getInstance()->parameters[strtolower($_key)] = $_value;
		}

		public function merge($_params)
		{
			foreach($_params AS $k => $v)
			{
				self::set($k, $v);
			}
			
			return self::getInstance();
		}

		/**
		 *	Never return DB nor protected assets
		 */
		public static function getAll()
		{
			$return = array_filter(self::getInstance()->parameters, fn($e) => !isset($e[1]) || $e[1] != 999);

			unset($return['db']);

			return $return;
		}
	}
?>