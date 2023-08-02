<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;
	use LCMS\Util\Singleton;
	use \Exception;

	class Redirect
	{
		use Singleton;

		private $to;
		private $code;

		public static function to($_url = null): self
		{
			self::getInstance()->to = (isset(parse_url($_url)['scheme'])) ? $_url : "/" . ltrim($_url, "/");

			return self::getInstance();
		}

		public static function route($_route_alias, $_arguments = null): self
		{
			self::getInstance()->to = Route::url($_route_alias, $_arguments);

			return self::getInstance();
		}

		public static function back(): self
		{
			self::getInstance()->to = Request::headers()->get('referer') ?? Env::get("app_path");

			return self::getInstance();
		}

		/**
		 *	This method requires to be static
		 */
		public static function with(string | array $key, $value = null): self
		{
			if(is_array($key))
			{
				foreach($key AS $k => $v)
				{
					Request::session()->flash($k, $v);
				}
			}
			else
			{
				Request::session()->flash($key, $value);
			}

			return self::getInstance();
		}

		public function code(int $_code): self
		{
			$this->code = $_code;

			return $this;
		}

		public static function dispatch(): never
		{
			if(self::getInstance()->code)
			{
				http_response_code(self::getInstance()->code);
			}

			Header("Location: " . self::getInstance()->to);
			exit();
		}
	}
?>