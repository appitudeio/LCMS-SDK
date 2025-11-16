<?php
    namespace LCMS\Api;

    use GuzzleHttp\Client as GuzzleClient;
    use GuzzleHttp\Exception\GuzzleException;
    use Exception;

    class Inbox
    {
        private string $api_key;
        private GuzzleClient $client;
        private string $base_uri = 'https://apirest.logicalcms.com/3.0'; //apirest == directly to LCMS-server

        public function __construct(string $api_key)
        {
            $this->api_key = $api_key;

            $this->client = new GuzzleClient([
                'headers' => [
                    'Authorization' => $api_key,
                    'User-Agent' => 'LCMS-SDK/3.4 (+https://github.com/appitudeio/lcms-sdk)'
                ]
            ]);
        }

        /**
         * Send an inbox message.
         *
         * @param string $subject
         * @param string $message
         * @param mixed  $sender
         * @param array  $receivers
         *
         * @return array
         *
         * @throws Exception
         */
        public function send(string $subject, string $message, $sender, array $receivers = []): array
        {
            $options = [
                'form_params' => [
                    'api_key'   => $this->api_key,
                    'subject'   => $subject,
                    'message'   => $message,
                    'sender'    => $sender,
                    'receivers' => $receivers
                ]
            ];

            return $this->sendRequest('POST', 'inbox', $options);
        }

        /**
         * Send an HTTP request to the API.
         *
         * @param string $method   HTTP method
         * @param string $endpoint API endpoint (relative URI)
         * @param array  $options  Options (json, form_params, etc.)
         *
         * @return array
         *
         * @throws Exception
         */
        private function sendRequest(string $method, string $endpoint, array $options = []): array
        {
            $uri = $this->base_uri . '/' . ltrim($endpoint, '/');

            try
            {
                $response = $this->client->request($method, $uri, $options);
            }
            catch (GuzzleException $e)
            {
                throw new Exception("HTTP Request failed: " . $e->getMessage(), $e->getCode(), $e);
            }

            $body = (string) $response->getBody();
            
            if(!json_validate($body))
            {
                throw new Exception("Invalid JSON response: " . $body);
            }

            $data = json_decode($body, true);

            if (isset($data['error']) || isset($data['errors']))
            {
                $errorMessage = $data['error'] ?? $data['errors'][0]['message'] ?? 'Unknown error';
                throw new Exception("API Error: " . $errorMessage);
            }

            return $data;
        }
    }
?>