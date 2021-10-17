<?php
	/**
	 *	PSR-3 logger
	 * 
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2021-09-20 
	 * 
	 * 	ToDo: 
	 * 		- Support PHP 8.1 Enums---
	 * 		- getLastError - support last line of error log file and echo	
	 *
	 * 	//--
	 * 	Describes a logger instance.
	 *
	 * 	The message MUST be a string or object implementing __toString().
	 *
	 * 	The message MAY contain placeholders in the form: {foo} where foo
	 * 	will be replaced by the context data in key "foo".
	 *
	 * 	The context array can contain arbitrary data, the only assumption that
	 * 	can be made by implementors is that if an Exception instance is given
	 * 	to produce a stack trace, it MUST be in a key named "exception".
	 *
	 * 	See https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
	 * 	for the full interface specification.
	 */	
	namespace LCMS\Core;

	use LCMS\Core\Database as DB;
	use LCMS\Core\Env;
	use LCMS\Core\Request;
	use LCMS\Utils\Singleton;
	
	use \ReflectionClass;
	use \Exception;

	class Logger extends LogLevel
	{
		use Singleton;

		/**
		 * To (Database || file)
		 */
		private $to;

		/**
		 * 	Stores all LogLevels inherited from class LogLevel (At the bottom)
		 * 		- For validation when logging
		 */
		private $levels;
		public static $level;

		/**
		 * 	Tag a message with something (E.g a category of some sort) 
		 */
		private $tag;

		/**
		 * 
		 */
		private $context;

		/**
		 *
		 */
		private $user;

		/**
		 * 
		 */
		function __construct(string $context = null)
		{
			self::$instance = $this;

			$this->context($context);

			$this->levels = (new ReflectionClass(__CLASS__))->getConstants();
		}

		/**
		 * System is unusable.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function emergency($message, array $context = array()): Void
		{
			self::getInstance()->log(self::EMERGENCY, $message, $context);
		}

		/**
		 * Action must be taken immediately.
		 *
		 * Example: Entire website down, database unavailable, etc. This should
		 * trigger the SMS alerts and wake you up.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function alert($message, array $context = array()): Void
		{
			self::getInstance()->log(self::ALERT, $message, $context);
		}

		/**
		 * Critical conditions.
		 *
		 * Example: Application component unavailable, unexpected exception.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function critical($message, array $context = array()): Void
		{
			self::getInstance()->log(self::CRITICAL, $message, $context);
		}

		/**
		 * Runtime errors that do not require immediate action but should typically
		 * be logged and monitored.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function error($message, array $context = array()): Void
		{
			self::getInstance()->log(self::ERROR, $message, $context);
		}

		/**
		 * Exceptional occurrences that are not errors.
		 *
		 * Example: Use of deprecated APIs, poor use of an API, undesirable things
		 * that are not necessarily wrong.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function warning($message, array $context = array()): Void
		{
			self::getInstance()->log(self::WARNING, $message, $context);
		}

		/**
		 * Normal but significant events.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function notice($message, array $context = array()): Void
		{
			self::getInstance()->log(self::NOTICE, $message, $context);
		}

		/**
		 * Interesting events.
		 *
		 * Example: User logs in, SQL logs.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function info($message, array $context = array()): Void
		{
			self::getInstance()->log(self::INFO, $message, $context);
		}

		/**
		 * Detailed debug information.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function debug($message, array $context = array()): Void
		{
			self::getInstance()->log(self::DEBUG, $message, $context);
		}

		/**
		 * Logs with an arbitrary level.
		 *
		 * @param mixed $level
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function log($level, string|ErrorException $message, array $context = array()): Void
		{
			if(!in_array($level, self::getInstance()->levels))
			{
				self::getInstance()->error("Unsupported log level (".$level.") of " . implode(", ", self::getInstance()->levels));
				
				$level = self::ERROR;
			}

			self::getInstance()::$level = $level;

			if(self::getInstance()->to)
			{
				self::getInstance()->validateLogLocation();
			}

			$endpoint = [Request::fullUrl()];

			if(Request::getInstance()->headers->get("referer") && !in_array(Request::getInstance()->headers->get("referer"), $endpoint))
			{
				$endpoint[] = Request::getInstance()->headers->get("referer");
			}

        	$params = array(
        		'uuid'				=> "UUID()",
        		'context'			=> self::getInstance()->context ?? null,
        		'level'				=> $level,
        		'endpoint'			=> $endpoint,
				'created'			=> gmdate("Y-m-d H:i:s"),
				'data'				=> (!empty($context)) ? $context : null,
				'user_identifier'	=> self::getInstance()->user ?? null,
				'tag'				=> self::getInstance()->tag ?? null
        	);

			if($message instanceof ErrorException)
			{
				$params += array(
					'code'		=> $message->code,
					'message'	=> $message->message,
					'file'		=> $message->file,
					'line'		=> $message->line,
					'type'		=> $message->type
				);
			}
			else
			{
				$params += array(
					'message'	=> (!empty($params['data'])) ? self::interpolate($message, $params['data']) : $message,
				);
			}

			/**
			 * 	Build return message to output
			 */
			$error_message = self::interpolate("{date} {context}{level}: {message}", array(
        		'context' 	=> ($params['context']) ? " [" . $params['context'] . "] " : "",
        		'level' 	=> strtoupper($params['level']),
        		'date'		=> gmdate("Y-m-d H:i:s"),
        		'message'	=> match(true)
        		{
        			($message instanceof ErrorException && $message->type == "exception") => "Exception(".$params['code']."): " . $params['message'] . " in " . $params['file'] . ":" . $params['line'],
        			($message instanceof ErrorException && $message->type == "error") => "Error(".match((int) $params['code'])
        			{
        				E_ERROR 			=> "E_ERROR",
        				E_WARNING 			=> "E_WARNING",
        				E_PARSE 			=> "E_PARSE",
        				E_NOTICE 			=> "E_NOTICE",
        				E_CORE_ERROR 		=> "E_CORE_ERROR",
        				E_CORE_WARNING 		=> "E_CORE_WARNING",
        				E_COMPILE_ERROR 	=> "E_COMPILE_ERROR",
        				E_COMPILE_WARNING 	=> "E_COMPILE_WARNING",
        				E_USER_ERROR 		=> "E_USER_ERROR",
        				E_USER_WARNING 		=> "E_USER_WARNING",
        				E_USER_NOTICE 		=> "E_USER_NOTICE",
        				E_STRICT 			=> "E_STRICT",
        				E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
        				E_DEPRECATED 		=> "E_DEPRECATED",
        				E_USER_DEPRECATED 	=> "E_USER_DEPRECATED",
        				E_ALL 				=> "E_ALL",
        				default 			=> "UNKNOWN"
        			} . "): " . $params['message'] . " [File: " . $params['file'] . ", at line: " . $params['line'] . "]",
        			default => $params['message']
        		}
        	)) . PHP_EOL;

			// If 'to' still exists
			if(self::getInstance()->to instanceof Database)
			{
				self::getInstance()->to->insert(Env::get("db")['database'].".`lcms_log`", $params);
			}
			elseif(is_string(self::getInstance()->to))
			{
				file_put_contents(self::getInstance()->to, $error_message, FILE_APPEND);
			}

			if(ini_get("display_errors") && $message instanceof ErrorException)
			{
				echo $error_message;
			}
		}

		public static function getLastError()
		{
			if(self::getInstance()->to instanceof Database)
			{
				return self::getInstance()->to->query("SELECT *, BIN_TO_UUID(`uuid`) AS `uuid` FROM ".Env::get("db")['database'].".`lcms_log` ORDER BY `id` DESC LIMIT 1")->asArray()[0] ?? array();
			}
			elseif(is_string(self::getInstance()->to))
			{

			}
			else
			{

			}
		}

		private function validateLogLocation()
		{
			$dir = self::getInstance()->to;

			if($dir instanceof Database)
			{
				if(!DB::getInstance()->isConnected())
				{
					self::getInstance()->to(null);

					self::getInstance()->error("No database connection initialized");
				}
			}
			elseif(is_string($dir))
			{
				if(is_file($dir) && !is_writeable($dir))
				{
					self::getInstance()->to(null);

					self::getInstance()->error("LogDir " . $dir . " is not writeable");					
				}
				elseif(($file_ending = substr(strrchr($dir, "."), 1)) && $file_ending != "log")
				{
					self::getInstance()->to(null);

					self::getInstance()->error("LogDir " . $dir . " is not a .log -file");
				}
			}

			unset($dir);
		}

		public static function context(string $_context = null)
		{
			self::getInstance()->context = $_context;

			return self::getInstance();
		}

		public static function to(Database | string $_to = null): Self
		{
			self::getInstance()->to = $_to;

			return self::getInstance();
		}

		public static function tag(string $_tag): Self
		{
			self::getInstance()->tag = $_tag;

			return self::getInstance();
		}

		public static function user($_user): Self
		{
			self::getInstance()->user = $_user;

			return self::getInstance();
		}		

		/**
		 * Interpolates context values into the message placeholders.
		 */
		private static function interpolate($message, array $context = array())
		{
		    // build a replacement array with braces around the context keys
		    $replace = array();

		    foreach ($context AS $key => $val) 
		    {
		        // check that the value can be cast to string
		        if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) 
		        {
		            $replace['{' . $key . '}'] = $val;
		        }
		    }

		    // interpolate replacement values into the message and return
		    return strtr($message, $replace);
		}

		public static function ErrorHandler()
		{
			self::getInstance()->error(new ErrorException(func_get_args()));
		}

		public static function ExceptionHandler(\Exception | \Error $e)
		{
			self::getInstance()->error(new ErrorException($e));
		}
	}

	class LogLevel
	{
		const EMERGENCY = "emergency";
		const ALERT     = "alert";
		const CRITICAL  = "critical";
		const ERROR     = "error";
		const WARNING   = "warning";
		const NOTICE    = "notice";
		const INFO      = "info";
		const DEBUG     = "debug";		
	}

	class ErrorException
	{
		public $code;
		public $message;
		public $file;
		public $line;
		public $type;

		function __construct($error_exception)
		{
			if($error_exception instanceof Exception)
			{
				$this->type 	= "exception";
				$this->code 	= $error_exception->getCode();
				$this->message 	= $error_exception->getMessage();
				$this->file 	= $error_exception->getFile();
				$this->line 	= $error_exception->getLine();
			}
			elseif(is_array($error_exception))
			{
				$this->type 	= "error";
				$this->code 	= $error_exception[0];
				$this->message 	= $error_exception[1];
				$this->file 	= $error_exception[2];
				$this->line 	= $error_exception[3];
			}
			else
			{
				$this->type 	= "string";
				$this->message 	= $error_exception;
			}
		}
	}
?>