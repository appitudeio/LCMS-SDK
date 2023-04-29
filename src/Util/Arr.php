<?php
	/**
	 *	Array functions
	 * 		- Inspired by Laravel array functions
	 * 		@ https://laravel.com/docs/8.x/helpers
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2021-10-17
	 */
	namespace LCMS\Util;

	class Arr
	{
		/**
		 * 	If an int occurs in the key, stop and treat it as an array
		 */
		public static function get($array, $key, $default = false)
		{
			if(!is_array($array))
			{
				return $default;
			}
			elseif(is_null($key)) 
			{
				return $array;
			}
			elseif(array_key_exists($key, $array)) 
			{
				return $array[$key];
			}
			elseif(!str_contains($key, '.')) 
			{
				return $array[$key] ?? $default;
			}
			
			foreach(explode('.', $key) AS $segment) 
			{
				if(!is_array($array) || !array_key_exists($segment, $array)) 
				{
					return $default;
				}
					
				$array = $array[$segment];
			}

			return $array;
		}	

		public static function has($array, $keys): bool
		{
			$keys = (array) $keys;

			if (! $array || $keys === []) 
			{
				return false;
			}

			foreach ($keys AS $key) 
			{
				$subKeyArray = $array;

				if (array_key_exists($key, $array)) 
				{
					continue;
				}

				foreach (explode('.', $key) AS $segment) 
				{
					if (is_array($subKeyArray) && array_key_exists($segment, $subKeyArray))
					{
						$subKeyArray = $subKeyArray[$segment];
					} 
					else 
					{
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * 	Remove one or many array items from a given array using "dot" notation.
		 */
		public static function forget(array &$array, array | string $keys): Void
		{
			$original = &$array;
			$keys = (array) $keys;

			if(count($keys) === 0) 
			{
				return;
			}

			foreach($keys AS $key) 
			{
				// if the exact key exists in the top-level, remove it
				$parts = explode('.', $key);

				// clean up before each pass
				$array = &$original;

				while(count($parts) > 1) 
				{
					$part = array_shift($parts);

					if(!isset($array[$part]) || !is_array($array[$part])) 
					{
						continue 2;
					}
					
					$array = &$array[$part];
				}

				unset($array[array_shift($parts)]);
			}
		}

		public static function set(&$array, $key, $value)
		{
	        if (is_null($key)) 
	        {
	            return $array = $value;
	        }

	        $keys = explode('.', $key);

	        foreach ($keys AS $i => $key) 
	        {
	            if (count($keys) === 1) 
	            {
	                break;
	            }

	            unset($keys[$i]);

	            // If the key doesn't exist at this depth, we will just create an empty array
	            // to hold the next value, allowing us to create the arrays to hold final
	            // values at the correct depth. Then we'll keep digging into the array.
	            if (! isset($array[$key]) || ! is_array($array[$key])) 
	            {
	                $array[$key] = [];
	            }

	            $array = &$array[$key];
	        }

	        $array[array_shift($keys)] = $value;

	        return $array;
		}

		public static function unflatten(&$array, $key, $value)
		{
			return self::set($array, $key, $value);
		}

		public static function flatten($array, $prepend = '')
		{
			return self::dot($array, $prepend);
		}

		public static function dot($array, $prepend = '')
		{
			$results = array();

			foreach ($array AS $key => $value) 
			{
				if (is_array($value) && !empty($value)) 
				{
					$results = array_merge($results, self::flatten($value, $prepend.$key.'.'));
				}
				else
				{
					$results[$prepend.$key] = $value;
				}
			}

			return $results;
		}

		public static function undot($array)
		{
			$results = [];
	
			foreach ($array as $key => $value) 
			{
				self::set($results, $key, $value);
			}
	
			return $results;
		}		
	}
?>