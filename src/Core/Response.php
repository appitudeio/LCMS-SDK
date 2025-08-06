<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	class Response
	{
		private static array $data;

		public static function json(array $_array): self
		{
			self::$data = $_array;

			return new static();
		}

		public function dispatch(): never
		{
			header('content-type: application/json; charset=utf-8');
			echo json_encode(self::$data, JSON_PRETTY_PRINT);	
			exit();
		}

		public function __toString(): string
		{
			return json_encode(self::$data);
		}
	}
?>