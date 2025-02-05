<?php
    namespace LCMS\Api;

    use LCMS\Api\Client;
    use Exception;

    class Inbox extends Client
    {
        protected string $version = '/3.0';

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
    }
?>