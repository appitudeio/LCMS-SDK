<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Core\Request;
	use LCMS\Backbone\Page;

	abstract class Controller
	{
		protected $request;

		function __construct(Request $_request = null)
		{
			if(!empty($_request))
			{
				$this->request = $_request;
			}
		}

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
	    public function __call($_action, $args)
	    {
	        if(!method_exists($this, $_action))
	        {
	        	throw new \Exception("Method ".$_action." not found in controller " . get_class($this));
	        }
	    }

	    /**
	     * Before filter - called before an action method.
	     *
	     * @return void
	     */
	    protected function middleware()
	    {
	    }

	    /**
	     * After filter - called after an action method.
	     *
	     * @return void
	     */
	    protected function after()
	    {
	    }

	    /**
	     *
	     */
	    protected function first()
	    {	
	    }
	}
?>