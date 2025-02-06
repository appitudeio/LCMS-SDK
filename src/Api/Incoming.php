<?php
	namespace LCMS\Api;

	use LCMS\Core\Request;
	use LCMS\Core\Env;
	use LCMS\Api\Client;
	use LCMS\Util\Singleton;
	use Exception;

	class Incoming extends Client
	{
		use Singleton;
		
		/**
		 * Allowed LCMS IP addresses.
		 */
		private array $allowedIps = ["16.170.44.93"];
		
		/**
		 * Register an object instance for later retrieval.
		 */
		public static function set($_object): void
		{
			$reflection = new \ReflectionClass($_object);
			self::getInstance()->instances[$reflection->getShortName()] = $_object;
		}
		
		/**
		 * Magic static call to retrieve previously stored instances.
		 *
		 * @param string $_method
		 * @param array $_args
		 * @return mixed
		 * @throws Exception
		 */
		public static function __callStatic(string $_method, array $_args)
		{
			if (!isset(self::getInstance()->instances[$_method])) 
			{
				throw new Exception("Trying to use non-existing instance ('{$_method}'). Available: " . implode(", ", array_keys(self::getInstance()->instances)));
			}
			
			return self::getInstance()->instances[$_method];
		}
		
		/**
		 * Validate an incoming request.
		 *
		 * @param Request $request
		 * @return bool
		 * @throws Exception
		 */
		public function validateRequest(Request $request): bool
		{
			if (!in_array($request->ip(), $this->allowedIps)) 
			{
				throw new Exception("Request outside of LCMS");
			}
			elseif (!$request->has("api_key")) 
			{
				throw new Exception("API_KEY missing");
			}
			elseif ($request->get("api_key") !== Env::get("lcms_api_key")) 
			{
				throw new Exception("API_KEY mismatch");
			}
			
			return true;
		}
	}
?>