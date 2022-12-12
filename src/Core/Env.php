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
		 */
		public static function getAll(): array
		{
			$return = array_filter(self::getInstance()->parameters, fn($e) => !isset($e[1]) || $e[1] != 999);

			unset($return['db']);

			return $return;
		}

		public function __invoke(string $_key, mixed $_default_value = null)
		{
			return $this->get($_key, $_default_value);
		}
	}
?>