<?php
	/**
	 *	Collection of Menus
	 */
	namespace LCMS\Page;

	use LCMS\Page\Navigation;
	use LCMS\Core\Route;
	use \Exception;

	class Navigations
	{
		private $collection = array();
		private $route;

		public function add(string $_identifier, Navigation $_menu): self
		{
			$this->collection[$_identifier] = $_menu;

			return $this;
		}

		public function get(string $_identifier): Navigation
		{
			if(!isset($this->collection[$_identifier]))
			{
				throw new Exception("Menu ".$_identifier." does not exist among " . implode(", ", array_keys($this->collection)));
			}

			return $this->collection[$_identifier]($this->route);
		}

		public function has(string $_identifier): bool
		{
			return isset($this->collection[$_identifier]);
		}

		public function getAll(): array
		{
			return $this->collection;
		}

		public function asTree(): array
		{
			return (empty($this->getAll())) ? array() : array_keys($this->collection);
		}

		public function merge(array $_menus): self
		{
			foreach($_menus AS $menu_identifier => $menu_items)
			{
				$this->collection[$menu_identifier]->merge($menu_items);
			}

			return $this;
		}

		public function setRoute(Route $_route): self
		{
			$this->route = $_route;

			return $this;
		}
	}
?>