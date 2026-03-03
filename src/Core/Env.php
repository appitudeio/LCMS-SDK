<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Util\Singleton;	

	class Env
	{
		use Singleton {
			Singleton::__construct as private SingletonConstructor;
		}

		const TYPE_INPUT		= 1;
		const TYPE_BOOL 		= 2;
		const TYPE_PROTECTED 	= 999;

		private $parameters = array();

		protected function get(string $_key, mixed $_default_value = null): mixed
		{
			$_key = strtolower($_key);
			
			if(!isset($this->parameters[$_key]))
			{
				return $_default_value;
			}

			if(is_string($this->parameters[$_key]))
			{
				return $this->parameters[$_key];
			}
			elseif(is_array($this->parameters[$_key]))
			{
				/**
				 * 	Return first item if string, array or bool
				 */
				if(isset($this->parameters[$_key][0]) && in_array(gettype($this->parameters[$_key][0]), ["string", "boolean", "array"]))
				{
					return $this->parameters[$_key][0];
				}
			}

			return $this->parameters[$_key];
		}

		protected function set(string $_key, mixed $_value): void
		{
			$this->parameters[strtolower($_key)] = $_value;
		}

		protected function merge(array $_params): self
		{
			foreach($_params AS $k => $v)
			{
				$this->set($k, $v);
			}
			
			return $this;
		}

		/**
		 *	Never return DB nor protected assets
		 *	- nor objects
		 */
		protected function getAll(): array
		{
			$return = array_filter($this->parameters, fn($e) => !is_array($e) || is_array($e) && array_is_list($e) && (!isset($e[1]) || $e[1] != 999));

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