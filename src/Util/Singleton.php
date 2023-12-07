<?php
	/**
	 *	2022-12-03: Updated with __callStatic
	 */
	namespace LCMS\Util;

	use LCMS\DI;

	use \Exception;

	trait Singleton 
	{
	    private static $instance = false;

	    function __construct()
	    {
	    	self::$instance = $this;
	    }

		/**
		 * 	Returns Dependency injection instead of self
		 */
	    public static function getInstance(): self
	    {
			if(false === self::$instance)
			{
				self::$instance = (static::class == "LCMS\\DI") ? new self : DI::get(static::class);
			}

			return self::$instance;
	    }
		
		public function __call(string $name, array $arguments): mixed
		{
			if(method_exists($this, $name))
			{
				return $this->$name(...$arguments);
			}
			elseif(isset($this->$name))
			{
				return $this->$name;
			}
			
			throw new Exception("Undefined class method: " . $name);
		}		

		static function __callStatic(string $name, array $arguments): mixed
		{
			if(empty($arguments) && method_exists(self::getInstance(), $name))
			{
				return DI::call([self::getInstance(), $name]);
			}
			
			return self::getInstance()->$name(...$arguments);
		}
	}
?>