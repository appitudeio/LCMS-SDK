<?php
    /**
     *  Dispatcher API Client v0.1
     */
    namespace LCMS\Api;

    use LCMS\Api\Client;
    use \Exception;

    class Dispatcher extends Client
    {
        private function send(){}
        private function email(){}
        private function sms(){}

        function __call(string $_method, array $_arguments): ClientResponse
        {
            $this->timings = array(microtime(true));
            $_method = strtolower($_method);

            list($mode, $request_data) = $this->validate($_method, $_arguments);

            if($user = $_arguments[1] ?? false)
            {
                $request_data['user'] = $user;
            }

            if($payload = $_arguments[2] ?? false)
            {
                if(isset($payload['from']))
                {
                    $request_data['from'] = $payload['from'];
                    unset($payload['from']);
                }

                if(!empty($payload))
                {
                    $request_data['payload'] = $payload;
                }
            }

            // Append {?interface} to the endpoint
            $endpoint = "dispatch/" . $request_data['event'] . (($_method == "send") ? null : "/" . $_method);

            return $this->sendRequest($mode, $endpoint, $request_data);
        }        

        protected function validate(string $_method, array $_arguments): array
        {
            $mode = "sandbox";
            $request_data = array();
			$methods = get_class_methods($this);

            // Depending on which api_key that comes in, decides mode
            if(!str_starts_with($this->api_key, "live_") && !str_starts_with($this->api_key, "test_"))
            {
                throw new Exception("Dispatcher ApiKey requires to start with test_ || live_");
            }
            elseif(!in_array($_method, $methods))
            {
                throw new Exception("Dispatch method ".$_method." not allowed (".implode(", ", $methods).")");
            }
            elseif((!$request_data['event'] = trim($_arguments[0] ?? "")) || !is_string($request_data['event']) || empty($request_data['event']))
            {
                throw new Exception("Dispatch can't send anything without an event as second argument");
            }
            elseif(false == preg_match("/^[A-Za-z0-9_]*$/", $request_data['event']))
            {
                throw new Exception("Event [".$request_data['event']."] contains non alpha-numeric(_) characters");
            }
            elseif(str_starts_with($this->api_key, "live_"))
            {
                $mode = "production";
            }

            // Can this server send silent requests(?)
            if(isset($this->options['silent']))
            {
                $exec_enabled =
                    function_exists('exec') &&
                    !in_array('exec', array_map('trim', explode(', ', ini_get('disable_functions')))) &&
                    strtolower(ini_get('safe_mode')) != 1;

                if(!$exec_enabled)
                {
                    throw new Exception("Can't dispatch messages silently (with exec)");
                }
            }

            // Validate batch request
            if(isset($this->options['batch']))
            {
                /**
                 *  Batch 'users' structure may be
                 *  @array ["email@domain.com", "some@one.com", "another@person.se"]
                 *  @array ["+46703055530", "+46703055529"]
                 *  @array [{ "id": 1337, "email": "email@domain.com" }, {}, {}]
                 */
                if(!isset($_arguments[1]) || !is_array($_arguments[1]))
                {
                    throw new Exception("Users argument missing for batch request");
                }
            }

            return array($mode, $request_data);
        }
    }
?>