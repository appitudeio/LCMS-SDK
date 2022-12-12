<?php
	/**
	 *
	 */
	namespace LCMS;

	use LCMS\Core\Request;
	use LCMS\Page;

	abstract class Controller
	{
		//protected $route;
		protected $page;
		private $magic_methods = ['middleware', 'before', 'after', 'first', 'last'];

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
	    public function __call(string $_action, array $_arguments)
	    {
			if(in_array($_action, $this->magic_methods))
			{
				if(!method_exists($this, $_action))
				{
					return;
				}

				return $this->$_action(...$_arguments);
			}
	        elseif(!method_exists($this, $_action))
	        {
	        	throw new \Exception("Method ".$_action." not found in controller " . get_class($this));
	        }
	    }

		private function first(...$args){}
		private function middleware(...$args){}
		private function after(...$args){}
	}
?>