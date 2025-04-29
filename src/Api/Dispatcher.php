<?php
    namespace LCMS\Api;

    use LCMS\Api\Client;
    use Exception;

    class Dispatcher extends Client
    {
        /**
         * Dynamically handle dispatching methods.
         *
         * @param string $name      The method name (send, email, sms, etc.)
         * @param array  $arguments The method arguments
         *
         * @return array
         *
         * @throws Exception
         */
        public function __call(string $name, array $arguments): array
        {
            if (!preg_match('/^(test_|live_)/', $this->api_key)) 
            {
                throw new Exception("Dispatcher api_key must start with test_ or live_");
            }
            
            $mode = (strpos($this->api_key, 'live_') === 0) ? "production" : "sandbox";
            $event = trim($arguments[0] ?? ''); // Assume first argument is the event name:

            if (empty($event) || !preg_match('/^[A-Za-z0-9_]+$/', $event)) 
            {
                throw new Exception("Invalid or missing event name");
            }
            
            // Optionally collect other parameters (user, payload, etc.)
            $params = [];
            
            if (isset($arguments[1])) 
            {
                $params['user'] = $arguments[1];
            }
            
            if (isset($arguments[2]) && is_array($arguments[2])) 
            {
                // Example: if 'from' is set in the payload, move it to the proper location
                if (isset($arguments[2]['from'])) 
                {
                    $params['from'] = $arguments[2]['from'];
                    unset($arguments[2]['from']);
                }
                
                if (!empty($arguments[2])) 
                {
                    $params['payload'] = $arguments[2];
                }
            }
            
            // Build the endpoint; for example: dispatch/{event}/{name?}
            $endpoint = "dispatch/".$event . ($name === 'send' ? '' : '/' . $name);
      
            return $this->sendRequest('POST', $endpoint, ['json' => $params]);
        }
    }
?>