<?php
	namespace LCMS\Util;

	class Toolset
	{
		public static function picture(string $_url, array $_attributes = array(), string | int $_size = null): string
		{
			$parts = self::pictureArray($_url, $_attributes, $_size);

			return self::pictureArrayToString($parts);
		}

		public static function pictureArray(string $_url, array $_attributes = array(), string | int $_size = null): array
		{
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

		public static function pictureArrayToString(array $picture_data): string
		{
			$picture_parts = array_map(fn($picture) => 
				"<" . $picture[0] . ((isset($picture[1]) && !empty($picture[1])) 
					? " " . implode(" ", array_map(fn($key) => $key . '="' . $picture[1][$key] . '"', array_keys($picture[1]))) 
					: "") . ">", $picture_data);

			return "<picture>" . implode("", $picture_parts) . "</picture>";

		}

		public static function imgTo(string $_url, mixed $_size = null, string $_file_ending = "webp"): string
		{
			$image_url = implode(".", explode(".", $_url, -1)) . "." . $_file_ending;
	
			if(!empty($_size))
			{
				return self::resize($image_url, $_size);
			}
	
			return $image_url;
		}
	
		public static function resize(string $_url, mixed $_size = null): string
		{
			if(empty($_size))
			{
				return $_url;
			}

			$parsed_url = parse_url($_url);
			$parts = explode("/", $_url);

			if($parts[1] == "")
			{
				unset($parts[0], $parts[1]); // http(s)
			}

			array_splice($parts, count($parts) - 1, 0, [$_size]);
		
			return (isset($parsed_url['scheme'])) ? $parsed_url['scheme'] . "://" . implode("/", $parts) : implode("/", $parts);
		}
		
		public static function getStringBetween(string $_string, string $_start, string $_end): mixed
		{
			if(preg_match_all('/'.$_start.'(.*?)'.$_end.'/', $_string, $match) > 0)
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