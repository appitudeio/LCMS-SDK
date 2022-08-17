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
        const CLIENT_NAME = 'LCMS-SDK';
        const CLIENT_VERSION = '0.1';
        const CLIENT_URL = 'https://github.com/appitudeio/lcms-sdk';        
        const API_URL = "https://api.logicalcms.com";
        private $api_key;
        private $options = array(
            'debug' => true
        );
        private $client;

        function __construct(string $_api_key, array $_options = array())
        {
            $this->api_key = $_api_key;
            $this->client = new Guzzle(['base_uri' => self::API_URL]);
            $this->options = array_replace_recursive($this->options, $_options, ['headers' => ['User-Agent' => $this->setUserAgent()]]);
        }

        /**
         *  Dispatches an event, e.g email or SMS
         *      [string $to, string | int $from] || string $to
         */
        public function send(string $_event, array | string $_to_from = null, array $_body = null): array
        {
            try
            {
                $request = $this->request("POST", "/dispatch", $_body + ['event' => $_event, 'to' => (is_array($_to_from)) ? $_to_from[0] : $_to_from, 'from' => (is_array($_to_from)) ? $_to_from[1] ?? null : null]);

				if(!$response_array = json_decode((string) $request->getBody(), true))
				{
                    throw new Exception($request->getBody());
				}
				elseif(isset($response_array['error']))
				{
					throw new Exception(json_encode(['error' => $response_array['error']]));
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

            return $response_array;
        }

        private function request(string $_method = "POST", string $_endpoint = "dispatch", array $_data = array()): Response
        {
            $query_data = array(
                'headers' => array(
                    'Authorization' => $this->api_key
                )
            );

            if(isset($this->options['headers']))
            {
                $query_data['headers'] = array_merge($query_data['headers'], $this->options['headers']);
            }

            if($_method == "POST")
            {
                $query_data[RequestOptions::JSON] = $_data;
            }
            else
            {
                $query_data['query'] = $_data;
            }

            return $this->client->request($_method, $_endpoint, $query_data); //, $this->options);
        }

        public function setUserAgent(): string
        {
            return self::CLIENT_NAME.'/'.self::CLIENT_VERSION.' (+'.self::CLIENT_URL.')';
        }
    }
?>