<?php
	/**
	 *
	 */
	namespace LCMS\Core;

	use LCMS\Core\Request;
	use LCMS\Util\Singleton;

	class Locale
	{
		use Singleton;

		private $languages = array(); // Available languages
		private $language = "en";
		private $is_default = false;

		public function setLanguages(array $_languages): self
		{
			$this->languages = $_languages;

			if(false === $this->isDefault() && in_array($this->language, $this->languages))
			{
				$this->is_default = true;
			}

			return $this;
		}
		
		public function setLanguage(string $_language, bool $_is_default = false)
		{
			$this->language = $_language;
			$this->is_default = $_is_default;
		}

		/**
		 *	Parse Locale from URL
		 */
		public function extract(Request $request): string | bool
		{
			if(!$test_language = (count($request->segments()) > 0 && strlen($request->segments()[0]) == 2) ? strtolower($request->segments()[0]) : false)
			{
				return false;
			}
			elseif(!in_array($test_language, $this->languages))
			{
				return false;
			}
			elseif($test_language != $this->language && $this->isDefault() === true)
			{
				$this->is_default = false;
			}

			$this->language = $test_language;

			return $this->language;
		}

		public static function getLanguage(): string
		{
			return self::getInstance()->language;
		}

		public static function isDefault(): bool
		{
			return self::getInstance()->is_default;
		}
	}
?>