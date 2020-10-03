<?php
	/**
	 *	Crypt functionality (Encode, Decode strings back and forth)
	 *
	 *	Inspired by: https://stackoverflow.com/questions/16600708/how-do-you-encrypt-and-decrypt-a-php-string
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2019-04-16
	 */
	namespace LCMS\Utils;

	use \Exception;
	use \RangeException;

	class Crypt
	{
		protected static $key = "FE43ZBHSQHYKX0M5GS1X4C0PCTGGBJA2";

		/**
		 * Encrypt a message
		 * 
		 * @param string $message - message to encrypt
		 * @param string $key - encryption key
		 * @return string
		 * @throws RangeException
		 */
		public static function encrypt($string, $key = null)
		{
			$key = (!empty($key)) ? $key : self::$key;

			if (mb_strlen($key, '8bit') !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) 
			{
				throw new RangeException('Key is not the correct size (must be 32 bytes).');
			}

			$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

			$cipher = base64_encode(
				$nonce . 
				sodium_crypto_secretbox(
					$string,
					$nonce,
					$key
				)
			);

			sodium_memzero($string);
			sodium_memzero($key);

			return $cipher;			
		}

		/**
		 * Decrypt a message
		 * 
		 * @param string $encrypted - message encrypted with safeEncrypt()
		 * @param string $key - encryption key
		 * @return string
		 * @throws Exception
		 */
		public static function decrypt($string, $key = null)
		{
			$key = (!empty($key)) ? $key : self::$key;

		    $decoded 	= base64_decode($string);
		    $nonce 		= mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
		    $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

		    $plain = sodium_crypto_secretbox_open(
		        $ciphertext,
		        $nonce,
		        $key
		    );

		    if (!is_string($plain)) 
		    {
		        throw new Exception('Invalid MAC');
		    }

		    sodium_memzero($ciphertext);
		    sodium_memzero($key);

		    return $plain;
		}
	}
?>