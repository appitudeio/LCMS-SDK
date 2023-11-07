<?php
    /**
     *  
     */
    namespace LCMS;

    use LCMS\Core\Request;
    use Exception;

    class Middleware
    {
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