<?php
	/**
	 *	Array functions
	 * 		- Inspired by Laravel array functions
	 * 		@ https://laravel.com/docs/8.x/helpers
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2021-10-17
	 */
	namespace LCMS\Utils;

	class Arr
	{
		public static function get($array, $key, $default = null)
		{
			if(!is_array($array))
			{
				return $default;
			}
			elseif(is_null($key)) 
			{
				return $array;
			}
			elseif(isset($array[$key])) 
			{
				return $array[$key];
			}
			elseif(strpos($key, '.') === false) 
			{
				return $array[$key] ?? $default;
			}

			foreach(explode('.', $key) AS $segment) 
			{
				if (is_array($array)) 
				{
					if(isset($array[$segment]))
					{
						$array = $array[$segment];
					}
					else
					{
						return false;
					}
				}
				else 
				{
					return $default;
				}
			}

			return $array;
		}	

		public static function has($array, $keys)
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

		public static function unflatten(&$array, $key, $value)
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

		public static function flatten($array, $prepend = '')
		{
			$results = array();

			foreach ($array AS $key => $value) 
			{
				if (is_array($value) && ! empty($value)) 
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
	}
?>