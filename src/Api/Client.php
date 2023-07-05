<?php
	/** 
	 *
	 */
	namespace LCMS\Api;

	use LCMS\Core\Request;
	use LCMS\Core\Env;
	use LCMS\Util\Singleton;

    use GuzzleHttp\Client as Guzzle;
    use GuzzleHttp\RequestOptions;
    use GuzzleHttp\Psr7\Response;
    use GuzzleHttp\Exception\ClientException;	
	use \ReflectionClass;
	use \Exception;

	class Client
	{
		use Singleton;

        const CLIENT_NAME = "LCMS-SDK";
        const CLIENT_VERSION = "3.1";
        const CLIENT_URL = "https://github.com/appitudeio/lcms-sdk";
        
        protected $methods = array();
        protected $urls = array(
            'production' => "https://api.logicalcms.com",
            'sandbox' => "https://api-sandbox.logicalcms.com"
        );
        protected $api_key;
        protected $options = array();
        protected $timings;

        function __construct(string $_api_key = null, array $_options = array())
        {
            $this->api_key = $_api_key;
            $this->options = array_replace_recursive($this->options, $_options, ['headers' => ['User-Agent' => $this->setUserAgent()]]);
        }

        protected function sendRequest(string $_mode = "sandbox", string $_endpoint = "", array $_request_data = array(), string $_method = "post"): array
        {
            $query_data = array_replace_recursive(array(
                'headers' => array(
                    'Authorization' => $this->api_key
                )
            ), $this->options);

            if(isset($query_data['batch']))
            {
                $query_data[RequestOptions::JSON] = $query_data[RequestOptions::JSON] ?? array();
                $query_data[RequestOptions::JSON]['users'] = $_request_data['user']; 
             
                unset($query_data['batch']);
            }
            elseif(isset($_request_data['user']))
            {  
                $query_data[RequestOptions::JSON] = $query_data[RequestOptions::JSON] ?? array();
                $query_data[RequestOptions::JSON]['user'] = $_request_data['user'];
            }

            if(isset($_request_data['from']))
            {  
                $query_data[RequestOptions::JSON] = $query_data[RequestOptions::JSON] ?? array();
                $query_data[RequestOptions::JSON]['from'] = $_request_data['from'];
            }
            
            if(isset($_request_data['payload']))
            {
                $query_data[RequestOptions::JSON] = $query_data[RequestOptions::JSON] ?? array();
                $query_data[RequestOptions::JSON]['payload'] = $_request_data['payload'];
            }

			if(isset($_request_data['form_params']))
			{
				$query_data['form_params'] = $_request_data['form_params'];
			}

            try
            {
                if(isset($query_data['silent']))
                {
                    // Send this message silently
                    $cmd = "curl -L -X POST -H 'Content-Type: application/json' -H 'Authorization: ".$query_data['headers']['Authorization']."'";
                    $cmd .= " -d '" . json_encode($query_data[RequestOptions::JSON] ?? "") . "' '".$this->urls[$_mode] . "/" . $endpoint . "'";
                    $cmd .= " > /dev/null 2>&1 &"; // Don't wait for response

                    exec($cmd, $output, $exit);

                    return array('success' => $exit == 0);
                }
                else
                {
                    $client = new Guzzle(); //['base_uri' => $this->urls[$_mode]]);
                    $request = $client->$_method($this->urls[$_mode] . "/" . ltrim($_endpoint, "/"), $query_data);

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

			if(isset($this->timings[0]))
			{
				return $response_array + ['execution_time' => (microtime(true) - $this->timings[0])];
			}

            return $response_array;
        }

        protected function validate(string $_method, array $_arguments): array
        {
            return array("sandbox", $_arguments);
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