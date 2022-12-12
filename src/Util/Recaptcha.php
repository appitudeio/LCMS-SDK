<?php
	/**
	 *	Recaptcha validation
	 * 
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2021-09-06
	 */
	namespace LCMS\Util;

	use LCMS\Core\Request;
	use GuzzleHttp\Client;
	use \Exception;

	class Recaptcha
	{
		private $secret;

		function __construct(string $_secret)
		{
			$this->secret = $_secret;
		}

		public function validate(Request $request): Bool
		{
			if(!$request->get("g-recaptcha-response", false))
			{
				throw new Exception("Recaptcha request token missing");
			}

			$guzzle = new Client();

			$response = $guzzle->request("GET", "https://www.google.com/recaptcha/api/siteverify?secret=".$this->secret."&response=".$request->get("g-recaptcha-response")."&remoteip=".$request->ip());
			$response_object = json_decode((string) $response->getBody(), true);

			if(!isset($response_object['success'], $response_object['hostname']) || !$response_object['success'] || $response_object['hostname'] != $request->getHost())
			{
				throw new Exception("Recaptcha response validation error");
			}

			return true;
		}
	}
?>