<?php
/**
 * @source https://kvz.io/blog/2009/06/10/create-short-ids-with-php-like-youtube-or-tinyurl/
 * @author  Kevin van Zonneveld <kevin@vanzonneveld.net>
 * @author  Simon Franz
 * @author  Deadfish
 * @author  SK83RJOSH
 * @copyright 2008 Kevin van Zonneveld (http://kevin.vanzonneveld.net)
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @version   SVN: Release: $Id: alphaID.inc.php 344 2009-06-10 17:43:59Z kevin $
 * @link    http://kevin.vanzonneveld.net/
 *
 * @param mixed   $in   String or long input to translate
 * @param boolean $to_num  Reverses translation when true
 * @param mixed   $pad_up  Number or boolean padds the result up to a specified length
 * @param string  $pass_key Supplying a password makes it harder to calculate the original ID
 *
 * @return mixed string or long
 */
  namespace LCMS\Util;

  class AlphaId
  {
    private static $index = "abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";

    public static function encode($string)
    {
      $out = "";

      for ($t = ($string != 0 ? floor(log($string, strlen(self::$index))) : 0); $t >= 0; $t--) 
      {
        $bcp = \bcpow(strlen(self::$index), $t);
        $a   = floor($string / $bcp) % strlen(self::$index);
        $out = $out . substr(self::$index, $a, 1);
        $string  = $string - ($a * $bcp);
      }

      return $out;
    }

    public static function decode($string)
    {
      $out = 0;

      $len = strlen($string) - 1;

      for ($t = $len; $t >= 0; $t--) 
      {
        $bcp = (int) \bcpow(strlen(self::$index), $len - $t);
        $out = $out + strpos(self::$index, substr($string, $t, 1)) * $bcp;
      }

      return $out;
    }

    // Although this function's purpose is to just make the
    // ID short - and not so much secure,
    // with this patch by Simon Franz (http://blog.snaky.org/)
    // you can optionally supply a password to make it harder
    // to calculate the corresponding numeric ID
    public static function lock($password)
    {
      for ($n = 0; $n < strlen($this->index); $n++) 
      {
        $i[] = substr(self::$index, $n, 1);
      }

      $pass_hash = hash('sha256',$password);
      $pass_hash = (strlen($pass_hash) < strlen(self::$index) ? hash('sha512', $password) : $pass_hash);

      for ($n = 0; $n < strlen(self::$index); $n++) 
      {
        $p[] =  substr($pass_hash, $n, 1);
      }

      array_multisort($p, SORT_DESC, $i);
      self::$index = implode($i);

      return $this;
    }
  }
?>