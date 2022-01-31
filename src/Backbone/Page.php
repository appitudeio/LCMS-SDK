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

		/**
		 * 	
		 */
		private function initMeta(View $_view): Self
		{
			// Meta built by Controller -> i18n -> DB 
			$meta = array_replace_recursive(Node::get("meta") ?: array(), array_filter($this->meta));
			$meta['canonical_url'] = $meta['canonical_url'] ?? Request::getInstance()->url();

			if(isset($meta['robots']))
			{
				SEO::metatags()->setRobots((is_array($meta['robots'])) ? implode(", ", $meta['robots']) : $meta['robots']);
				unset($meta['robots']);
			}

			// Iterate all 'meta' to extract tags
			if(!empty($meta))
			{
				// Fallback, look for data in the global namespace and fill them out with values from Views
				$extendable_data = (function() use($_view)
				{
					$viewData = array_filter(array_values((array) $_view)[0], fn($value) => !empty($value) && (is_string($value) || (is_array($value) && count(array_filter(array_keys($value), 'is_string')) > 0)));
					return (empty($viewData)) ? array() : (($viewData = Arr::flatten($viewData)) ? array_combine(array_map(fn($k) => "{{" . $k . "}}", array_keys($viewData)), $viewData) : array());
				})();

				array_walk($meta, fn($v, $k) => Node::set("meta." . $k, ((!empty($extendable_data) && !empty($v) && str_contains($v, "{{")) ? strtr($v, $extendable_data) : ((is_null($v) && $node = Node::get("meta." . $k)) ? Node::get("meta." . $k)->text() : (string) $v))));
		
				foreach($meta AS $key => $value)
				{
					if(match($key)
					{
						"title" 		=> SEO::setTitle($value),
						"description" 	=> SEO::setDescription($value),
						"canonical_url" => SEO::setCanonical($value) && SEO::openGraph()->addProperty("url", $value),
						default 		=> "unhandled"
					} == "unhandled" && str_starts_with($key, "og:"))
					{
						if($key == "og:image")
						{
							SEO::setImage($value);
						}
						else
						{
							SEO::openGraph()->addProperty(str_replace("og:", "", $key), $value);
						}
					}
				}

				if(!isset(SEO::openGraph()->getProperties()['type']))
				{
					SEO::openGraph()->addProperty("type", "website");
				}
			}
			
			return $this;
		}

		public function setParameters(Array $_params): Self
		{
			$this->parameters = (!empty($this->parameters)) ? array_merge($this->parameters, $_params) : $_params;

			return $this;
		}

		public function meta(Array $_meta): Self
		{
			$_meta = array_filter($_meta[Locale::getLanguage()] ?? $_meta);

			if(!empty($_meta))
			{
				$this->meta = (!empty($this->meta)) ? array_replace_recursive($this->meta, array_filter($_meta)) : $_meta;
			}

			return $this;
		}

		public function settings(Array $_settings)
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

		public function setting(string $_key, $_value = null)
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

		public function asArray(): Array
		{
			return $this->route; 
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
		public function __toString(): String
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