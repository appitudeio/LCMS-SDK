<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

    use LCMS\Api\Merge;
	use LCMS\Core\Request;
	use LCMS\Core\Response;
	use LCMS\Core\Node;
	use LCMS\Core\Locale;
	use LCMS\Backbone\View;
	use \Exception;

	class Kernel
	{
        private $settings;
        private $events;

		function __construct($_settings)
		{
            if(isset($_settings['request']) && $_settings['request'] instanceof Request)
            {
                $this->settings['request'] = $_settings['request'];
            }

            if(isset($_settings['route']) && $_settings['route'] instanceof Route)
            {
                $this->settings['route'] = $_settings['route'];
            }

            if(isset($_settings['database']) && $_settings['database'] instanceof Database)
            {
                $this->settings['database'] = $_settings['database'];
            }

            if(isset($_settings['locale']) && $_settings['locale'] instanceof Locale)
            {
                $this->settings['locale'] = $_settings['locale'];
            } 
            
            if(isset($_settings['node']) && $_settings['node'] instanceof Node)
            {
                $this->settings['node'] = $_settings['node'];
            } 
            
            if(isset($_settings['env']) && $_settings['env'] instanceof Env)
            {
                $this->settings['env'] = $_settings['env'];
            }             
		}

        public function on($_event, $_callback)
        {
            $this->events[$_event] = $_callback;
        }

        public function trigger($_event, $_data = null)
        {
            if(!isset($this->events[strtolower($_event)]))
            {
                return false;
            }

            return $this->events[strtolower($_event)]($_data);
        }

        public function dispatch()
        {
            if(isset($this->settings['route'], $this->settings['database']))
            {
                $routeMerger = new Merge($this->settings['route']);
                $this->settings['route'] = $routeMerger->with($this->settings['database']);
            }

            if(isset($this->settings['node'], $this->settings['database']))
            {
                $nodeMerger = new Merge($this->settings['node']);
                $this->settings['node'] = $nodeMerger->with($this->settings['database']);
            }
            
            if(isset($this->settings['env'], $this->settings['database']))
            {
                $envMerger = new Merge($this->settings['env']);
                $this->settings['env'] = $envMerger->with($this->settings['database']);
            }

            if(isset($this->settings['request'], $this->settings['route']))
            {
                $response = $this->settings['route']->dispatch($this->settings['request']);

                /**
                 *	Any response|redirect from Routes?
                */
                if($response instanceof Redirect)
                {
                    return $this->trigger("redirect", $response);
                }
                elseif($response instanceof Response)
                {
                    return $this->trigger("response", $response);
                }
                elseif(isset($response['callback']))
                {
                    return $this->trigger("callback", $response['callback'], $response['parameters'] ?? null);
                }
                elseif(is_string($response))
                {
                    return $this->trigger("string", $response);
                }

                /**
                 *	The Controller has told the View which Nodes to use-
                *	 Now let's pair everything with LCMS
                */
                $route_array = $this->settings['route']->compile($response);

                // Check Middleware what to do
                if($route_array === false)
                {
                    throw new Exception("Request failed", 500);
                }
                elseif($route_array instanceof Redirect)
                {
                    return $this->trigger("redirect", $route_array);
                }
                elseif($route_array instanceof Response)
                {
                    return $this->trigger("response", $route_array);
                }
                elseif($route_array instanceof View)
                {
                    return $this->trigger("view", View::getInstance());
                }
                
				// Let's populate a Page (Or Redirect, Or Response)
				$page = new Page($route_array);

				$compilation = $page->compile();

				if($compilation instanceof Redirect)
                {
                    return $this->trigger("redirect", $compilation);
                }
                elseif($compilation instanceof Response)
				{
					return $this->trigger("response", $compilation);
				}

                if(isset($this->settings['node']))
                {
                    $this->settings['node']->init(Env::get("domain") . "/images/"); // Initialize the ImageFactory	

                    if(isset($route_array['alias']) && !empty($route_array['alias']))
                    {
                        // Only Routes with Alias may use the Node "locally"
                        $this->settings['node']->setNamespace($route_array['alias'], $route_array['id'] ?? null);
                    }

                    if(isset($this->settings['locale']))
                    {
                        $nodeMerger = new Merge($this->settings['node']);
                        $nodeMerger->with(__DIR__ . "/../i18n/" . $this->settings['locale']->getLanguage() . ".ini");

                        if(isset($this->settings['database']) && isset($route_array['meta'], $route_array['meta'][$this->settings['locale']->getLanguage()]) && !empty($route_array['meta'][$this->settings['locale']->getLanguage()]))
                        {
                            $page->meta(array_filter($route_array['meta'][$this->settings['locale']->getLanguage()]));
                        }
                    }
                }

                return $this->trigger("page", $page);
            }
        }
	}
?>