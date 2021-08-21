<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Database as DB;
	use \Exception;

	class ErrorHandler
	{
		public static function error($code, $message, $file = null, $line = null, $error_context = null)
		{
			return self::log("error", array(
				'code'		=> $code,
				'message'	=> $message,
				'file'		=> $file,
				'line'		=> $line
			));
		}

		public static function exception($e)
		{
			return self::log("exception", array(
				'code'		=> $e->getCode(),
				'message'	=> $e->getMessage(),
				'file'		=> $e->getFile(),
				'line'		=> $e->getLine()
			));
		}

		private static function log($type, $params): Void
		{
			pre($params);

			DB::insert(Env::get("db")['database'].".`lcms_error_log`", array('type' => $type, 'endpoint' => self::getEndpoint()) + $params);
		}

		private static function getEndpoint()
		{
			if(!isset($_SERVER['REQUEST_URI']) || empty($_SERVER['REQUEST_URI']))
			{
				return null;
			}

			$scheme = (self::isSecureConnection()) ? "https://" : "http://";
			return (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) ? $scheme . $_SERVER['HTTP_HOST'] . escape($_SERVER['REQUEST_URI']) : null;
		}

		private static function isSecureConnection()
		{
			return (bool) ((isset($_SERVER['HTTP_X_FORWARDED_PORT']) && $_SERVER['HTTP_X_FORWARDED_PORT'] == "443") || ((isset($_SERVER['HTTPS']) && (($_SERVER['HTTPS'] == 'on') || ($_SERVER['HTTPS'] == '1')))) || ((isset($_SERVER['SERVER_PORT'])) && ($_SERVER['SERVER_PORT'] == 443)));
		}		
	}
?>