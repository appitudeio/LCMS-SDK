<?php
    /**
     *  PHP-DI wrapper
     *  @source https://php-di.org/
     *  @created 2022-12-05
     */
    namespace LCMS;

    use LCMS\Util\Singleton;
    use DI\Container;

    class DI
    {
		use Singleton {
			Singleton::__construct as private SingletonConstructor;
		}        

        protected $di;
        protected array $instances = array();

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
            if($_method == "set")
            {
                $this->instances[] = $_args[0];
            }
            elseif($_method == "has")
            {
                return in_array($_method, $this->instances);
            }

            return $this->di->$_method(...$_args);
        }
    }
?>