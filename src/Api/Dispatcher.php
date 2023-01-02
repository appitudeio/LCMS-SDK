<?php
    /**
     *  Dispatcher API Client v0.1
     */
    namespace LCMS\Api;

    use GuzzleHttp\Client as Guzzle;
    use GuzzleHttp\RequestOptions;
    use GuzzleHttp\Psr7\Response;
    use GuzzleHttp\Exception\ClientException;
    use \Exception;

    class Dispatcher
    {
        const CLIENT_NAME = "LCMS-SDK";
        const CLIENT_VERSION = "0.1-beta";
        const CLIENT_URL = "https://github.com/appitudeio/lcms-sdk";
        
        private $methods = array("send", "email", "sms", "slack"); //, "push");
        private $urls = array(
            'production' => "https://api.logicalcms.com",
            'sandbox' => "https://api-sandbox.logicalcms.com"
        );
        private $api_key;
        private $options = array();
        private $timings;
    
        function __construct(string $_api_key, array $_options = array())
        {
            $this->api_key = $_api_key;
            $this->options = array_replace_recursive($this->options, $_options, ['headers' => ['User-Agent' => $this->setUserAgent()]]);
        }

        function __call(string $_method, array $_arguments): array
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
                $request_data['payload'] = $payload;
            }

            return $this->sendRequest($mode, $_method, $request_data);
        }

        private function sendRequest(string $mode = "sandbox", string $_method = "send", array $_request_data = array()): array
        {
            $query_data = array_replace_recursive(array(
                'headers' => array(
                    'Authorization' => $this->api_key
                )
            ), $this->options);

            if(isset($query_data['batch']))
            {
                $query_data[RequestOptions::JSON]['users'] = $_request_data['user']; 
             
                unset($query_data['batch']);
            }
            elseif(isset($_request_data['user']))
            {  
                $query_data[RequestOptions::JSON]['user'] = $_request_data['user'];
            }
            
            if(isset($_request_data['payload']))
            {  
                $query_data[RequestOptions::JSON]['payload'] = $_request_data['payload'];
            }            

            try
            {
                $endpoint = "dispatch/" . $_request_data['event'] . (($_method == "send") ? null : "/" . $_method);
                
                if(isset($query_data['silent']))
                {
                    
                }
                else
                {
                    $client     = new Guzzle(['base_uri' => $this->urls[$mode]]);
                    $request    = $client->post($endpoint, $query_data);

                    if(!$response_array = json_decode((string) $request->getBody(), true))
                    {
                        throw new Exception($request->getBody());
                    }
                    elseif(isset($response_array['error']))
                    {
                        throw new Exception($response_array['error']);
                    }
                    elseif(isset($response_array['errors']))
                    {
                        $error = (is_array($response_array['errors'][0]['message'])) ? $response_array['errors'][0]['message'][array_key_first($response_array['errors'][0]['message'])] : $response_array['errors'][0]['message'];
                        throw new Exception($error);
                    }
                }
            }
			catch(ClientException $e)
			{
				throw new Exception($e->getResponse()->getBody()->getContents());
			}
            catch(Exception $e)
            {
                throw new Exception($e->getMessage());
            }

            return $response_array + ['execution_time' => (microtime(true) - $this->timings[0])];
        }

        private function validate(string $_method, array $_arguments): array
        {
            $mode = "sandbox";
            $request_data = array();

            // Depending on which api_key that comes in, decides mode
            if(!str_starts_with($this->api_key, "live_") && !str_starts_with($this->api_key, "test"))
            {
                throw new Exception("Dispatcher ApiKey requires to start with test_ || live_");
            }
            elseif(!in_array($_method, $this->methods))
            {
                throw new Exception("Dispatch method ".$_method." not allowed (".implode(", ", $this->methods).")");
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

        public function setUserAgent(): string
        {
            return self::CLIENT_NAME.'/'.self::CLIENT_VERSION.' (+'.self::CLIENT_URL.')';
        }

        public function debug(): self
        {
            $this->options['debug'] = true;
            return $this;
        }

        /**
         *  
         */
        public function silent(): self
        {
            $this->options['silent'] = true;
            return $this;
        }

        public function batch(): self
        {
            $this->options['batch'] = true;
            return $this;
        }
    }
?>