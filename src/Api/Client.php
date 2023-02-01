<?php
	/** 
	 *
	 */
	namespace LCMS\Api;

	use LCMS\Core\Request;
	use LCMS\Core\Env;
	use \ReflectionClass;
	use \Exception;

	class Client
	{
		private static $instance;
		private $instances = array();
		public static $ips = array("16.170.44.93");

		/**
		 *	Makes instances of the application reachable through this class, statically
		 */
		public static function initialize($_objects = array())
		{
			$self = new Static();

			foreach($_objects AS $obj)
			{
				$reflection = new ReflectionClass($obj);
				$self->instances[$reflection->getShortName()] = $obj;
			}

			self::$instance = $self;

			return self::$instance;
		}

		public static function set($_object)
		{
			$reflection = new ReflectionClass($_object);
			self::$instance->instances[$reflection->getShortName()] = $_object;
		}

		public static function __callStatic($_method, $_arguments)
		{
			if(!isset(self::$instance->instances[$_method]))
			{
				throw new Exception("Trying to use non existing instance (".$_method.") from: " . implode(", " , array_keys(self::$instance->instances)));
			}

			return self::$instance->instances[$_method];
		}

		public function validate(Request $_request)
		{
			/**
			 *	If Request comes from the CMS, this is a "FieldCreating"-request 
			 */
			if(!in_array($_request->ip(), self::$ips))
			{
				throw new Exception("Request outside of LCMS");
			}
			elseif(!$_request->has("api_key"))
			{
				throw new Exception("API_KEY missing");
			}
			elseif($_request->get("api_key") !== Env::get("API_KEY"))
			{
				throw new Exception("Wrong API_KEY");
			}

			return true;
		}
	}
?>