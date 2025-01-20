<?php
	/**
	 *  Changelog:
     *      - 2023-06-15: Added simple middleware stacking (Inspo from: https://github.com/idealo/php-middleware-stack)
	 */
	namespace LCMS\Backbone;

    use LCMS\DI;
	use LCMS\View;
    use LCMS\Page;
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
    use LCMS\Util\Toolset;
    use LCMS\Util\Arr;

    use \Closure;
	use \Exception;

	class Kernel
	{
        private array $events;
        private array $middlewares = [];

        /**
         *  What the Init returns get's parsed into either Merger or into the Container
         *  +++ Node+Database merge happens after Route identification ("namespace")
         */
        public function init(Closure $_callback): self
        {
            // Set itself
            DI::set(DI::class, DI::getInstance());

            /**
             *  First off, run Middlewares
             */
            if(!empty($this->middlewares))
            {
                if(!DI::has(Request::class))
                {
                    DI::set(Request::class, Request::getInstance());
                }

                $middlewaresStack = new MiddlewareStack(...$this->middlewares);
                $middlewaresResponse = DI::call([$middlewaresStack, "handle"]);

                if($middlewaresResponse instanceof Redirect)
                {
                    return $this->on("middlewareredirect", $middlewaresResponse);
                }
                elseif($middlewaresResponse instanceof Response)
                {
                    return $this->on("middlewareresponse", $middlewaresResponse);
                }
            }                    

            // Set rest
            foreach(DI::call($_callback) AS $key => $merger)
            {
                if(is_array($merger) && isset($merger[0]) && gettype($merger[0]) == "object")
                {
                    // If Node+Database (Merges after figured out the Route namespace)
                    $RootObj = $merger[0];
                    unset($merger[0]);

                    // Either create a new Merge, or re-use
                    foreach($merger AS $storage)
                    {
                        if(!$mergeObj = Merge::getClassOf($RootObj))
                        {
                            continue;
                        }

                        if(DI::has($mergeObj))
                        {
                            $obj = DI::get($mergeObj);
                            $has_di = true;
                        }
                        else 
                        {
                            $obj = (new Merge($RootObj))->with($storage);
                            $has_di = false;
                        }

                        // Nodes are merge later when using Database (After route initialization)
                        if(!($RootObj instanceof Node) || ($RootObj instanceof Node && !($storage instanceof Database))) //$auto_merge)
                        {
                            $obj->merge($storage);
                        }
                        else
                        {
                            $obj->setStorage($storage); // For later
                        }

                        if(!$has_di)
                        {
                            DI::set($mergeObj, $obj);
                        }
                    }
                }
                else
                {
                    DI::set($key, $merger);
                }
            }
            
            return $this;
        }

        public function middlewares(array $_mw): self
        {
            $this->middlewares = array_merge($this->middlewares, $_mw);

            return $this;
        }

        public function on($_event, $_callback): self
        {
            $this->events[strtolower($_event)] = $_callback;

            return $this;
        }

        public function trigger(string $_event, ...$_args): mixed
        {
            $_event = strtolower($_event);

            if($_event == "middlewareredirect")
            {
                array_unshift($_args, $this->events[$_event]);
                $_event = "redirect";
            }
            elseif($_event == "middlewareresponse")
            {
                array_unshift($_args, $this->events[$_event]);
                $_event = "response";
            }

            if(!isset($this->events[$_event]))
            {
                return false;
            }

            return DI::call($this->events[$_event], [...$_args]);
        }

        public function dispatch(): mixed
        {
            if(isset($this->events['middlewareredirect']) || isset($this->events['middlewareresponse']))
            {
                return (isset($this->events['middlewareredirect'])) ? $this->trigger("middlewareredirect") : $this->trigger("middlewareresponse");
            }

            $locale = DI::get(Locale::class);
            $request = DI::get(Request::class);
            $env = DI::get(Env::class);
            $node = DI::get(Node::class);
            $route = DI::get(Route::class);
            
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
                $nodeMerger->merge(); // Merge Nodes with Database
            }

            // Compile page into the end product
            $compilation = DI::call([Page::class, "compile"]);
 
            // Before we do anything, let's check and set headers
            if(($content_type = $request->headers->get("Content-Type", $request->headers->get("content-type", false))) && $content_type != "application/x-www-form-urlencoded")
            {
                $request->headers->set("Content-Type", $content_type);
                header("Content-Type: " . $content_type);
            }

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

            // If nodes has changed (Been added through a Controller/Page)
            if(isset($nodeMerger) && !empty($node->getAdded()) && $nodes = array_map(fn($n) => $node->get($n), $node->getAdded()))
            {
                $nodeMerger->store($nodes);
            }

            /** 
             *  Find out if we should block this page with 'noindex' or 'nofollow'
             */
            $this->setRobotsHeaders();

            if($robots_header = $request->headers->get("X-Robots-Tag", false))
            {
                header("X-Robots-Tag: " . $robots_header);
            }

            /** 
             *  Page is an actual html page!
             *  - Prepare meta data
             */
            if(isset($route_array['meta'], $route_array['meta'][$locale->getLanguage()]) && !empty($route_array['meta'][$locale->getLanguage()]))
            {
                DI::get(Page::class)->meta($route_array['meta'][$locale->getLanguage()]);
            }

            // Merge all Nodes with View-data  
            if(DI::has(NodeMerge::class) && $nodeMerge = DI::get(NodeMerge::class))
            {
                $parameters = array();

                foreach(DI::get(View::class) AS $parameter_key => $parameter_value)
                {
                    if(empty($parameter_value) || is_object($parameter_value))
                    {
                        continue;
                    }

                    $parameters[$parameter_key] = $parameter_value;
                }

                if(!empty($parameters))
                {
                    $nodeMerge->getInstance()->with(Arr::flatten($parameters))->merge();
                }
            }

            return DI::call([$this, "trigger"], ["page"]);
        }

        private function setRobotsHeaders(): void
        {
            $env = DI::get(Env::class);
            $request = DI::get(Request::class);

            if($env->get("is_dev", false)) 
            {
                DI::call([Page::class, "meta"], [['robots' => ['noindex', 'nofollow']]]);
                $request->headers->set("X-Robots-Tag", "noindex, nofollow");

                return;
            }
            elseif(!$robots_node = DI::get(Node::class)->get("robots")) 
            {
                return;
            }
            elseif($robots = $this->extractRobotsDirectives($robots_node->text())) 
            {
                DI::get(Page::class)->meta(['robots' => $robots]);
                $request->headers->set("X-Robots-Tag", implode(", ", $robots));
            }
        }

        private function extractRobotsDirectives(string $robotsText): array
        {
            $robots = [];

            // Extract Noindex directives
            if (preg_match_all('/Noindex:\s*(.*?)\s*$/m', $robotsText, $matches)) 
            {
                $robots['noindex'] = array_map('trim', $matches[1]);
            }

            // Extract Disallow directives
            if (preg_match_all('/Disallow:\s*(.*?)\s*$/m', $robotsText, $matches)) 
            {
                $robots['nofollow'] = array_map('trim', $matches[1]);
            }

            return array_merge(...array_values($robots));
        }
    }

    class MiddlewareStack
    {
        protected array $middlewares = array();

        function __construct(...$middlewares)
        {
            $this->middlewares = array_map(fn($mw) => is_string($mw) ? new $mw() : $mw, $middlewares);
        }

        private function without($middleware)
        {
            return new self(
                ...array_filter(
                    $this->middlewares,
                    fn($mw) => $middleware !== $mw
                )
            );
        }

        public function handle(Request $request)
        {
            $middleware = $this->middlewares[0] ?? false;
            $middleware = (is_string($middleware)) ? new $middleware() : $middleware;

            return $middleware ? DI::call([$middleware::class, "process"], ['next' => $this->without($middleware)]) : null;
        }

        function __invoke(Request $request)
        {
            return $this->handle($request);
        }        
    }
?>