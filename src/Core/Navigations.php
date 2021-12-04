<?php
	/**
	 *	Collection of Menus
	 */
	namespace LCMS\Core;

	use LCMS\Core\Navigation;
	use \Exception;

	class Navigations
	{
		use \LCMS\Utils\Singleton;

		private $collection = array();

		public function add($_identifier, Navigation $_menu)
		{
			$this->getInstance()->collection[$_identifier] = $_menu;			

			return $this->getInstance();
		}

		public function get($_identifier)
		{
			if(!isset($this->getInstance()->collection[$_identifier]))
			{
				throw new Exception("Menu ".$_identifier." does not exist among " . implode(", ", array_keys($this->collection)));
			}

			return $this->getInstance()->collection[$_identifier];
		}

		public function getAll()
		{
			return $this->getInstance()->collection;
		}

		public function asTree()
		{
			return (empty($this->getInstance()->getAll())) ? array() : array_keys($this->getInstance()->collection);
		}

		public function merge($_menus)
		{
			foreach($_menus AS $menu_identifier => $menu_items)
			{
				$this->getInstance()->collection[$menu_identifier]->merge($menu_items);
			}

			return $this->getInstance();
		}
	}
?>