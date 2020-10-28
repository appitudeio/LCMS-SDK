<?php
	/**
	 *	Database PDO-wrapper
	 *
	 * 	@author     Mathias Eklöf <mathias@appitude.io>
	 *	@created 	2018-06-01
	 *
	 *	Changelog:
	 *		2019-11-20: Added `getColumns` which returns an array of columns for given database and table
	 *		2019-11-21: Added support for UUID and NOW in  statement bindings
	 *		2020-01-06: Added SSL certificate support
	 */
	namespace LCMS\Core;

	use \PDO;
	use \Exception;

	class Database
	{
		/* Keeps all current connections-instances-key */
		private static $connections = array();

		/* Used in debugging */
		private static $sql;

		private static $time_zone	= "Europe/Stockholm";

		private static $instance;

		/**
		 *	Connects to a DB, and returns the current created DB-instance-key
		 */
		public static function connect($host, $username, $password, $db_name = null, $ssl_certificate_path = "/core/certificates/rds-ca-2019-root.pem")
		{
			$options = array(
				PDO::ATTR_PERSISTENT 	=> false, 
				PDO::ATTR_ERRMODE 		=> PDO::ERRMODE_EXCEPTION
			);

			if(!empty($ssl_certificate_path))
			{
				$options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_certificate_path;
			}			

			/* first iteration of connections == 0 */
			$key = count(self::$connections);

			$driver = "mysql:host=".$host."; charset=utf8mb4";

			try
			{
			    self::$connections[$key] = new PDO($driver, $username, $password, $options);
			} 
			catch(PDOException $e) 
			{
			    self::debug($e);
			}

			if(is_null($db_name))
			{
				return self::$connections[$key];
			}

			$db_select = self::select_db($db_name, $key);

			if(!$db_select)
			{
				throw new Exception('Could not select database ' . $db_name . " (".$host.", ".$username.")");
			}
			
			return self::$connections[$key];
		}

		public static function getInstance()
		{
			if(self::$instance == null)
			{
				self::$instance = new Static();
			}

			return self::$instance;
		}

		/**
		 *	Smart insertQuery which allows an associative array with columns and values.
		 *		Also, ofcourse, with Binds and prepared statements
		 */
		public static function insert($table, $fields, $connection_key = null)
		{
			$connection = self::getConnection($connection_key);

			/**
			 *	Prepare the bindings
			 */
			$columns_values = array();

			foreach($fields AS $column => $data)
			{
				$column_value = ":" . $column;

				if(!is_array($data) && strpos(strtolower($data), "now") === 0)
				{
					$column_value = "NOW()";
				}
				elseif(!is_array($data) && strpos(strtolower($data), "uuid") === 0)
				{
					$column_value = "UUID_TO_BIN(UUID())";
				}
				elseif(!is_array($data) && strpos(strtolower($data), "point(") === 0)
				{
					$column_value = $data;	
				}

                $columns_values[] = $column_value;
			}

			self::$sql = "INSERT INTO " . $table . " (" . "`" . implode("`, `", array_keys($fields)) . "`" . ") VALUES(" . implode(", ", $columns_values) . ")";

			$statement = $connection->prepare(self::$sql);

			try
			{
				foreach(self::bind($fields) AS list($column, $value, $param))
				{
					if(strpos(strtolower($value), "point(") === 0)
					{
						continue;
					}

					$statement->bindValue(":" . $column, $value, $param);
				}

				$statement->execute();
			}
			catch(PDOException $e)
			{
				self::debug($e);
			}

			return $statement;
		}

		/**
		 *	Smart updateQuery which updates a current row with an associative array
		 *		Just put in an array with key and values, as column => value
		 *			Specify a Where-statement through an array, like:
		 *				array('id' => $product_id);
		 */
		public static function update($table, $fields, $where, $connection_key = null)
		{
			if(empty($fields) || empty($where))
			{
				trigger_error("No Fields nor Where-statement specified for Update-query", E_USER_ERROR);
				return false;
			}

			$connection 	= self::getConnection($connection_key);

			/**
			 *	Prepare the bindings
			 */
			$columns_values = array();

			foreach($fields AS $column => $data)
			{
				if($column == "id")
				{
					continue;
				}

				$column_value = ":" . $column;

				if(!is_array($data) && strpos(strtolower($data), "now") === 0)
				{
					$column_value = "NOW()";
				}
				elseif(!is_array($data) && strpos(strtolower($data), "uuid") === 0)
				{
					$column_value = "UUID_TO_BIN(UUID())";
				}
				elseif(!is_array($data) && strpos(strtolower($data), "point(") === 0)
				{
					$column_value = $data;	
				}
				
                $columns_values[] = "`" . $column . "` = " . $column_value;
			}

			$where_statement = array();

			foreach($where AS $column => $data)
			{
				$where_data = "`" . $column . "`";

				if(is_null($data))
				{
					$where_data .= " IS NULL";
				}
				elseif(is_numeric($data))
				{
					$where_data .= "=".$data;
				}
				elseif(is_array($data))
				{
					$where_data .= $data[0] . $data[1];
				}
				else
				{
					$where_data .= "='".$data."'";
				}

				$where_statement[] = $where_data;
			}

			self::$sql = "UPDATE ".$table." SET " . implode(", ", $columns_values) . " WHERE " . implode(" AND ", $where_statement);

			$statement = $connection->prepare(self::$sql);

			try
			{
				foreach(self::bind($fields) AS list($column, $value, $param))
				{
					$statement->bindValue(":" . $column, $value, $param);
				}

				$statement->execute();
			}
			catch(PDOException $e)
			{
				self::debug($e);
			}

			return $statement;
		}

		private static function bind($_fields)
		{
			$return = array();

			$excludes = array('now', 'uuid', 'point(');

			foreach($_fields AS $column => $value)
			{
				// If NOW or UUID, then we've already covered this when we prepared the sql
				if(!is_array($value))
				{
					foreach($excludes AS $exclude)
					{
						if(strpos(strtolower($value), $exclude) === 0)
						{
							continue 2;
						}
					}
				}

				if(is_int($value))
				{
					$param = PDO::PARAM_INT;
				}
				elseif(is_bool($value))
				{
					$param = PDO::PARAM_BOOL;
				}
				elseif(is_null($value))
				{
					$param = PDO::PARAM_NULL;
				}
				else
				{
					$param = PDO::PARAM_STR;
				}

				if(is_array($value))
				{
					$value = json_encode($value);
				}

				$return[] = array($column, $value, $param);
	        }

	        return $return;
		}

		/* Actually run a query */
		public static function query($sql, $connection_key = null)
		{
			$connection = self::getConnection($connection_key);

			self::$sql = $sql;

			$statement 	= $connection->prepare(self::$sql);

			try
			{
				$statement->execute();
			}
			catch(PDOException $e)
			{
				self::debug($e);
			}

			return $statement;
		}

		/**
		 *	Gets columns from a table
		 */
		public static function getColumns($database, $table)
		{
			$query = self::query("SHOW COLUMNS FROM ".$database.".`".$table."`");

			if(self::num_rows($query) == 0)
			{
				return false;
			}

			$columns = array();

			while($row = self::fetch_assoc($query))
			{
				$columns[$row['Field']] = $row['Type'];
			}

			return $columns;
		}

		public static function fetch_assoc($statement)
		{
			return $statement->fetch(PDO::FETCH_ASSOC);
		}
		
		public static function num_rows($statement)
		{
			return $statement->rowCount();
		}
		
		public static function last_insert_id($connection_key = null)
		{
			$connection = self::getConnection($connection_key);
			
			return $connection->lastInsertId();
		}

		public static function select_db($db_name, $connection_key = null)
		{
			return self::query("USE " . $db_name, $connection_key);
		}

		/* New way to fetch just one column instad of mysql_result */
		public static function fetch_column($statement)
		{
			return $statement->fetchColumn();
		}		

		/**
		 * Call on object destruction
		 * 		closes the connection to the database
		 */
		public function __destruct()
		{
			foreach(self::$connections AS $key => $conn)
			{
				unset(self::$connections[$key]);
			}
			
			return true;
		}

		public static function disconnect($connection_key = 0)
		{
			unset(self::$connections[$connection_key]);

			return true;
		}

		public static function debug($e)
		{
			$trace = $e->getTrace()[1];

			$string = "file: " . $trace['file'] . ", line: " . $trace['line'] . ", function: " . $trace['function'] . ", class: " . $trace['class'];

			throw new Exception("SQL-error: " . $e->getMessage() . " (" . self::$sql .") - (" . $string . ")");
		}

		/**
		 *	Find out which connection we should use. Could be from a Key
		 */
		private static function getConnection($connection_key = null)
		{
			if(empty(self::$connections))
			{
				throw new Exception("No connections found");
			}
			
			if(is_numeric($connection_key))
			{
				if(!isset(self::$connections[$connection_key]))
				{
					throw new Exception("The connection (".$connection_key.") doesnt exist");
				}
				
				return self::$connections[$connection_key];
			}
			elseif(is_null($connection_key))
			{
				/* Return the first connected DB */
				return self::$connections[0]; //count(self::$connections) - 1];
			}
			
			return $connection;
		}

		public function isConnected()
		{
			return (empty(self::$connections)) ? false : true;
		}

		public static function escape($string, $connection_key = null)
		{
			$connection = self::getConnection($connection_key);

			if(is_array($string))
			{
				foreach($string AS $key => $value)
				{
					$string[$key] = substr($connection->quote($value), 1, -1);
				}
			}
			else
			{
				$string = substr($connection->quote($string), 1, -1);
			}

			return $string;
		}

		public static function UUID()
		{
		    if (function_exists('com_create_guid') === true)
		    {
		        return trim(com_create_guid(), '{}');
		    }

		    return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));			
		}
	}
?>