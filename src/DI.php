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
        use Singleton;

        private $di;

        function __construct(string $_environment = "development")
        {
            if($_environment == "development")
            {
                $this->di = new Container();
            }
            else
            {

            }
        }

        function __call(string $_name, array $_arguments): mixed
        {
            return $this->di->$_name(...$_arguments);
        }
    }
?>