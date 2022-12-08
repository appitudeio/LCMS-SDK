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

		protected function to($_url = null): self
		{
			$this->to = (isset(parse_url($_url)['scheme'])) ? $_url : "/" . ltrim($_url, "/");

			return $this;
		}

		protected function route($_route_alias, $_arguments = null): self
		{
			$this->to = Route::url($_route_alias, $_arguments);

			return $this;
		}

		protected function back(): self
		{
			$this->to = Request::headers()->get('referer') ?? Env::get("app_path");

			return $this;
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

		protected function code(int $_code): self
		{
			$this->code = $_code;

			return $this;
		}

		public static function dispatch(): void
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
