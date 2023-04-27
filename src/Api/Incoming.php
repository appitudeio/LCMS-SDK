<?php
    /**
     *  Dispatcher API Client v0.1
     */
    namespace LCMS\Api;

    use LCMS\Api\Client;
    use LCMS\Core\Request;
    use LCMS\Core\Env;

    use \ReflectionClass;
    use \Exception;

    class Incoming extends Client
    {
        public $lcms_ips = array("16.170.44.93");
        private $instances = array();

		public static function set($_object)
		{
			$reflection = new ReflectionClass($_object);
			self::getInstance()->instances[$reflection->getShortName()] = $_object;
		}

		public static function __callStatic($_method, $_arguments)
		{
			if(!isset(self::getInstance()->instances[$_method]))
			{
				throw new Exception("Trying to use non existing instance (".$_method.") from: " . implode(", " , array_keys(self::getInstance()->instances)));
			}

			return self::getInstance()->instances[$_method];
		}        

		public function validate(Request $_request)
		{
			/**
			 *	If Request comes from the CMS, this is a "FieldCreating"-request 
			 */
			if(!in_array($_request->ip(), self::getInstance()->lcms_ips))
			{
				throw new Exception("Request outside of LCMS");
			}
			elseif(!$_request->has("api_key"))
			{
				throw new Exception("API_KEY missing");
			}
			elseif($_request->get("api_key") !== Env::get("lcms_api_key"))
			{
				throw new Exception("API_KEY mismatch");
			}

			return true;
		}
    }
?>