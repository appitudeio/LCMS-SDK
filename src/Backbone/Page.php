<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

	use LCMS\Core\Request;
	use LCMS\Core\Node;
	use LCMS\Core\Locale;
	use LCMS\Backbone\View;
	use LCMS\Backbone\SEO;
	use LCMS\Utils\Arr;
	use \Exception;

	class Page
	{
		public $parameters = array();
		public $controller;
		public $action;
		private $compilation;
		private $route;
		private $settings = array();
		private $meta = array(
			'title'			=> null,
			'description'	=> null
		);

		function __construct($_route_array)
		{
			$this->controller 	= $_route_array['controller'];
			$this->action 		= $_route_array['action'];

			$this->route = $_route_array;

			if(empty($this->route['alias']))
			{
				unset($this->route['alias']);
			}
			
			if(isset($_route_array['parameters']) && !empty($_route_array['parameters']))
			{
				$this->setParameters($_route_array['parameters']);
			}

			if(isset($_route_array['settings']) && !empty($_route_array['settings']))
			{
				$this->settings($_route_array['settings']);
			}
		}

		private function initMeta(View $_view): Self
		{
			// Views and controllers may overwrite Meta of a page
			if(!isset($this->route['alias']))
			{
				return $this;
			}

			// Flatten view-variables for data-extraction into SEO-tags (Only use named 'keys')
			$extendable_data = array();

			foreach($_view AS $key => $value)
			{
				if(!is_array($value) || count(array_filter(array_keys($value), 'is_string')) == 0)
				{
					if(is_string($value))
					{
						$extendable_data[$key] = $value;
					}
					continue;
				}
				
				$extendable_data[$key] = $value;
			}

			if(!empty($extendable_data))
			{
				$extendable_data = Arr::flatten($extendable_data);
				
				$search = array_map(fn($k) => "{{" . $k . "}}", array_keys($extendable_data));
				$replace = array_values($extendable_data);
			}

			$meta = $this->meta ?? Node::get("meta"); // array_filter($this->meta) ?? Node::get("meta");
			$meta = array_replace(array_filter($this->meta, fn($v) => is_string($v) && !empty($v)), $meta);
			
			if(isset($meta['robots']))
			{
				SEO::metatags()->setRobots((is_array($meta['robots'])) ? implode(", ", $meta['robots']) : $meta['robots']);
				unset($meta['robots']);
			}

			// Iterate all 'meta' to extract tags
			if(!empty($meta))
			{
				// Fallback, look for data in the global namespace
				array_walk($meta, fn($v, $k) => Node::set("meta." . $k, ((isset($search, $replace) && !empty($v) && str_contains($v, "{{")) ? str_replace($search, $replace, $v) : ((is_null($v) && $node = Node::get("meta." . $k)) ? Node::get("meta." . $k)->text() : (string) $v))));
			}
			
			$canonical_url = $this->meta[Locale::getLanguage()]['canonical_url'] ?? Request::getInstance()->url();

			SEO::openGraph()->addProperty('url', $canonical_url);
			SEO::setCanonical($canonical_url);

			return $this;
		}

		public function setParameters($_params): Self
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

		public function meta($_meta = null): Self
		{
			if(empty($_meta))
			{
				return $this;
			}

			$_meta = $_meta[Locale::getLanguage()] ?? $_meta;

			//(!isset($_meta[Locale::getLanguage()])) ? array(Locale::getLanguage() => $_meta) : $_meta;
			
			if(!empty($this->meta))
			{
				$this->meta = array_replace_recursive($this->meta, array_filter($_meta));
			}
			else
			{
				$this->meta = $_meta;
			}

			return $this;
		}

		public function settings($_settings)
		{
			$_settings = (!isset($_settings[Locale::getLanguage()])) ? array(Locale::getLanguage() => $_settings) : $_settings;
			
			if(!empty($this->settings))
			{
				$this->settings = array_replace_recursive($_settings, $this->settings);
			}
			else
			{
				$this->settings = $_settings;
			}

			return $this;
		}

		public function setting($_key, $_value = null)
		{
			if(!empty($_value))
			{
				// Set
				if(!isset($this->settings[Locale::getLanguage()]))
				{
					$this->settings[Locale::getLanguage()] = array();
				}

				if(isset($this->settings[Locale::getLanguage()][$_key], $this->settings[Locale::getLanguage()][$_key]['value']))
				{
					$this->settings[Locale::getLanguage()][$_key]['value'] = $_value;
				}
				else
				{
					$this->settings[Locale::getLanguage()][$_key] = $_value;
				}

				return true;
			}
			
			// Get ('value' == coming from db, w/o value, coming from Page)
			if(!$setting = $this->settings[Locale::getLanguage()][$_key]['value'] ?? $this->settings[Locale::getLanguage()][$_key] ?? false)
			{
				$this->settings[Locale::getLanguage()][$_key] = false;
			}

			return $setting;
		}

		public function getSettings(): Array
		{
			return $this->settings[Locale::getLanguage()] ?? $this->settings;
		}

		public function compile()
		{
            $action = $this->action;

			// Returns HTML from View
			$this->controller->setPage($this);
			
           	$this->compilation = $this->controller->$action(...array_values($this->parameters));

           	if(empty($this->compilation))
           	{
           		throw new Exception("Return value from controller cant be Void");
           	}

			// Since this is a HTML-rendered page
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
			return $this->route[$_key] ?? null;
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

			if($this->compilation instanceof View)
			{
				$this->initMeta($this->compilation); // After Local, then Page and lastly DB	
			}

			// If any meta-data to take care about (Before the Controller may overwrite it)
			return (string) $this->render();
		}
	}
?>