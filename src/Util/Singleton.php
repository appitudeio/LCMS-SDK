<?php
	/**
	 *	2022-12-03: Updated with __callStatic
	 */
	namespace LCMS\Util;

	use LCMS\DI;

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
	    public static function getInstance()
	    {
			if(false === self::$instance)
			{
				self::$instance = (static::class == "LCMS\\DI") ? new self : DI::get(static::class);
			}

			return self::$instance;
	    }

		static function __callStatic(string $_method, array $_args): mixed
		{
			return self::getInstance()->$_method(...$_args);
		}
	}
?>