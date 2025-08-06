<?php
	/**
	 *	Translator - Fetch language specific strings from .ini-files
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2019-03-14
	 * 		---- DECAPRECATED (2022-12-07) ---??
	 */
	namespace LCMS\Core;

	use LCMS\Util\Singleton;
	use LCMS\Util\Arr;

	use \Exception;

	class Translator
	{
		use Singleton;

		private array $translations = [];

		protected function init(string $translation_file_path)
		{
			if(!file_exists($translation_file_path)) 
			{
				throw new Exception("Unable to find the translation file for language $translation_file_path!");
			} 
			elseif(!$this->translations = parse_ini_file($translation_file_path, true))
			{
				throw new Exception("Could not parse ini-file: " . $translation_file_path);
			}
		}
		
		protected function getTranslations()
		{
			return $this->translations;
		}
		
		protected function get(string $_label, mixed $_data = null, string $_fallback_label = null)
		{
			if(empty($this->translations) && empty($_fallback_label))
			{
				throw new Exception("Translator language file not initialized");
			}

		 	if(!$message = $this->getMessage($_label)) // Backup
		 	{
		 		if(empty($_fallback_label))
		 		{
		 			throw new Exception("Translation &quot;" . $_label . "&quot; does not exist");
		 		}

		 		$message = $_fallback_label;
		 	}

			if(!empty($_data))
			{
				$placeholders = Arr::flatten($_data);

	 			return $this->replacePlaceholders($message, $placeholders);
	 		}

		 	return $message;
		}
		
		private function getMessage(string $_label)
		{
			list($section, $section_message) = explode(".", $_label, 2);

			if(!isset($this->translations[$section]) || !isset($this->translations[$section][$section_message]))
			{
				return false;
			}			
			
			return $this->translations[$section][$section_message];
		}
		
		/**
		 *	Replace {value}'s in the text string. 
		 *		First; Flatten the array so the multidimensional values are accessable
		 */
		protected function replacePlaceholders(string $_message, array $_placeholders)
		{
			foreach(array_filter($_placeholders) AS $key => $placeholder)
			{
				$_message = str_replace('{{' . $key . '}}', $placeholder, $_message);
			}
			
			return $_message;
		}
	}
?>