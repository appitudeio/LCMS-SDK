<?php
	/**
	 *
	 */
	namespace LCMS;

	use LCMS\DI;
	use LCMS\Core\Request;
	use LCMS\Core\Node;
	use LCMS\Core\Locale;
	use LCMS\View;
	use LCMS\Page\SEO;
	use LCMS\Util\Arr;
	use \Exception;

	class Page
	{
		public $parameters = array();
		public $controller;
		public $action;
		private $compilation;
		private $route;
		private $locale;
		private $settings = array();
		private $meta = array(
			'title'			=> null,
			'description'	=> null
		);

		function __construct(Locale $locale)
		{
			$this->locale = $locale;
		}

		public function init(array $_route_array): self
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

			return $this;
		}

		/**
		 * 	
		 */
		public function initMeta(View $_view, SEO $seo, Node $node, Request $request): Self
		{
			// Meta built by Controller -> i18n -> DB
			$meta = array_replace_recursive($node->get("meta") ?: array(), array_filter($this->meta));
			$meta['canonical_url'] = $meta['canonical_url'] ?? $request->url();

			if(isset($meta['robots']))
			{
				$seo->metatags()->setRobots((is_array($meta['robots'])) ? implode(", ", $meta['robots']) : $meta['robots']);
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
				
				// Extend / replace {{variables}} if possible
				array_walk($meta, fn($v, $k) => $node->set("meta." . $k, ((!empty($extendable_data) && !empty($v) && str_contains($v, "{{")) ? strtr($v, $extendable_data) : ((is_null($v) && $node = $node->get("meta." . $k)) ? (string) $node->get("meta." . $k)->text() : (string) $v))));
		
				foreach($meta AS $key => $value)
				{
					if(match($key)
					{
						"title" 		=> $seo->setTitle($value),
						"description" 	=> $seo->setDescription($value),
						"canonical_url" => $seo->setCanonical($value) && $seo->openGraph()->addProperty("url", $value),
						default 		=> "unhandled"
					} == "unhandled" && str_starts_with($key, "og:"))
					{
						if($key == "og:image")
						{
							$seo->setImage($value);
						}
						else
						{
							$seo->openGraph()->addProperty(str_replace("og:", "", $key), $value);
						}
					}
				}

				if(!isset($seo->openGraph()->getProperties()['type']))
				{
					$seo->openGraph()->addProperty("type", "website");
				}
			}
			
			return $this;
		}

		public function setParameters(array $_params): self
		{
			$this->parameters = (!empty($this->parameters)) ? array_merge($this->parameters, $_params) : $_params;

			return $this;
		}

		public function meta(array $_meta): self
		{
			if($_meta = array_filter($_meta[$this->locale->getLanguage()] ?? $_meta))
			{
				$this->meta = (!empty($this->meta)) ? array_replace_recursive($this->meta, array_filter($_meta)) : $_meta;
			}

			return $this;
		}

		public function settings(array $_settings): self
		{
			$_settings = (!isset($_settings[$this->locale->getLanguage()])) ? array($this->locale->getLanguage() => $_settings) : $_settings;
			
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

		public function setting(string $_key, mixed $_value = null): mixed
		{
			if(!empty($_value))
			{
				// Set
				if(!isset($this->settings[$this->locale->getLanguage()]))
				{
					$this->settings[$this->locale->getLanguage()] = array();
				}

				if(isset($this->settings[$this->locale->getLanguage()][$_key], $this->settings[$this->locale->getLanguage()][$_key]['value']))
				{
					$this->settings[$this->locale->getLanguage()][$_key]['value'] = $_value;
				}
				else
				{
					$this->settings[$this->locale->getLanguage()][$_key] = $_value;
				}

				return true;
			}
			
			// Get ('value' == coming from db, w/o value, coming from Page)
			if(!$setting = $this->settings[$this->locale->getLanguage()][$_key]['value'] ?? $this->settings[$this->locale->getLanguage()][$_key] ?? false)
			{
				$this->settings[$this->locale->getLanguage()][$_key] = false;
			}

			return $setting;
		}

		public function getSettings(): array
		{
			return $this->settings[$this->locale->getLanguage()] ?? $this->settings;
		}

		public function compile(): mixed
		{
			if(!$this->compilation = DI::call([$this->controller, $this->action], [...array_values($this->parameters)]))
           	{
           		throw new Exception("Return value from controller cant be Void");
           	}

			DI::call([$this->controller, "after"]); // Cleanup
			
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
				DI::call([$this, "initMeta"], [$this->compilation]); // After Local, then Page and lastly DB	
			}

			// If any meta-data to take care about (Before the Controller may overwrite it)
			return (string) $this->render();
		}
	}
?>