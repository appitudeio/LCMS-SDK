<?php
	/**
	 *	Salt String Generator
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2019-11-11	 
	 */
	namespace LCMS\Utils;

	use \Exception;

	class Salt
	{
		/**
		 *	Unique for the system, used to hash passwords back and forth
		 */
		const SALT = "Wh33y pr0t3inpwder n0t g00d?";

		/**
		 *	Generates a random string, in given length
		 */
		public static function generate($_length = 15, $_salt = "", $_only_lowercase = false)
		{	
			$character_list = ($_only_lowercase) ? "abcdefghijklmnopqrstuvwxyz" : "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ01234567890";
			
			for($i = 1; $i <= $_length; $i++)
			{
				$_salt .= $character_list[mt_rand(0, strlen($character_list) - 1)];
			}
			
			return $_salt;
		}

		/**
		 *	Prepare a string for protection, e.g database password
		 */
		public static function protect($_string, $_salt)
		{
			return sha1(sha1($_salt . $_string . self::SALT));
		}
	}
?>