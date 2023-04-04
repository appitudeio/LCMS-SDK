<?php
	namespace LCMS\Util;

	class Toolset
	{
		public static function isJson($_string)
		{
			try
			{
				return (bool) json_decode($_string, null, 512, JSON_THROW_ON_ERROR);
			}
			catch(\JsonException $e)
			{
				return false;
			}
		}

		public static function picture(String $_url, Array $_attributes = array(), String | Int $_size = null): String
		{
			$parts = self::pictureArray($_url, $_attributes, $_size);

			return self::pictureArrayToString($parts);
		}

		public static function pictureArray(String $_url, Array $_attributes = null, String | Int $_size = null): Array
		{
			//$_size = (!empty($_size) && !is_array($_size)) ? explode("x", $_size) : null;
			$parts = explode(".", $_url);
			$file_ending = $parts[count($parts) - 1];
			unset($parts[count($parts) - 1]);

			$_attributes = array_merge($_attributes ?? array(), array('loading' => "lazy"));

			$mime = ($file_ending == "jpg") ? "jpeg" : $file_ending;

			if($file_ending == "webp")
			{
				return array(
					array("source", array('srcset' => self::resize($_url, $_size), 'type' => "image/webp")),
					array("source", array('srcset' => self::imgTo($_url, $_size, "png"), 'type' => 'image/png')),
					array("img", array('src' => self::imgTo($_url, $_size, "png")) + $_attributes)
				);
			}
			elseif($file_ending == "svg")
			{
				return array(
					array("img", array('src' => $_url) + $_attributes)
				);
			}
			
			return array(
				array("source", array('srcset' => self::imgTo($_url, $_size), 'type' => "image/webp")),
				array("source", array('srcset' => self::resize($_url, $_size), 'type' => 'image/' . $mime)),
				array("img", array('src' => self::resize($_url, $_size)) + $_attributes)
			);
		}

		public static function pictureArrayToString(Array $picture_data)
		{
			$picture_parts = array_map(fn($picture) => 
				"<" . $picture[0] . ((isset($picture[1]) && !empty($picture[1])) 
					? " " . implode(" ", array_map(fn($key) => $key . '="' . $picture[1][$key] . '"', array_keys($picture[1]))) 
					: "") . ">", $picture_data);

			return "<picture>" . implode("", $picture_parts) . "</picture>";

		}

		public static function imgTo(string $_url, mixed $_size = null, string $_file_ending = "webp")
		{
			$image_url = implode(".", explode(".", $_url, -1)) . "." . $_file_ending;
	
			if(!empty($_size))
			{
				return self::resize($image_url, $_size);
			}
	
			return $image_url;
		}
	
		public static function resize(string $_url, mixed $_size = null)
		{
			if(empty($_size))
			{
				return $_url;
			}

			$parts = explode("/", $_url);

			if($parts[1] == "")
			{
				unset($parts[0], $parts[1]); // http(s)
			}

			array_splice($parts, count($parts) - 1, 0, [$_size]);
		
			return "https://" . implode("/", $parts);
		}
		
		public static function getStringBetween($string, $start, $end)
		{
			if(preg_match_all('/'.$start.'(.*?)'.$end.'/', $string, $match) > 0)
			{
				return array($match[0], $match[1]);
			}

			return false;
		}

		public static function isMultidimensionalArray(array $arr): bool
		{
			rsort($arr); 
			return isset($arr[0]) && is_array($arr[0]); 
		}
	}
?>