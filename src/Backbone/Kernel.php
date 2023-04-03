<?php
	/**
	 *
	 */
	namespace LCMS\Backbone;

    use LCMS\Api\Merge;
    use LCMS\Api\NodeMerge;
	use LCMS\Core\Request;
	use LCMS\Core\Response;
    use LCMS\Core\Redirect;
	use LCMS\Core\Node;
    use LCMS\Core\Route;
	use LCMS\Core\Locale;
    use LCMS\Core\Env;
    use LCMS\Core\Database;
	use LCMS\View;
    use LCMS\DI;
    use LCMS\Page;
    use LCMS\Page\Navigations;
    use LCMS\Util\Toolset;
	use \Exception;

	class Kernel
	{
        private $events;

        /**
         *  What the Init returns get's parsed into either Merger or into the Container
         *  +++ Node+Database merge happens after Route identification ("namespace")
         */
        public function init(\Closure $_callback): self
        {
            // Set itself
            DI::set(DI::class, DI::getInstance());

            // Set rest
            foreach(DI::call($_callback) AS $key => $merger)
            {
                if(is_array($merger) && isset($merger[0]) && gettype($merger[0]) == "object")
                {
                    // If Node+Database (Merges after figured out the Route namespace)
                    $RootObj = $merger[0];
                    unset($merger[0]);

                    $instances = array_map(fn($o) => (is_object($o)) ? get_class($o) : $o, $merger);
                    $auto_merge = ($RootObj instanceof Node || ($RootObj instanceof Node && !in_array(Database::class, $instances))) ? false : true;

                    // Either create a new Merge, or re-use
                    foreach($merger AS $merge)
                    {
                        $mergerObject = (new Merge($RootObj))->with($merge, $auto_merge);
                    }

                    DI::set($mergerObject::class, $mergerObject);

                    /**
                     *  If Env is Local, let's figure out language (Maybe from URL or default).
                     */
                    if($RootObj instanceof Locale && $new_language = $RootObj->extract(DI::get(Request::class)))
                    {
                        if($RootObj->isDefault()) // if url = /{defualt_language}/ -> remove
                        {
                            return $this->trigger("redirect", Redirect::to(str_replace("/" . $new_language, "", DI::get(Request::class)->getRequestUri())));
                        }

                        DI::get(Request::class)->appendUrl($new_language);
                        DI::get(Env::class)->set("web_path", DI::get(Env::class)->get("web_path") . $new_language . "/");            
                    }
                }
                else
                {
                    DI::set($key, $merger);
                }
            }

            return $this;
        }

        public function on($_event, $_callback): self
        {
            $this->events[strtolower($_event)] = $_callback;

            return $this;
        }

        public function trigger(string $_event, ...$_args): mixed
        {
            if(!isset($this->events[strtolower($_event)]))
            {
                return false;
            }

            return DI::call($this->events[strtolower($_event)], $_args);
        }

        public function dispatch(): mixed
        {
            $locale = DI::get(Locale::class);
            $request = DI::get(Request::class);
            $env = DI::get(Env::class);
            $node = DI::get(Node::class);
            $route = DI::get(Route::class);
            $navigations = DI::get(Navigations::class);
            
            /**
             *  If LCMS-environment, set ImageFactory 
             */
            if($env->get("domain"))
            {
                $node->init($env->get("domain") . "/images/");
            }
            
            /**
             *  
             */
            $response = DI::call([$route, "dispatch"]);
            
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
            $route_array = $route->compile($response);

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
            if($request->method() != $request::METHOD_GET && !isset($route_array['alias']) && $get_route_array = $route->match($request->path(), $request::METHOD_GET, false, false))
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

            // Let's populate a Page (Or Redirect, Or Response)
            DI::get(Page::class)->init($route_array);

            // Merge nodes with database (Incase we use Nodes inside any controller)
            $node->setNamespace(array(
                'id'    => $route_array['id'] ?? null,
                'alias' => $route_array['alias'] ?? null,
                'pattern' => $route_array['pattern']
            ));

            if(DI::has(NodeMerge::class) && ($nodeMerger = DI::get(NodeMerge::class)) && $nodeMerger->getStorage() instanceof Database)
            {
                $nodeMerger->merge();
            }

            // Compile page into the end product
            $compilation = DI::call([Page::class, "compile"]);

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
            if(isset($route_array['meta'], $route_array['meta'][$locale->getLanguage()]) && !empty($route_array['meta'][$locale->getLanguage()]))
            {
                DI::get(Page::class)->meta($route_array['meta'][$locale->getLanguage()]);
            }
            
            // Set content type (Coming from Controller)
            if(($content_type = $request->headers->get("Content-type")) && $content_type != "application/x-www-form-urlencoded")
            {
                $request->headers->set("Content-type", $content_type);
            }
            
            /** 
             *  Find out if we should block this page with 'noindex' or 'nofollow'
             */
            if($env->get("is_dev", false))
            {
                DI::call([Page::class, "meta"], [['robots' => array('noindex', 'nofollow')]]);
                $request->headers->set("X-Robots-Tag", "noindex, nofollow");
            }
            elseif($robots_node = $node->get("robots"))
            {
                $robots = array();

                if((list($row, $noindex_content) = Toolset::getStringBetween($robots_node->text(), "Noindex: ", "\\n")) 
                    && ($noindex_content = str_replace($env->get("web_path"), "", $noindex_content))
                    && (in_array(DI::get(Page::class)->pattern, $noindex_content) || count(array_filter(explode("/", DI::get(Page::class)->pattern), fn($part) => in_array($part . "/", $noindex_content)))
                ))
                {
                    $robots[] = "noindex";
                }

                if((list($row, $disallow_content) = Toolset::getStringBetween($robots_node->text(), "Disallow: ", "\\n")) 
                    && ($disallow_content = str_replace($env->get("web_path"), "", $disallow_content))
                    && (in_array(DI::get(Page::class)->pattern, $disallow_content) || count(array_filter(explode("/", DI::get(Page::class)->pattern), fn($part) => in_array($part . "/", $disallow_content)))
                ))
                {
                    $robots[] = "nofollow";
                }

                if(!empty($robots))
                {
                    DI::get(Page::class)->meta(['robots' => $robots]);
                    $request->headers->set("X-Robots-Tag", implode(", ", $robots));
                }
            }

            return DI::call([$this, "trigger"], ["page"]);
        }
    }
?>