<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Util\Singleton;

	class Response
	{
		use Singleton {
			Singleton::__construct as private SingletonConstructor;
		}

		private array $data = [];

		protected function json(array $_array): self
		{
			$this->data = $_array;

			return $this;
		}

		public function dispatch(): never
		{
			header('content-type: application/json; charset=utf-8');
			echo json_encode($this->data, JSON_PRETTY_PRINT);	
			exit();
		}

		protected function __toString(): string
		{
			return json_encode($this->data);
		}
	}
?>