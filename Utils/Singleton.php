<?php
	/**
	 *
	 */
	namespace LCMS\Utils;

	trait Singleton 
	{
	    private static $instance;

	    function __construct()
	    {
	    	self::$instance = $this;
	    }

	    public static function getInstance() 
	    {
			if(self::$instance == null)
			{
				self::$instance = new static();
			}

			return self::$instance;
	    }
	}
?>