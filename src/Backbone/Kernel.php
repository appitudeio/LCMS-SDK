<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

    use LCMS\Api\Merge;
	use LCMS\Core\Request;
	use LCMS\Core\Response;
    use LCMS\Core\Redirect;
	use LCMS\Core\Node;
    use LCMS\Core\Route;
	use LCMS\Core\Locale;
    use LCMS\Core\Env;
    use LCMS\Core\Database;
    use LCMS\Core\Navigations;
	use LCMS\Backbone\View;
    use LCMS\Utils\Toolset;
	use \Exception;

	class Kernel
	{
        private $settings;
        private $events;
        private $mergers = array();

		function __construct(Array $_settings)
		{
            $this->initialize($_settings);
		}

        public function initialize(Array $_settings): Void
        {
            if(isset($_settings['mergers']) && is_array($_settings['mergers']))
            {
                foreach($_settings['mergers'] AS $mergeObj)
                {
                    $this->mergers[strtolower($mergeObj->getFamily())] = $mergeObj;
                }
            }

            if(isset($_settings['request']) && $_settings['request'] instanceof Request)
            {
                $this->settings['request'] = $_settings['request'];
            }
            elseif(!isset($this->settings['request']))
            {
                $this->settings['request'] = new Request();
            }

            if(isset($_settings['route']) && $_settings['route'] instanceof Route)
            {
                $this->settings['route'] = $_settings['route'];
            }
            elseif(!isset($this->settings['request']))
            {
                $this->settings['request'] = new Request();
            }

            if(isset($_settings['database']) && $_settings['database'] instanceof Database)
            {
                $this->settings['database'] = $_settings['database'];
            }

            if(isset($_settings['locale']) && $_settings['locale'] instanceof Locale)
            {
                $this->settings['locale'] = $_settings['locale'];
            }
            elseif(!isset($this->settings['locale']))
            {
                $this->settings['locale'] = new Locale();
            }
            
            if(isset($_settings['node']) && $_settings['node'] instanceof Node)
            {
                $this->settings['node'] = $_settings['node'];
            }
            elseif(!isset($this->settings['node']))
            {
                $this->settings['node'] = new Node();
            }
            
            if(isset($_settings['env']) && $_settings['env'] instanceof Env)
            {
                $this->settings['env'] = $_settings['env'];
            }

            if(isset($_settings['navigations']) && $_settings['navigations'] instanceof Navigations)
            {
                $this->settings['navigations'] = $_settings['navigations'];
            }

            if(isset($_settings['paths']) && is_array($_settings['paths']))
            {
                $this->settings['paths'] = array();

                foreach($_settings['paths'] AS $key => $path)
                {
                    if(!is_dir($path))
                    {
                        throw new Exception($key."-path does not exist (".$path.")");
                    }

                    $this->settings['paths'][$key] = $path;
                }
            }
        }

        public function on($_event, $_callback): Self
        {
            $this->events[strtolower($_event)] = $_callback;

            return $this;
        }

        public function trigger($_event)
        {
            if(!isset($this->events[strtolower($_event)]))
            {
                return false;
            }

            $arguments = func_get_args();
            unset($arguments[0]);

            return $this->events[strtolower($_event)](...$arguments);
        }

        public function dispatch()
        {
            /**
             *  Merge env before we runtime-edit it (with language settings below)
             */
            if(isset($this->settings['env'], $this->settings['database']))
            {
                if(!isset($this->mergers['env']))
                {
                    $this->mergers['env'] = new Merge($this->settings['env']);
                }

                $this->settings['env'] = $this->mergers['env']->with($this->settings['database']);
            }
            
            /**
             *  Set language
             *  - If we successfully set a new language through the URL, remove it from the Request
             */
            if(isset($this->settings['env'], $this->settings['paths'], $this->settings['paths']['i18n']))
            {
                $self = $this;
                $this->settings['locale']->setFrom($this->settings['request'], $this->settings['paths']['i18n'], function($new_language) use ($self)
                {
                    if($new_language == $self->settings['env']->get("default_language"))
                    {
                        return $self->trigger("redirect", Redirect::to(str_replace("/" . $new_language, "", $self->settings['request']->getRequestUri())));
                    }

                    $self->settings['request']->setLanguage($new_language);
                    $self->settings['locale']->setLanguage($new_language);
                    $self->settings['env']->set("web_path", $self->settings['env']->get("web_path") . $new_language . "/");
                });
            }
            
            /**
             *  If environment, set ImageFactory 
             */
            if(isset($this->settings['env']) && $domain = $this->settings['env']->get("domain"))
            {
                $this->settings['node']->init($domain . "/images/");
            }
            
            /**
             *  
             */
            if(!isset($this->mergers['node']) && (isset($this->settings['database']) || isset($this->settings['paths'], $this->settings['paths']['i18n'])))
            {
                $this->mergers['node'] = new Merge($this->settings['node']);

                // Merge "local" nodes
                if(isset($this->settings['paths'], $this->settings['paths']['i18n']))
                {
                    $this->settings['node'] = $this->mergers['node']->with($this->settings['paths']['i18n'] . "/" . $this->settings['locale']->getLanguage() . ".ini");
                }
            }

            /**
             *  
             */
            if(isset($this->settings['navigations'], $this->settings['database']))
            {
                if(!isset($this->mergers['navigations']))
                {
                    $this->mergers['navigations'] = new Merge($this->settings['navigations']);
                }

                $this->settings['navigations'] = $this->mergers['navigations']->with($this->settings['database']);
            }

            /**
             *  
             */
            if(isset($this->settings['database']))
            {
                if(!isset($this->mergers['route']))
                {
                    $this->mergers['route'] = new Merge($this->settings['route']);
                }

                $this->settings['route'] = $this->mergers['route']->with($this->settings['database']);
            }            
            
            /**
             *  
             */
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
           /* elseif(is_string($response))
            {
                return $this->trigger("string", $response);
            }*/

            /**
             *	The Controller has told the View which Nodes to use-
            *	 Now let's pair everything with LCMS
            */
            $route_array = $this->settings['route']->compile($response);

            // Check Middleware what to do
            if(false === $route_array)
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

            // If this is a POST-request, with a View returned, try to find the GET equivalent
            if($this->settings['request']->method() != $this->settings['request']::METHOD_GET && !isset($route_array['alias']) && $get_route_array = $this->settings['route']->match($this->settings['request']->path(), $this->settings['request']::METHOD_GET, false, false))
            {
                if(isset($get_route_array['alias']) && !empty($get_route_array['alias']))
                {
                    $route_array['alias'] = $get_route_array['alias'];
                }

                if(isset($get_route_array['id']) && !empty($get_route_array['id']))
                {
                    $route_array['id'] = $get_route_array['id'];
                }

                if(isset($get_route_array['meta']) && !empty($get_route_array['meta']) && empty($route_array['meta']))
                {
                    $route_array['meta'] = $get_route_array['meta'];
                }
            }

            // If this route ends with a {wildcard}, and doesnt have an alias, try to match it to it's parent
            /*if(empty($route_array['alias']) 
                && isset($route_array['parent'], $route_array['parameters'])
                && !empty($route_array['parent'])
                && !empty($route_array['parameters']) 
                && str_ends_with($route_array['pattern'], "}") 
                && ($parent = $this->settings['route']->asArray()[$route_array['parent']])
                && !empty($parent['alias']))
            {
                $route_array['alias'] = $parent['alias'] . "&" . implode("&", array_keys($route_array['parameters']));

                if(isset($parent['meta']) && !empty($parent['meta']) && empty($route_array['meta']))
                {
                    $route_array['meta'] = $parent['meta'];
                }                    
            }*/

            // Let's populate a Page (Or Redirect, Or Response)
            $page = new Page($route_array);

            // Merge nodes with database (Incase we use Nodes inside any controller)
            $this->settings['node']->setNamespace(array(
                'id'    => $route_array['id'] ?? null,
                'alias' => $route_array['alias'] ?? null,
                'pattern' => $route_array['pattern']
            ));

            if(isset($this->settings['database']))
            {
                $this->settings['node'] = $this->mergers['node']->with($this->settings['database']);
            }

            // Compile page into the end product
            $compilation = $page->compile();

            if($compilation instanceof Redirect)
            {
                return $this->trigger("redirect", $compilation);
            }
            elseif($compilation instanceof Response)
            {
                return $this->trigger("response", $compilation);
            }
            elseif(is_string($compilation))
            {
                return $this->trigger("string", $compilation);
            }
            
            // Prepare Meta
            if(isset($this->settings['node'], $route_array['meta'], $this->settings['locale'], $route_array['meta'][$this->settings['locale']->getLanguage()]) && !empty($route_array['meta'][$this->settings['locale']->getLanguage()]))
            {
                $page->meta($route_array['meta'][$this->settings['locale']->getLanguage()]);
            }
            
            // Set content type (Coming from Controller)
            if(($content_type = $this->settings['request']->headers->get("Content-type")) && $content_type != "application/x-www-form-urlencoded")
            {
                $this->settings['request']->headers->set("Content-type", $content_type);
            }

            /** 
             *  Find out if we should block this page with 'noindex' or 'nofollow'
             */
            if(isset($this->settings['env']) && $this->settings['env']->get("is_dev"))
            {
                $page->meta(['robots' => array('noindex', 'nofollow')]);
                $this->settings['request']->headers->set("X-Robots-Tag", "noindex, nofollow");
            }
            elseif(isset($this->settings['env']) && $robots_node = $this->settings['node']->get("robots"))
            {
                $robots = array();

                if((list($row, $noindex_content) = Toolset::getStringBetween($robots_node->text(), "Noindex: ", "\\n")) 
                    && ($noindex_content = str_replace($this->settings['env']->get("web_path"), "", $noindex_content))
                    && (in_array($page->pattern, $noindex_content) || count(array_filter(explode("/", $page->pattern), fn($part) => in_array($part . "/", $noindex_content)))
                ))
                {
                    $robots[] = "noindex";
                }

                if((list($row, $disallow_content) = Toolset::getStringBetween($robots_node->text(), "Disallow: ", "\\n")) 
                    && ($disallow_content = str_replace($this->settings['env']->get("web_path"), "", $disallow_content))
                    && (in_array($page->pattern, $disallow_content) || count(array_filter(explode("/", $page->pattern), fn($part) => in_array($part . "/", $disallow_content)))
                ))
                {
                    $robots[] = "nofollow";
                }

                if(!empty($robots))
                {
                    $page->meta(['robots' => $robots]);
                    $this->settings['request']->headers->set("X-Robots-Tag", implode(", ", $robots));
                }
            }
        
            return $this->trigger("page", $page, $this->mergers);
        }
	}
?>