<?php
    /**
     *  PHP-DI wrapper
     *  @source https://php-di.org/
     *  @created 2022-12-05
     */
    namespace LCMS;

    use LCMS\Util\Singleton;
    use DI\Container;
    use ReflectionMethod;
    use ReflectionFunction;
    use ReflectionNamedType;
    use Closure;

    class DI
    {
		use Singleton {
			Singleton::__construct as private SingletonConstructor;
		}

        protected Container $di;
        protected array $instances = [];

        function __construct(string $_environment = "development")
        {
            $this->SingletonConstructor();

            if($_environment == "development")
            {
                $this->di = new Container();
            }
        }

        public function __call(string $_method, array $_args): mixed
        {
            return match($_method) {
                "set" => ($this->instances[] = $_args[0]) && $this->di->set(...$_args),
                "has" => in_array($_args[0], $this->instances),
                "call" => $this->di->call(
                    $_args[0],
                    $this->instances ? $this->injectRegisteredInstances($_args[0], $_args[1] ?? []) : ($_args[1] ?? [])
                ),
                default => $this->di->$_method(...$_args)
            };
        }

        private function injectRegisteredInstances(mixed $_callable, array $_params): array
        {
            try {
                $ref = is_array($_callable)
                    ? new ReflectionMethod($_callable[0], $_callable[1])
                    : new ReflectionFunction(Closure::fromCallable($_callable));

                foreach($ref->getParameters() AS $param) 
                {
                    if(isset($_params[$param->getName()])) {
                        continue;
                    }

                    $type = $param->getType();
                    if(!$type || $type->isBuiltin()) {
                        continue;
                    }

                    $className = $type instanceof ReflectionNamedType ? $type->getName() : null;
                    
                    if($className && in_array($className, $this->instances)) {
                        $_params[$param->getName()] = $this->di->get($className);
                    }
                }
            } catch(\Throwable) {
                // Silently fail - use original params
            }

            return $_params;
        }
    }
?>