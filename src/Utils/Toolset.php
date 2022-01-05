<?php
	namespace LCMS\Utils;

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

		public static function picture($_url, $_attributes = null, $_size = null)
		{
			$_size = (!empty($size) && is_string($_size)) ? explode("x", $_size) : null;
			$parts = explode(".", $_url);
			$file_ending = $parts[count($parts) - 1];
			unset($parts[count($parts) - 1]);
	
			$attributes = "loading='lazy'";
	
			if(!empty($_attributes))
			{
				$attributes = array_map(fn($k) => $k."='".$_attributes[$k]."'", array_keys($_attributes));
				$attributes = " " . implode(" ", $attributes);
			}
	
			$mime = ($file_ending == "jpg") ? "jpeg" : $file_ending;
	
			$html = "<picture>";
	
			if($file_ending == "webp")
			{
				$html .= "<source srcset='".$_url."' type='image/webp'>";
				$html .= "<source srcset='".self::imgTo($_url, $_size, "png")."' type='image/png'>";
				$html .= "<img src='".self::imgTo($_url, $_size, "png")."' ".$attributes."/>";
			}
			else
			{
				$html .= "<source srcset='".self::imgTo($_url, $_size)."' type='image/webp'>";
				$html .= "<source srcset='".$_url."' type='image/".$mime."'>";
				$html .= "<img src='".$_url."' ".$attributes."/>";
			}
	
			return $html . "</picture>";
		}

		public static function imgTo($_url, $_size, $_file_ending = "webp")
		{
			$image_url = implode(".", explode(".", $_url, -1)) . "." . $_file_ending;
	
			if(!empty($_size))
			{
				return self::resize($image_url, $_size);
			}
	
			return $image_url;
		}
	
		public static function resize($_url, $_size)
		{
			$parts = explode("/", $_url);
			$parts[count($parts) - 2] = $_size;
			return implode("/", $parts);
		}		
	}
?>