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
        private $methods = array("send", "email", "sms", "slack"); //, "push");
        private $urls = array(
            'production' => "https://api.logicalcms.com",
            'sandbox' => "https://api-sandbox.logicalcms.com"
        );
        private $mode = "sandbox";

        const CLIENT_NAME = "LCMS-SDK";
        const CLIENT_VERSION = "0.1-beta";
        const CLIENT_URL = "https://github.com/appitudeio/lcms-sdk";
        
        private $api_key;
        private $options = array();
        private $client;

        function __construct(string $_api_key, array $_options = array())
        {
            // Depending on which api_key that comes in, decides mode
            if(!str_starts_with($_api_key, "live_") && !str_starts_with($_api_key, "test"))
            {
                throw new Exception("Dispatcher ApiKey requires to start with test_ || live_");
            }
            else if(str_starts_with($_api_key, "live_"))
            {
                $this->mode = "production";
            }

            $this->api_key = $_api_key;
            $this->client = new Guzzle(['base_uri' => $this->urls[$this->mode]]);
            $this->options = array_replace_recursive($this->options, $_options, ['headers' => ['User-Agent' => $this->setUserAgent()]]);
        }

        function __call(string $_method, array $_arguments)
        {
            $time_start = microtime(true);
            $_method = strtolower($_method);

            if(!in_array($_method, $this->methods))
            {
                throw new Exception("Dispatch method ".$_method." not allowed (".implode(", ", $this->methods).")");
            }
            elseif((!$event = trim($_arguments[0] ?? "")) || !is_string($event) || empty($event))
            {
                throw new Exception("Dispatch can't send anything without an event as second argument");
            }
            elseif(false == preg_match("/^[A-Za-z0-9_]*$/", $event))
            {
                throw new Exception("Event [".$event."] contains non alpha-numeric(_) characters");
            }

            $query_data = array_replace_recursive(array(
                'headers' => array(
                    'Authorization' => $this->api_key
                )
            ), $this->options);

            if($user = $_arguments[1] ?? false)
            {
                $query_data[RequestOptions::JSON]['user'] = $user;
            }

            if($payload = $_arguments[2] ?? false)
            {
                $query_data[RequestOptions::JSON]['payload'] = $payload;
            }

            try
            {
                $request = $this->client->post("dispatch/" . $event . (($_method == "send") ? null : "/" . $_method), $query_data);

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
			catch(ClientException $e)
			{
				throw new Exception($e->getResponse()->getBody()->getContents());
			}
            catch(Exception $e)
            {
                throw new Exception($e->getMessage());
            }

            return $response_array + ['execution_time' => (microtime(true) - $time_start)];
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
    }
?>