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
	}
?>