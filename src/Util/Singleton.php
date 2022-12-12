<?php
	/**
	 *	2022-12-03: Updated with __callStatic
	 */
	namespace LCMS\Util;

	trait Singleton 
	{
	    private static $instance;

	    function __construct()
	    {
	    	self::$instance = $this;
	    }

	    public static function getInstance()
	    {
			if(!(self::$instance instanceof self)) 
			{
				self::$instance = (str_ends_with(static::class, "\\DI")) ? new self : \LCMS\DI::get(static::class);
			}

			return self::$instance;
	    }

		static function __callStatic($name, $arguments)
		{
			return self::getInstance()->$name(...$arguments);
		}
	}
?>