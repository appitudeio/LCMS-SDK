<?php
	/**
	 *	Collection of Menus
	 */
	namespace LCMS\Core;

	use LCMS\Core\Navigation;
	use LCMS\Core\Route;
	use \Exception;

	class Navigations
	{
		use \LCMS\Utils\Singleton;

		private $collection = array();
		private $route;

		public function add(string $_identifier, Navigation $_menu): Self
		{
			$this->getInstance()->collection[$_identifier] = $_menu;

			return $this->getInstance();
		}

		public function get(string $_identifier): Navigation
		{
			if(!isset($this->getInstance()->collection[$_identifier]))
			{
				throw new Exception("Menu ".$_identifier." does not exist among " . implode(", ", array_keys($this->collection)));
			}

			return $this->getInstance()->collection[$_identifier]($this->route);
		}

		public function has(string $_identifier): Bool
		{
			return isset($this->getInstance()->collection[$_identifier]);
		}

		public function getAll(): Array
		{
			return $this->getInstance()->collection;
		}

		public function asTree(): Array
		{
			return (empty($this->getInstance()->getAll())) ? array() : array_keys($this->getInstance()->collection);
		}

		public function merge(array $_menus): Self
		{
			foreach($_menus AS $menu_identifier => $menu_items)
			{
				$this->getInstance()->collection[$menu_identifier]->merge($menu_items);
			}

			return $this->getInstance();
		}

		public function setRoute(Route $_route)
		{
			$this->route = $_route;
		}
	}
?>