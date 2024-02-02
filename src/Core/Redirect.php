<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;
	use LCMS\Util\Singleton;

	class Redirect
	{
		use Singleton;

		private $to;
		private $code;

		protected function to(string $_url = null): self
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

		protected function with(string | array $key, mixed $value = null): self
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

			return $this;
		}

		protected function code(int $_code): self
		{
			$this->code = $_code;

			return $this;
		}

		protected function dispatch(): never
		{
			if($this->code)
			{
				http_response_code($this->code);
			}

			header("Location: " . $this->to);
			exit();
		}
	}
?>