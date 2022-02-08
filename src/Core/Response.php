<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	class Response
	{
		private static $data;

		public static function json($_array)
		{
			self::$data = $_array;

			return new static();
		}

		public function dispatch()
		{
			header('content-type: application/json; charset=utf-8');
			echo json_encode(self::$data, JSON_PRETTY_PRINT);	
			exit();
		}
	}
?>