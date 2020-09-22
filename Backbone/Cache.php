<?php
	namespace LCMS\Backbone;

	use LCMS\Core\Request;
	use \Exception;

	class Cache
	{
		use \LCMS\Utils\Singleton;

		private static $cache_file_ext = ".html";
		private static $file_storage = false;

		public static function findFile(Request $_request, $_callback)
		{
			$strict_filename = ($_request->path() !== "/") ? $_request->path() : "startpage";
			$root_filename = $strict_filename;

			$url_parameters = $_request->query->all();
			unset($url_parameters[$strict_filename]);

			if(!empty($url_parameters))
			{
				foreach($url_parameters AS $key => $value)
				{
					$strict_filename .= "+" . hyphenize($key) . "-" . hyphenize($value);
				}
			}

			return $_callback($strict_filename . self::$cache_file_ext, $root_filename . self::$cache_file_ext, $url_parameters);
		}

		public static function setFile($to_file)
		{
			if(!is_dir(dirname($to_file)))
			{
				throw new Exception("Cache folder doesnt exist " . $to_file);
			}
			elseif(!is_writable(dirname($to_file)))
			{
				throw new Exception("Cache folder isnt writeable " . $to_file);
			}

			self::$file_storage = $to_file;
		}

		public static function getFileCached($_root, Request $_request)
		{
			if(!self::$file_cache_enabled)
			{
				return null;
			}


		}

		public static function storeToFile($_html) : String
		{
			if(!self::$file_storage)
			{
				return $_html;
			}

			$fp = fopen(self::$file_storage, 'w'); 
			
			// save the contents of output buffer to the file
			fwrite($fp, $_html);
			
			// close the file
			fclose($fp);

			return $_html;  
		}
	}

	function hyphenize($string) 
	{
	    return strtolower(
	        preg_replace(
	          array( '#[\\s-]+#', '#[^A-Za-z0-9. -]+#' ),
	          array( '-', '' ),
	          // the full cleanString() can be downloaded from http://www.unexpectedit.com/php/php-clean-string-of-utf8-chars-convert-to-similar-ascii-char
	          cleanString(urldecode($string))));
	             /* str_replace( // preg_replace can be used to support more complicated replacements
	                  array_keys($dict),
	                  array_values($dict),
	                  urldecode($string)*/
	}

	function cleanString($text) {
	    $utf8 = array(
	        '/[áàâãªä]/u'   =>   'a',
	        '/[ÁÀÂÃÄ]/u'    =>   'A',
	        '/[ÍÌÎÏ]/u'     =>   'I',
	        '/[íìîï]/u'     =>   'i',
	        '/[éèêë]/u'     =>   'e',
	        '/[ÉÈÊË]/u'     =>   'E',
	        '/[óòôõºö]/u'   =>   'o',
	        '/[ÓÒÔÕÖ]/u'    =>   'O',
	        '/[úùûü]/u'     =>   'u',
	        '/[ÚÙÛÜ]/u'     =>   'U',
	        '/ç/'           =>   'c',
	        '/Ç/'           =>   'C',
	        '/ñ/'           =>   'n',
	        '/Ñ/'           =>   'N',
	        '/–/'           =>   '-', // UTF-8 hyphen to "normal" hyphen
	        '/[’‘‹›‚]/u'    =>   ' ', // Literally a single quote
	        '/[“”«»„]/u'    =>   ' ', // Double quote
	        '/ /'           =>   ' ', // nonbreaking space (equiv. to 0x160)
	    );
	    return preg_replace(array_keys($utf8), array_values($utf8), $text);
	}	
?>