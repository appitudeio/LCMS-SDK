<?php
	/**
	 *	Traversable menu from the root
	 */
	namespace LCMS\Page;

	use LCMS\Core\Route;
	use LCMS\Core\Locale;
	use LCMS\DI;

	use \Closure;
	use \Exception;

	class Navigation implements \Iterator
	{
		private int 	$index = 0;
		private array 	$currents;
		private array 	$params;
		private array 	$root;
		private bool 	$sorted = true;
		private array 	$active_keys = array();
		public array 	$items = array();

		function __construct(string $_identifier)
		{
			$this->params = array('identifier' => $_identifier);
		}

		function __invoke(): self
		{
			return $this;
		}

		public function add(string $_title = null, string $_route = null, array $_params = null): self
		{
			$item = array(
				'key'	=> $this->getCurrentKey() + 1,
				'order'	=> count($this->items)
			);

			if(!empty($_title))
			{
				$item['title'] = $_title;
			}

			if(!empty($_route))
			{
				$item['route'] = $_route;
			}

			if(!empty($_params))
			{
				$item['parameters'] = $_params;
			}			

			$this->items[] = $item;

			/**
			 *	Chain this added item into it's parent
			 */
			if(!empty($this->currents))
			{
				$current = $this->currents[count($this->currents) - 1];

				if(!isset($this->items[$current['key']]['children']))
				{
				 	$this->items[$current['key']]['children'] = array();
				}

				$this->items[$this->getCurrentKey()]['parent'] = $current['key'];
				$this->items[$current['key']]['children'][] = $this->getCurrentKey();
			}
			else
			{
				// If no parent, it's a root item
				$this->root[] = $this->getCurrentKey();
			}

			return $this;
		}

		public function getActiveKey(): int
		{
			return $this->active_keys[array_key_last($this->active_keys)];
		}

		public function getActiveKeys(): array
		{
			return $this->active_keys;	
		}

		public function setActiveKey(int $_key): void
		{
			$this->active_keys[] = $_key;
		}

		public function remove(int $_key = null): void
		{
			$_key = (!empty($_key)) ? $_key : $this->index; 
			unset($this->items[$_key]);
		}

		public function group(Closure $_callback): void
		{
			$this->currents[] = $this->items[$this->getCurrentKey()];

			$_callback($this);

			unset($this->currents[count($this->currents) - 1]);

			$this->currents = array_values($this->currents);
		}

		private function getCurrentKey(): int
		{
			return count($this->items) - 1;
		}

		public function current(): mixed
		{
			return $this->items[$this->root[$this->key()]];
		}

		public function next(): void
		{
			++$this->index;
		}

		public function rewind(): void
		{
			if(!$this->sorted)
			{
				$this->sort();
			}

			if(DI::get(Route::class))
			{
				$this->sortActive();
			}
			
			$this->index = 0;
		}

		public function key(): mixed
		{
			return $this->index;
		}

		public function valid(): bool
		{
			return isset($this->root[$this->key()]);
		}

		private function sortActive()
		{
			// Get whole Route Tree aliases
			$route = DI::get(Route::class);

			if(!$current_route = $route->getCurrentMatched())
			{
				return;
			}

			$current_route_aliases = $route->asTreeAliases($current_route);

			foreach(array_filter($this->items, fn($i) => isset($i['route'])) AS $item)
			{
				// If direct hit
				if(!in_array($item['route'], $current_route_aliases))
				{
					// Try to grab from children
					if(!isset($item['children']))
					{
						continue;
					}

					foreach(array_filter(array_map(fn($k) => $this->items[$k], $item['children']), fn($i) => isset($i['route'])) AS $child_item)
					{
						if(!in_array($child_item['route'], $current_route_aliases))
						{
							continue;
						}

						$this->items[$child_item['key']]['active'] = true;
						$this->setActiveKey($child_item['key']);

						// All parents to become active
						foreach($this->asTreeParents($child_item['key']) AS $parent_key)
						{
							$this->items[$parent_key]['active'] = true;
						}
					}

					continue;
				}

				$this->items[$item['key']]['active'] = true;
				$this->setActiveKey($item['key']);
			}
		}

		private function asTreeParents(int $child_key, array $parents = array()): array // Recursive
		{
			$parents[] = $this->items[$child_key]['parent'];

			if(isset($this->items[$this->items[$child_key]['parent']]['parent']))
			{
				return $this->asTreeParents($this->items[$child_key]['key'] , $parents);
			}

			return $parents;
		}

		public function children(int $_key = null): array
		{
			$_key = (empty($_key)) ? $this->item($this->index)['key'] : $_key;

			if(empty($this->item($_key)['children']))
			{
				return array();
			}

			return array_map(fn($k) => $this->item($k), $this->item($_key)['children']);
		}

		public function items(): array
		{
			return $this->items;
		}

		public function item(int $_key): array | null
		{
			// Current MenuItem aliases
			if(!($this->items[$_key] ?? false))
			{
				return null;
			}

			if(isset($this->items[$_key]['parent']))
			{
				if($aliases = $this->recursiveParentAlias($this->items[$this->items[$_key]['parent']], (isset($this->items[$_key]['route'])) ? array($this->items[$_key]['route']) : array()))
				{
					$this->items[$_key]['aliases'] = $aliases;
				}
			}
			elseif(isset($this->items[$_key]['route']))
			{
				$this->items[$_key]['aliases'] = array($this->items[$_key]['route']);
			}

			return $this->items[$_key];
		}

		private function recursiveParentAlias(array $menu_item, array $aliases): array
		{
			if(isset($menu_item['route']))
			{
				$aliases[] = $menu_item['route'];
			}

			if(isset($menu_item['parent']))
			{
				return $this->recursiveParentAlias($this->items[$menu_item['parent']], $aliases);
			}

			return $aliases;
		}

		private function sort(): void
		{
			$this->sorted = true;

			$this->root = $this->sortRecursive($this->root);
		}

		public function merge(array $_items): self
		{
			foreach($_items AS $k => $nav_item)
			{
				$this->items[$k] = $nav_item;

				if(empty($nav_item['parent_id']) && !in_array($nav_item['key'], $this->root))
				{
					$this->root[$k] = $nav_item['key'];
				}
			}

			$this->sorted = false;

			return $this;
		}

		public function asArray(): array
		{
			return array_merge($this->params, array('items' => $this->items));
		}

		private function sortRecursive(array $_keys): array
		{
			$language = DI::get(Locale::class)->getLanguage();

			$items = array_filter(array_map(function($key) use($language)
			{
				if(isset($this->items[$key]['hidden_at'], $this->items[$key]['hidden_at'][$language]))
				{
					return false;
				}
				elseif(isset($this->items[$key]['children']) && count($this->items[$key]['children']) > 1)
				{
					$this->items[$key]['children'] = $this->sortRecursive($this->items[$key]['children']);
				}

				if(empty($this->items[$key]['route']) && isset($this->items[$key]['route_id']))
				{
					$this->items[$key]['route'] = $this->items[$key]['route_id'];
				}

				return $this->items[$key];
			}, $_keys));
			
			usort($items, fn($a, $b) => $a['order'] <=> $b['order']);

			return array_column($items, "key");
		}
	}
?>