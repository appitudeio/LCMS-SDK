<?php
	/**
	 *	Timer
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2021-05--12
	 */
	namespace LCMS\Utils;

	use \Exception;

	class Timer
	{
		private static $timers = array();

		public static function set($_identifier)
		{
			if(isset(self::$timers[$_identifier]))
			{
				throw new Exception("Timer &quot;".$_identifier."&quot; already exists");
			}

			self::$timers[$_identifier] = (new TimerObject())->start();
		}

		public static function get($_identifier)
		{
			if(!isset(self::$timers[$_identifier]))
			{
				throw new Exception("Timer &quot;".$_identifier."&quot; does not exist");
			}

			return self::$timers[$_identifier];
		}
	}

	class TimerObject
	{
		private $format = "s";
		private $formats = array("s", "m", "h", "d"); // Seconds, Minutes, Hours, Days
		private $timestamp;
		private $timestamp_to = null;

		public function start()
		{
			$this->timestamp = microtime(true);

			return $this;
		}

		public function stop()
		{
			$this->timestamp_to = microtime(true);
		}

		public function pause()
		{

		}

		public function as($_format)
		{
			if(!in_array($_format, $this->formats))
			{
				throw new Exception("Format not allowed (".$_format."). Allowed: " . implode(", ", $this->formats));
			}

			$this->format = $_format;
		}

		function __toString()
		{
			if(empty($this->timestamp_to))
			{
				$this->stop();
			}
			
			if($this->format == "s")
			{
				return round($this->timestamp_to - $this->timestamp, 1);
			}
		}
	}
?>