<?php
    namespace LCMS\Api;

    use GuzzleHttp\Client as GuzzleClient;
    use GuzzleHttp\Exception\GuzzleException;
    use Psr\Http\Message\ResponseInterface;
    use Exception;

    abstract class Client
    {
        protected HttpClient $httpClient;
        protected string $api_key;
        
        // Define both production and sandbox URIs.
        protected array $base_uris = [
            'production' => 'https://api.logicalcms.com',
            'sandbox'    => 'https://api-sandbox.logicalcms.com',
        ];
        
        public function __construct(string $api_key, string $mode = "sandbox", array $http_config = [])
        {
            $this->api_key = $api_key;
            
            if (!isset($this->base_uris[$mode])) 
            {
                throw new Exception("Invalid API mode: $mode");
            }
            
            // Optionally append a version or sub-path for this client.
            $baseUri = $this->base_uris[$mode];

            if (isset($this->version)) 
            {
                $baseUri .= $this->version;
            }
            
            $http_config['headers']['Authorization'] = $api_key;
            $this->httpClient = new HttpClient($base_uri, $http_config);
        }
        
        /**
         * Send a request and decode the JSON response.
         *
         * @param string $method  HTTP method
         * @param string $uri     API endpoint
         * @param array  $options Guzzle options such as json, form_params, etc.
         *
         * @return array
         *
         * @throws Exception on error
         */
        protected function sendRequest(string $method, string $uri, array $options = []): array
        {
            try 
            {
                $response = $this->httpClient->request($method, $uri, $options);
            } 
            catch (GuzzleException $e) 
            {
                throw new Exception("HTTP Request failed: " . $e->getMessage(), $e->getCode(), $e);
            }

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) 
            {
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }
            elseif (isset($data['error']) || isset($data['errors'])) 
            {
                $errorMessage = $data['error'] ?? $data['errors'][0]['message'] ?? 'Unknown error';
                throw new Exception("API Error: " . $errorMessage);
            }
            
            return $data;
        }
    }

    class HttpClient
    {
        private GuzzleClient $client;

        public function __construct(string $base_uri, array $config = [])
        {
            $default_config = [
                'base_uri' => $base_uri,
                'headers'  => [
                    'User-Agent' => $config['user_agent'] ?? 'LCMS-SDK/3.3 (+https://github.com/appitudeio/lcms-sdk)'
                ]
            ];

            $this->client = new GuzzleClient(array_merge($default_config, $config));
        }

        /**
         * Send a request.
         *
         * @param string $method   HTTP method (GET, POST, etc.)
         * @param string $uri      Relative URI (endpoint)
         * @param array  $options  Guzzle options (json, form_params, etc.)
         *
         * @return ResponseInterface
         *
         * @throws GuzzleException
         */
        public function request(string $method, string $uri, array $options = []): ResponseInterface
        {
            return $this->client->request($method, ltrim($uri, '/'), $options);
        }
    }
?>