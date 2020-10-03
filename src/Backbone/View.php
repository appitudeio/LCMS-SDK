<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use \Exception;

	class View
	{
		private $data = array();
		private $view_file;
		private $page;
		private static $instance;

		public static function getInstance()
		{
			if(self::$instance == null)
			{
				self::$instance = new static();
			}

			return self::$instance;
		}		

		/**
		 * Get a piece of data from the view.
		 *
		 * @param  string  $key
		 * @return mixed
		 */
		public function &__get($key)
		{
		 	return $this->data[$key];
		}

		/**
		 * Set a piece of data on the view.
		 *
		 * @param  string  $key
		 * @param  mixed  $value
		 * @return void
		 */
		public function __set($key, $value)
		{
			$this->with($key, $value);
		}

		/**
		 * Check if a piece of data is bound to the view.
		 *
		 * @param  string  $key
		 * @return bool
		 */
		public function __isset($key)
		{
			return isset($this->data[$key]);
		}

		/**
		 * Add a piece of data to the view.
		 *
		 * @param  string|array  $key
		 * @param  mixed  $value
		 * @return $this
		 */
		public static function with($key, $value = null)
		{
			if(is_array($key)) 
			{
				self::getInstance()->data = array_merge(self::getInstance()->data, $key);
			} 
			else 
			{
				self::getInstance()->data[$key] = $value;
			}

			return self::getInstance();
		}

		public static function make($_view)
		{
			//$dir = (defined("ROOT_PATH")) ? ROOT_PATH : dirname(__DIR__);

			$file = str_replace(".", "/", $_view);
			$file = getcwd() . "/App/Views/" . $file . ".php";  // relative to Core directory

			if(!is_readable($file)) 
			{
				throw new \Exception($file . " not found");
			}

			self::getInstance()->view_file = $file;

			return self::getInstance();
		}

		public function setPage(Page $_page)
		{
			$this->page = $_page;
		}

		public function getPage()
		{
			return $this->page;
		}

		private function renderContent($_data = null)
		{
       	 	$obLevel = ob_get_level();

        	ob_start();

	    	/**
	    	 *	Convert $_args('key' => 'value') into $key = 'value'
	    	 */
	    	if(!empty(self::getInstance()->data))
	    	{
	        	extract(self::getInstance()->data, EXTR_SKIP);
	        }

	        if(!empty($_data))
	        {
	        	extract($_data, EXTR_SKIP);
	        }

	        include self::getInstance()->view_file;

	        return ltrim(ob_get_clean());

	        //return ltrim(ob_get_clean());

			// We'll evaluate the contents of the view inside a try/catch block so we can
			// flush out any stray output that might get out before an error occurs or
			// an exception is thrown. This prevents any partial views from leaking.
			/*try 
			{
				include $__path;
			} 
			catch (Exception $e) 
			{
				$this->handleViewException($e, $obLevel);
			}*/
		}

	    /**
	     * Render a view file
	     *
	     * @param string $view  The view file
	     * @param array $args  Associative array of data to display in the view (optional)
	     *
	     * @return void
	     */
	    public static function render($_view = null, $_data = null)
	    {
	    	if(!empty($_view))
	    	{
	    		self::make($_view);
	    	}

	    	$content = self::getInstance()->renderContent($_data);

	    	return $content;
	    }

		/**
		 * Get the string contents of the view.
		 *
		 * @return string
		 *
		 * @throws \Throwable
		 */
	    public function __toString()
	    {
	    	return (string) $this->render();
	    }
	}
?>