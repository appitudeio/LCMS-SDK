<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Util\Singleton;

	use \Exception;

	class Env
	{
		use Singleton;

		const TYPE_INPUT		= 1;
		const TYPE_BOOL 		= 2;
		const TYPE_PROTECTED 	= 999;

		private $parameters = array();

		public static function get(string $_key, mixed $_default_value = null): mixed
		{
			$_key = strtolower($_key);
			
			if(!isset(self::getInstance()->parameters[$_key]))
			{
				return $_default_value;
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
		}

		public static function set(string $_key, mixed $_value): void
		{
			self::getInstance()->parameters[strtolower($_key)] = $_value;
		}

		public static function merge(array $_params): self
		{
			foreach($_params AS $k => $v)
			{
				self::getInstance()->set($k, $v);
			}
			
			return self::getInstance();
		}

		/**
		 *	Never return DB nor protected assets
		 *	- nor objects
		 */
		public static function getAll(): array
		{
			$return = array_filter(self::getInstance()->parameters, function($e)
			{
				if(is_object($e) || (is_array($e) && array_is_list($e) && is_object($e[0])))
				{
					return false;
				}
				elseif(is_array($e) && array_is_list($e) && (!isset($e[1]) || $e[1] != 999))
				{
					return true;
				}

				return true;
			});

			// If key == db, db_web, web_db
			foreach($return AS $key => $value)
			{
				if($key == "db" || str_starts_with($key, "db_") || str_ends_with($key, "_db"))
				{
					unset($return[$key]);
				}
			}

			return $return;
		}

		public function __invoke(string $_key, mixed $_default_value = null)
		{
			return $this->get($_key, $_default_value);
		}
	}
?>