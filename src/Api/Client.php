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
        protected array $options = []; // Options such as debug or silent mode.
        protected bool $is_exec_enabled;
        protected ?string $version;
        
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
            $base_uri = $this->base_uris[$mode] . '/';

            if (!empty($this->version))
            {
                $base_uri .= trim($this->version, '/') . '/';
            }
            
            $http_config['headers']['Authorization'] = $api_key;
            $this->httpClient = new HttpClient($base_uri, $http_config);
        }

        /**
         * Enable silent mode.
         */
        public function silent(): self
        {
            $this->options['silent'] = true;
            return $this;
        }
        
        /**
         * Send an HTTP request. If silent mode is enabled,
         * delegate to sendSilentRequest().
         *
         * @param string $method   HTTP method
         * @param string $uri      API endpoint (relative URI)
         * @param array  $options  Options (json, form_params, etc.)
         *
         * @return array
         *
         * @throws Exception
         */
        protected function sendRequest(string $method, string $uri, array $options = []): array
        {
            if (isset($this->options['silent']) && $this->options['silent'] === true && $this->isExecEnabled()) 
            {
                return $this->sendSilentRequest($method, $uri, $options);
            }
            
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

        /**
         * Send a request silently using exec() and curl.
         *
         * @param string $method   HTTP method (e.g. POST)
         * @param string $endpoint API endpoint (relative URI)
         * @param array  $options  Request options (e.g. JSON payload)
         *
         * @return array
         *
         * @throws Exception
         */
        protected function sendSilentRequest(string $method, string $endpoint, array $options = []): array
        {
            if(!$this->isExecEnabled()) 
            {
                throw new Exception("exec() is disabled on this server.");
            }

            // Get the base URI from the current HttpClient configuration.
            $baseUri = $this->httpClient->getBaseUri();
            $fullUri = rtrim($baseUri, '/') . '/' . ltrim($endpoint, '/');
            
            $headers = [
                "Content-Type: application/json",
                "Authorization: ".$this->api_key
            ];
            $headers_string = implode("' -H '", $headers);
            
            // Prepare the data.
            $data = '';
            if (isset($options['json'])) 
            {
                $data = escapeshellarg(json_encode($options['json']));
            } 
            elseif (isset($options['form_params'])) 
            {
                $data = escapeshellarg(json_encode($options['form_params']));
            }
            
            // Build the curl command.
            $cmd = "curl -L -X ".$method." -H '".$headers_string."' -d ".$data." '".$fullUri."' > /dev/null 2>&1 &";
            
            // Execute the command.
            exec($cmd, $output, $exit);
            
            return ['success' => $exit === 0];
        }

        private function isExecEnabled(): bool
        {
            if (isset($this->is_exec_enabled)) 
            {
                return $this->is_exec_enabled;
            }

            // Check if exec() is available.
            if (!function_exists('exec')) 
            {
                $this->is_exec_enabled = false;
                return $this->is_exec_enabled;
            }
            
            // Check if exec is disabled via php.ini
            $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
            if (in_array('exec', $disabled, true)) 
            {
                $this->is_exec_enabled = false;
                return $this->is_exec_enabled;
            }

            $this->is_exec_enabled = true;
            return $this->is_exec_enabled;
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

        public function getBaseUri(): string
        {
            return $this->client->getConfig('base_uri');
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