<?php
    /**
     *  
     */
    namespace LCMS;

    use LCMS\Core\Request;
    use Exception;

    class Middleware
    {
        /*public function process(Request $request, Closure $next)
        {
            return $next($request);
        }*/

        function __invoke(Request $request, $next)
        {
            if(!method_exists($this, "process"))
            {
                throw new Exception("No Middleware->process method found in " . __CLASS__);
            }

            return $this->process($request, $next);
        }
    }
?>