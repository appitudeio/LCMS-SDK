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
	 *		2021-05-06: Added query->statement->fetchAs
	 *			- DB::query("SELECT...")->as(Model::class); returns array with Models
	 */
	namespace LCMS\Core;

	use LCMS\Utils\Uuid;
	use LCMS\Utils\Singleton;

	use \PDO;
	use \Exception;

	class Database
	{
		use Singleton;

		/* Keeps all current connections-instances-key */
		private static $connections = array();
		private static $sql;
		private static $time_zone	= "Europe/Stockholm";
		//private static $instance;

		public static function __callStatic($method, $args)
		{
			return call_user_func_array(array(self::getConnection(), $method), $args);
		}		

		/**
		 *	Connects to a DB, and returns the current created DB-instance-key
		 */
		public static function connect($host, $username, $password, $db_name = null, $ssl_certificate_path = null) //"/core/certificates/rds-ca-2019-root.pem")
		{
			$options = array(
				PDO::ATTR_PERSISTENT 	=> false, 
				PDO::ATTR_ERRMODE 		=> PDO::ERRMODE_EXCEPTION
			);

			if(!empty($ssl_certificate_path))
			{
				if(!is_file($ssl_certificate_path))
				{
					throw new Exception("Database ssl_certificate (".$ssl_certificate_path.") does not exist");
				}

				$options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_certificate_path;
			}			

			/* first iteration of connections == 0 */
			$key = count(self::$connections);

			$driver = "mysql:host=".$host."; charset=utf8mb4";

			try
			{
				$pdo = new PDO($driver, $username, $password, $options);
				$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
			    self::$connections[$key] = $pdo;
			}
			catch(PDOException $e) 
			{
			    self::debug($e);
			}
			catch(Exception $e)
			{
				throw new PDOException($e->getMessage());
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

		/*public static function getInstance()
		{
			if(self::$instance == null)
			{
				self::$instance = new Static();
			}

			return self::$instance;
		}

		public static function instance()
		{
			return self::getInstance();
		}*/

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

				if(!is_array($data) && strpos(strtolower($data ?? ""), "now") === 0)
				{
					$column_value = "NOW()";
				}
				elseif(!is_array($data) && strpos(strtolower($data ?? ""), "uuid") === 0)
				{
					preg_match('#\((.*?)\)#', $data, $match);
					$column_value = (in_array(strtolower($data ?? ""), ["uuid", "uuid()"])) ? "UUID_TO_BIN('".Uuid::generate()."')" : "UUID_TO_BIN('".$match[1]."')";
				}
				elseif(!is_array($data) && strpos(strtolower($data ?? ""), "point(") === 0)
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
					if(strpos(strtolower($value ?? ""), "point(") === 0)
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

				if(!is_array($data) && strpos(strtolower($data ?? ""), "now") === 0)
				{
					$column_value = "NOW()";
				}
				elseif(!is_array($data) && strpos(strtolower($data ?? ""), "uuid") === 0)
				{
					$column_value = (in_array(strtolower($data ?? ""), ["uuid", "uuid()"])) ? "UUID_TO_BIN('".Uuid::generate()."')" : "UUID_TO_BIN('".$match[1]."')";
				}
				elseif(!is_array($data) && strpos(strtolower($data ?? ""), "point(") === 0)
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
						if(strpos(strtolower($value ?? ""), $exclude) === 0)
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
		public static function query($sql, $args = array(), $connection_key = null)
		{
			$connection = self::getConnection($connection_key);

			self::$sql = $sql;

			if(empty($args))
			{
				try
				{
					return $connection->query($sql);
				}
				catch(PDOException $e)
				{
					self::debug($e);
				}
				catch(Exception $e)
				{
					self::debug($e);
				}
			}

			$args = (is_string($args)) ? array($args) : $args;

			foreach($args AS $k => $v)
			{
				if(!is_array($v))
				{
					continue;
				}

				$args[$k] = json_encode($v);
			}

			try
			{
				$statement = $connection->prepare(self::$sql);
				$statement->execute($args);
				return $statement;
			}
			catch(PDOException $e)
			{
				self::debug($e);
			}
			catch(Exception $e)
			{
				self::debug($e);
			}
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

			throw new PDOException("SQL-error: " . $e->getMessage() . " (" . self::$sql .") - (" . $string . ")");
		}

		/**
		 *	Find out which connection we should use. Could be from a Key
		 */
		public static function getConnection($connection_key = null)
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
	}

	class PDOStatement extends \PDOStatement
	{
		public function as(String $obj) : Array
		{
			$rows = $this->fetchAll(PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE);

			if(empty($rows))
			{
				return array();
			}

			return array_combine(array_keys($rows), array_map(fn($row, $id) => (new $obj(['id' => $id] + $row)), $rows, array_keys($rows)));
		}

		public function asArray() : Array
		{
			return $this->fetchAll(PDO::FETCH_ASSOC);
		}

		public function asKeyValue() : Array
		{
			return $this->fetchAll(PDO::FETCH_KEY_PAIR);
		}

		public function asColumn()
		{
			return $this->fetchColumn();
		}		
	}

	class PDOException extends Exception
	{
		
	}
?>
