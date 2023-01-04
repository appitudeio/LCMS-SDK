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
            return $this->di->$_method(...$_args);
        }
    }
?>