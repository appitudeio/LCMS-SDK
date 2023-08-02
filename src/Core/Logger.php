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
	use LCMS\Util\Singleton;
	
	use \ReflectionClass;
	use \Exception;
	use \Error;

	class Logger extends LogLevel
	{
		use Singleton {
			Singleton::__construct as private SingletonConstructor;
		}

		/**
		 * To (Database || file)
		 */
		private $to;

		/**
		 * 	Stores all LogLevels inherited from class LogLevel (At the bottom)
		 * 		- For validation when logging
		 */
		private $levels;
		public $level;

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

		private $request;

		/**
		 * 
		 */
		function __construct(Request $request)
		{
			$this->SingletonConstructor();

			self::getInstance()->request = $request;

			//self::getInstance()->context($_context);

			self::getInstance()->levels = (new ReflectionClass(__CLASS__))->getConstants();
		}

		/**
		 * System is unusable.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function emergency(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::EMERGENCY, $_message, $_context);
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
		public static function alert(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::ALERT, $_message, $_context);
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
		public static function critical(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::CRITICAL, $_message, $_context);
		}

		/**
		 * Runtime errors that do not require immediate action but should typically
		 * be logged and monitored.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function error(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::ERROR, $_message, $_context);
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
		public static function warning(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::WARNING, $_message, $_context);
		}

		/**
		 * Normal but significant events.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function notice(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::NOTICE, $_message, $_context);
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
		public static function info(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::INFO, $_message, $_context);
		}

		/**
		 * Detailed debug information.
		 *
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function debug(mixed $_message, array $_context = array()): void
		{
			self::getInstance()->log(self::DEBUG, $_message, $_context);
		}

		/**
		 * Logs with an arbitrary level.
		 *
		 * @param mixed $level
		 * @param string $message
		 * @param array $context
		 * @return void
		 */
		public static function log(string $_level, string | ErrorException $_message, array $_context = array()): void
		{
			if(!in_array($_level, self::getInstance()->levels))
			{
				self::getInstance()->error("Unsupported log level (".$_level.") of " . implode(", ", self::getInstance()->levels));
				
				$_level = self::ERROR;
			}

			self::getInstance()->level = $_level;

			if(self::getInstance()->to)
			{
				self::getInstance()->validateLogLocation();
			}

			$endpoint = [self::getInstance()->request::fullUrl()];

			if(self::getInstance()->request->headers->get("referer") && !in_array(self::getInstance()->request->headers->get("referer"), $endpoint))
			{
				$endpoint[] = self::getInstance()->request->headers->get("referer");
			}

        	$params = array(
        		'uuid'				=> "UUID()",
        		'context'			=> self::getInstance()->context ?? null,
        		'level'				=> $_level,
        		'endpoint'			=> $endpoint,
				'created'			=> gmdate("Y-m-d H:i:s"),
				'data'				=> (!empty($_context)) ? $_context : null,
				'user_identifier'	=> self::getInstance()->user ?? null,
				'tag'				=> self::getInstance()->tag ?? null
        	);

			if($_message instanceof ErrorException)
			{
				$params += array(
					'code'		=> $_message->code,
					'message'	=> $_message->message,
					'file'		=> $_message->file,
					'line'		=> $_message->line,
					'type'		=> $_message->type
				);
			}
			else
			{
				$params += array(
					'message'	=> (!empty($params['data'])) ? self::getInstance()->interpolate($_message, $params['data']) : $_message
				);
			}

			/**
			 * 	Build return message to output
			 */
			$error_message = self::getInstance()->interpolate("{date} {context}{level}: {message}", array(
        		'context' 	=> ($params['context']) ? " [" . $params['context'] . "] " : "",
        		'level' 	=> strtoupper($params['level']),
        		'date'		=> gmdate("Y-m-d H:i:s"),
        		'message'	=> match(true)
        		{
        			($_message instanceof ErrorException && $_message->type == "exception") => "Exception(".$params['code']."): " . $params['message'] . " in " . $params['file'] . ":" . $params['line'],
        			($_message instanceof ErrorException && $_message->type == "error") => "Error(".match((int) $params['code'])
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
			if(self::getInstance()->to instanceof Database || (is_object(self::getInstance()->to) && method_exists(self::getInstance()->to, "insert")))
			{
				self::getInstance()->to::insert(Env::get("db")['database'].".`lcms_log`", $params);
			}
			elseif(is_string(self::getInstance()->to))
			{
				file_put_contents(self::getInstance()->to, $error_message, FILE_APPEND);
			}

			if(ini_get("display_errors") && $_message instanceof ErrorException)
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

		public static function validateLogLocation()
		{
			$dir = self::getInstance()->to;

			if($dir instanceof Database)
			{
				if(!$dir::isConnected())
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

		public static function context(string $_context = null): self
		{
			self::getInstance()->context = $_context;

			return self::getInstance();
		}

		public static function to(mixed $_to = null): self
		{
			self::getInstance()->to = $_to;

			return self::getInstance();
		}

		public static function tag(string $_tag): self
		{
			self::getInstance()->tag = $_tag;

			return self::getInstance();
		}

		public static function user(mixed $_user): self
		{
			self::getInstance()->user = $_user;

			return self::getInstance();
		}		

		/**
		 * 	Interpolates context values into the message placeholders.
		 */
		private function interpolate($_message, array $_context = array())
		{
		    // build a replacement array with braces around the context keys
		    $replace = array();

		    foreach ($_context AS $key => $val) 
		    {
		        // check that the value can be cast to string
		        if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) 
		        {
		            $replace['{' . $key . '}'] = $val;
		        }
		    }

		    // interpolate replacement values into the message and return
		    return strtr($_message, $replace);
		}

		public static function ErrorHandler(): void
		{
			$args = func_get_args();
			$args = (isset($args[0]) && $args[0] instanceof Error) ? $args[0] : $args;

			self::getInstance()->error(new ErrorException($args));
		}

		public static function ExceptionHandler(\Exception | \Error $e): void
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
			if($error_exception instanceof Exception || $error_exception instanceof Error)
			{
				$this->type 	= ($error_exception instanceof Error) ? "error" : "exception";
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