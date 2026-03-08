<?php
    namespace LCMS\Api;

    use GuzzleHttp\Client as GuzzleClient;
    use GuzzleHttp\Exception\GuzzleException;
    use Exception;

    class Inbox
    {
        private GuzzleClient $client;
        private string $base_uri = 'https://api.logicalcms.com';

        public function __construct(private string $api_key, private string $domain)
        {
            $this->client = new GuzzleClient([
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'LCMS-SDK/3.7 (+https://github.com/appitudeio/lcms-sdk)'
                ]
            ]);
        }

        /**
         * Store an inbox message and optionally notify receivers.
         *
         * @param string $subject
         * @param string $message
         * @param string|array $sender  Email string or array with 'email' and optional 'name'
         * @param array  $receivers     Email addresses to notify
         *
         * @return array
         *
         * @throws Exception
         */
        public function send(string $subject, string $message, string|array $sender, array $receivers = []): array
        {
            return $this->sendRequest('POST', $this->domain . '/inbox', [
                'json' => [
                    'subject'   => $subject,
                    'message'   => $message,
                    'sender'    => $sender,
                    'receivers' => $receivers
                ]
            ]);
        }

        /**
         * Send an email via Sendivent.
         *
         * @param string $to        Recipient email address
         * @param string $subject   Email subject
         * @param array  $params    Template parameters (body, etc.)
         * @param array  $options   Optional overrides: event, channel, reply_to
         *
         * @return array
         *
         * @throws Exception
         */
        public function sendEmail(string $to, string $subject, array $params = [], array $options = []): array
        {
            $body = [
                'to'      => $to,
                'subject' => $subject,
                'params'  => $params
            ];

            if (isset($options['event']))
            {
                $body['event'] = $options['event'];
            }

            if (isset($options['channel']))
            {
                $body['channel'] = $options['channel'];
            }

            if (isset($options['reply_to']))
            {
                $body['reply_to'] = $options['reply_to'];
            }

            return $this->sendRequest('POST', $this->domain . '/email', [
                'json' => $body
            ]);
        }

        /**
         * Send an HTTP request to the API.
         *
         * @param string $method   HTTP method
         * @param string $endpoint API endpoint (relative URI)
         * @param array  $options  Guzzle request options
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