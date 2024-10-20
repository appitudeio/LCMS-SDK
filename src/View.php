<?php
	/**
	 *
	 */
	namespace LCMS;

	use LCMS\DI;
	use LCMS\Page;
	use LCMS\Util\Singleton;

	use \Iterator;
	use \Closure;
	use \Exception;

	class View implements Iterator
	{
		use Singleton;

		private array 	$data = array();
		private string 	$view_file;
		private array 	$keys;
		private int 	$index = 0;

		protected function get(string $name): mixed
		{
			return $this->data[$name] ?? DI::get($name);
		}

		protected function has(string $name): bool
		{
			return (isset($this->data[$name]) || DI::has($name));
		}

		protected function all(): array
		{
			return $this->data;
		}

		/**
		 * Get a piece of data from the view.
		 *
		 * @param  string  $key
		 * @return mixed
		 */
		public function &__get(string $name): mixed
		{
		 	return $this->data[$name];
		}

		/**
		 * Set a piece of data on the view.
		 *
		 * @param  string  $key
		 * @param  mixed  $value
		 * @return void
		 */
		public function __set(string $name, mixed $value): void
		{
			$this->with($name, $value);
		}

		/**
		 * Check if a piece of data is bound to the view.
		 *
		 * @param  string  $key
		 * @return bool
		 */
		public function __isset(string $name): bool
		{
			return isset($this->data[$name]);
		}

		/**
		 * Add a piece of data to the view.
		 *
		 * @param  string|array  $key
		 * @param  mixed  $value
		 * @return $this
		 */
		public static function with(array | string $key, mixed $value = null): self
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

				self::getInstance()->data[$key] += $value; // Keeps keys
			}
			elseif($value instanceof Closure)
			{
				self::getInstance()->data[$key] = $value; //();
			}
			else
			{
				self::getInstance()->data[$key] = $value;
			}

			return self::getInstance();
		}

		public static function make(string $_view, mixed $_with = null): self
		{
			if(!$ext = pathinfo($_view, PATHINFO_EXTENSION))
			{
				$_view = str_replace(".", "/", $_view) . ".php";
			}
			
			$file = getcwd() . "/../App/Views/" . $_view;  // relative to Root

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

		protected function getPage(): Page
		{
			return DI::get(Page::class);
		}

		public function renderContent(mixed $_data = null): string
		{
       	 	$obLevel = ob_get_level();

        	ob_start();

	    	/**
	    	 *	Convert $_args('key' => 'value') into $key = 'value'
	    	 */
	        if(!empty($_data))
	        {
	        	extract($_data, EXTR_SKIP);
	        }

	    	if(!empty($this->data))
	    	{
	        	extract($this->data, EXTR_SKIP);
	        }
			
	        include $this->view_file;
			
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
	    public static function render(string $_view = null, mixed $_data = null): string
	    {
	    	if(!empty($_view))
	    	{
	    		self::getInstance()->make($_view, $_data);
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
	    public function __toString(): string
	    {
	    	return (string) $this->render();
	    }
	
		public function rewind(): void
		{
			$this->keys = array_keys($this->data);
			$this->index = 0;
		}
	
		public function current(): mixed
		{
			return $this->data[$this->key()];
		}
	
		public function key(): mixed
		{
			return $this->keys[$this->index];
		}
	
		public function next(): void
		{
			++$this->index;
		}
	
		public function valid(): bool
		{
			return isset($this->keys[$this->index]);
		}
	}
?>