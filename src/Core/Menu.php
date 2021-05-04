<?php
	/**
	 *	Traversable menu from the root
	 */
	namespace LCMS\Core;

	use \Exception;

	class Menu implements \Iterator
	{
		private $index = 0;
		private $currents;
		private $params;
		private $root;
		private $sorted = true;
		public $items = array();

		function __construct($_identifier)
		{
			$this->params = array('identifier' => $_identifier);
		}

		public function add($_title = null, $_route = null, $_params = null)
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
				 	$this->items[$current['key']]['children'] 	= array();
				}

				$this->items[$this->getCurrentKey()]['parent'] 	= $current['key'];
				$this->items[$current['key']]['children'][] 	= $this->getCurrentKey();
			}
			else
			{
				// If no parent, it's a root item
				$this->root[] = $this->getCurrentKey();
			}

			return $this;
		}

		public function group($_callback)
		{
			$this->currents[] = $this->items[$this->getCurrentKey()];

			$_callback($this);

			unset($this->currents[count($this->currents) - 1]);

			$this->currents = array_values($this->currents);
		}

		private function getCurrentKey()
		{
			return count($this->items) - 1;
		}

		public function current()
		{
			return $this->items[$this->root[$this->index]];
		}

		public function next()
		{
			$this->index++;
		}

		public function rewind()
		{
			if(!$this->sorted)
			{
				$this->sort();
			}

			$this->index = 0;
		}

		public function key()
		{
			return $this->index;
		}

		public function valid()
		{
			return isset($this->root[$this->key()]);
		}

		public function children($_key = null)
		{
			$_key = (empty($_key)) ? $this->root[$this->index] : $_key;

			if(empty($this->item($_key)['children']))
			{
				return array();
			}

			return array_map(function($k)
			{
				return $this->item($k);
			}, $this->item($_key)['children']);
		}

		public function items()
		{
			/*if(!$this->sorted)
			{
				$this->sort();
			}*/

			return $this->items;
		}

		public function item($_key)
		{
			return $this->items[$_key];
		}

		private function sort()
		{
			$this->sorted = true;

			$this->root = $this->sortRecursive($this->root);
		}

		public function merge($_items)
		{
			foreach($_items AS $k => $v)
			{
				$this->items[$k] = $v;

				if(empty($v['parent_id']))
				{
					$this->root[$k] = $v['key'];
				}
			}

			$this->sorted = false;

			return $this;
		}

		public function asArray()
		{
			return array_merge($this->params, array('items' => $this->items));
		}

		private function sortRecursive($keys)
		{
			$items = array_map(function($key)
			{
				if(isset($this->items[$key]['children']) && count($this->items[$key]['children']) > 1)
				{
					$this->items[$key]['children'] = $this->sortRecursive($this->items[$key]['children']);
				}

				if(empty($this->items[$key]['route']) && isset($this->items[$key]['route_id']))
				{
					$this->items[$key]['route'] = $this->items[$key]['route_id'];
				}

				return $this->items[$key];
			}, $keys);

			usort($items, function($a, $b)
			{
				return $a['order'] <=> $b['order'];
			});

			return array_column($items, "key");
		}

		/*public function merge($_menu_items)
		{
			die("MEnu Merg3!");

			$database_relations = array();

			foreach($_menu_items AS $k => $r)
			{
				if(isset($r['id']))
				{
					$database_relations[$r['id']] = $k;
				}

				self::$items[$k] = (isset(self::$items[$k])) ? array_merge(self::$items[$k], $r) : $r;
				
				if(!isset(self::$items[$k]['key']))
				{
					self::$items[$k]['key'] = $k;
				}

				if(!empty($r['alias']) && !isset(self::$relations[$r['alias']]))
				{
					self::$relations[$r['alias']] = $k;
				}

				if(isset($r['parent_id']) && !empty($r['parent_id']) && !isset(self::$items[$k]['parent']))
				{
					self::$items[$k]['parent'] = $database_relations[$r['parent_id']];
				}

				if(!in_array($k, self::$map[Request::METHOD_GET]))
				{
					self::$map[Request::METHOD_GET][] = $k;
				}
			}

			return self::$instance;
		}		*/	
	}
?>