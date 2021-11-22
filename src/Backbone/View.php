<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Utils\Singleton;
	use \Exception;

	class View implements \Iterator
	{
		use Singleton;

		private $data = array();
		private $view_file;
		private $page;
		private $keys;
		private $index = 0;

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
			elseif(is_array($value))
			{
				if(isset(self::getInstance()->data[$key]) && !is_array(self::getInstance()->data[$key]))
				{
					self::getInstance()->data[$key] = array(self::getInstance()->data[$key]);
				}
				elseif(!isset(self::getInstance()->data[$key]))
				{
					self::getInstance()->data[$key] = array();
				}

				self::getInstance()->data[$key] += $value;
			}
			elseif($value instanceof \Closure)
			{
				self::getInstance()->data[$key] = $value();
			}
			else
			{
				self::getInstance()->data[$key] = $value;
			}

			return self::getInstance();
		}

		public static function make($_view, $_with = null)
		{
			$file = str_replace(".", "/", $_view);
			$file = getcwd() . "/App/Views/" . $file . ".php";  // relative to Root

			if(!is_readable($file)) 
			{
				throw new Exception($file . " not found");
			}

			self::getInstance()->view_file = $file;

			if(is_array($_with))
			{
				foreach($_with AS $key => $value)
				{
					self::getInstance()->with($key, $value);
				}
			}

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
	    		self::make($_view, $_data);
	    	}

	    	return self::getInstance()->renderContent($_data);
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
	
		public function rewind() 
		{
			$this->keys = array_keys($this->data);
			$this->index = 0;
		}
	
		public function current() 
		{
			return $this->data[$this->key()];
		}
	
		public function key() 
		{
			return $this->keys[$this->index];
		}
	
		public function next() 
		{
			++$this->index;
		}
	
		public function valid() 
		{
			return isset($this->keys[$this->index]);
		}
	}
?>