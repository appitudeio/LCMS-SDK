<?php
	/**
	 *	2023-11-07: Added support for PHP 8.3 with __set + __get
	 */
	namespace LCMS;

	use LCMS\Core\Request;
	use LCMS\Page;

	abstract class Controller
	{
		private $magic_methods = ['middleware', 'before', 'after', 'first', 'last'];
		private array $properties = [];

		private function first(...$args){}
		private function middleware(...$args){}
		private function after(...$args){}

	    /**
	     * Magic method called when a non-existent or inaccessible method is
	     * called on an object of this class. Used to execute before and after
	     * filter methods on action methods. Action methods need to be named
	     * with an "Action" suffix, e.g. indexAction, showAction etc.
	     *
	     * @param string $name  Method name
	     * @param array $args Arguments passed to the method
	     *
	     * @return void
	     */
	    public function __call(string $name, array $arguments): mixed
	    {
			if(in_array($name, $this->magic_methods))
			{
				if(!method_exists($this, $name))
				{
					return false;
				}

				return $this->$name(...$arguments);
			}
	        elseif(!method_exists($this, $name))
	        {
	        	throw new \Exception("Method ".$name." not found in controller " . get_class($this));
	        }
	    }

		public function __get(string $name): mixed
		{
			return $this->properties[$name] ?? false;
		}

		public function __set(string $name, mixed $value): void
		{
			$this->properties[$name] = $value;
		}

		public function __unset(string $name): void
		{
			unset($this->properties[$name]);
		}

		public function __isset(string $name): bool
		{
			return (bool) isset($this->properties[$name]);
		}

	}
?>