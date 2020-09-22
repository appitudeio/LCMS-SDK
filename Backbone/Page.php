<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Core\Request;
	use LCMS\Core\Response;
	use LCMS\Backbone\View;
	use LCMS\Backbone\TemplateEngine;
	use \Exception;

	class Page
	{
		public $parameters = array();
		public $controller;
		public $action;
		private $compilation;
		private $route;

		function __construct($_route_array)
		{
			$this->controller 	= $_route_array['controller'];
			$this->action 		= $_route_array['action'];

			$this->route = $_route_array;

			if(empty($this->route['alias']))
			{
				unset($this->route['alias']);
			}
			
			if(isset($_route_array['parameters']))
			{
				$this->setParameters($_route_array['parameters']);
			}
		}

		public function setParameters($_params)
		{
			if(!empty($this->parameters))
			{
				$this->parameters = array_merge($this->parameters, $_params);
			}
			else
			{
				$this->parameters = $_params;
			}

			return $this;
		}

		public function compile()
		{
            $action = $this->action;

            // Returns HTML from View
           	$this->compilation = $this->controller->$action(...array_values($this->parameters));

           	if(empty($this->compilation))
           	{
           		throw new Exception("Return value from controller cant be Void");
           	}

           	if($this->compilation instanceof View)
           	{
           		$this->compilation->setPage($this);
			}

            $this->controller->after(); // Cleanup

            return $this->compilation;
		}

		public function render()
		{
			return $this->compilation; // HTML from View
		}

		public function __get($_key)
		{
			return $this->route[$_key];
		}

		public function __isset($_key)
		{
			return isset($this->route[$_key]);
		}

		/**
		 *
		 */
		public function __toString()
		{
			if(empty($this->compilation))
			{
				throw new Exception("Must run Compile first");
			}
			
			return (string) $this->render();
		}
	}
?>