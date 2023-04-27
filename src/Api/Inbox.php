<?php
    /**
     *  Dispatcher API Client v0.1
     */
    namespace LCMS\Api;

    use LCMS\Api\Client;
    use \Exception;

    class Inbox extends Client
    {
        function __construct(string $_api_key, array $_options = array())
        {
            $this->urls['production'] .= "/3.0";
            $this->urls['sandbox'] .= "/3.0";

            parent::__construct($_api_key, $_options);
        }

        public function send(string $_subject, string $_message, mixed $_sender, array $_receivers = array())
        {
            return $this->sendRequest("production", "inbox", array(
                'form_params' => array(
                    'api_key' => $this->api_key,
                    'subject' => $_subject,
                    'message' => $_message,
                    'sender' => $_sender,
                    'receivers' => $_receivers
                )
            ));
        }
    }
?>