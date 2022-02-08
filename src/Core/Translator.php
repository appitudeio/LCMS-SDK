<?php
	/**
	 *	Translator - Fetch language specific strings from .ini-files
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2019-03-14
	 */
	namespace LCMS\Core;

	use \Exception;

	class Translator
	{
		private static $translations = false;

		public static function init($translation_file_path = null)
		{
			if(empty($translation_file_path) && empty($_parsed_data))
			{
				throw new Exception("Translation_file_path AND parsed_data cant be null");
			}

			if(!empty($translation_file_path))
			{
				if(!file_exists($translation_file_path)) 
				{
					throw new Exception("Unable to find the translation file for language $translation_file_path!");
				} 

				self::$translations = parse_ini_file($translation_file_path, true);

				if(!self::$translations)
				{
					throw new Exception("Could not parse ini-file: " . $translation_file_path);
				}
			}
		}
		
		public static function getTranslations()
		{
			return self::$translations;
		}
		
		public static function get($_label, $_data = null, $_fallback_label = null)
		{
			if(!self::$translations && empty($_fallback_label))
			{
				throw new Exception("Translator language file not initialized");
			}

		 	$message = self::getMessage($_label);

		 	if(!$message) // Backup
		 	{
		 		if(empty($_fallback_label))
		 		{
		 			throw new Exception("Translation &quot;" . $_label . "&quot; does not exist");
		 		}

		 		$message = $_fallback_label;
		 	}

			if(!empty($_data))
			{
				$placeholders = self::array_flatten($_data);

	 			return self::replacePlaceholders($message, $placeholders);
	 		}

		 	return $message;
		}
		
		private static function getMessage($label)
		{
			list($section, $section_message) = explode(".", $label);

			if(!isset(self::$translations[$section]) || !isset(self::$translations[$section][$section_message]))
			{
				return false;
			}			
			
			return self::$translations[$section][$section_message];
		}
		
		/**
		 *	Replace {value}'s in the text string. 
		 *		First; Flatten the array so the multidimensional values are accessable
		 */
		public static function replacePlaceholders($message, $placeholders)
		{
			foreach(array_filter($placeholders) AS $key => $placeholder)
			{
				$message = str_replace('{{' . $key . '}}', $placeholder, $message);
			}
			
			return $message;
		}

		public static function array_flatten($arr, $narr = array(), $nkey = '')
		{
    		foreach ($arr AS $key => $value) 
    		{
        		if (is_array($value)) 
        		{
            		$narr = array_merge($narr, self::array_flatten($value, $narr, $nkey . $key . '.'));
        		} 
        		else 
        		{
            		$narr[$nkey . $key] = $value;
            	}
        	}
        	
        	return $narr;
        }
	}
?>