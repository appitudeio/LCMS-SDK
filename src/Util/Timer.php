<?php
	/**
	 *	Timer
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2021-05--12
	 */
	namespace LCMS\Util;

	use \Exception;

	class Timer
	{
		private static array $timers = array();

		public static function set($_identifier): void
		{
			if(isset(self::$timers[$_identifier]))
			{
				throw new Exception("Timer &quot;".$_identifier."&quot; already exists");
			}

			self::$timers[$_identifier] = (new TimerObject())->start();
		}

		public static function get($_identifier): TimerObject
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
		private $format = "ms";
		private $formats = array("ms", "s", "m", "h", "d"); // Milliseconds, Seconds, Minutes, Hours, Days
		private $timestamp;
		private $timestamp_to = null;

		public function start(): self
		{
			$this->timestamp = microtime(true);

			return $this;
		}

		public function stop(): void
		{
			$this->timestamp_to = microtime(true);
		}

		public function pause(): void
		{

		}

		public function as(string $_format): void
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

			return match($this->format)
			{
				'ms' => $this->timestamp_to - $this->timestamp,
				's' => round($this->timestamp_to - $this->timestamp, 1),
				default => round($this->timestamp_to - $this->timestamp) / 1000
			};
		}
	}
?>