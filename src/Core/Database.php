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

	use LCMS\Util\Uuid;
	use LCMS\Util\Singleton;

	use \PDO;
	use \Exception;

	class Database
	{
		use Singleton;

		private $connections = array();
		private $sql;
		private $time_zone	= "Europe/Stockholm";

		/**
		 *	Connects to a DB, and returns the current created DB-instance-key
		 */
		public static function connect(string $_host, string $_username, mixed $_password, string $_db_name = null, string $_ssl_certificate_path = null): PDO
		{
			$options = array(
				PDO::ATTR_PERSISTENT 	=> false, 
				PDO::ATTR_ERRMODE 		=> PDO::ERRMODE_EXCEPTION
			);

			if(!empty($_ssl_certificate_path))
			{
				if(!is_file($_ssl_certificate_path))
				{
					throw new Exception("Database ssl_certificate (".$_ssl_certificate_path.") does not exist");
				}

				$options[PDO::MYSQL_ATTR_SSL_CA] = $_ssl_certificate_path;
			}			

			/* first iteration of connections == 0 */
			$key = count(self::getInstance()->connections);

			$driver = "mysql:host=".$_host."; charset=utf8mb4";

			try
			{
				$pdo = new PDO($driver, $_username, $_password, $options);
				$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);

			    self::getInstance()->connections[$key] = $pdo;
			}
			catch(PDOException $e) 
			{
			    self::getInstance()->debug($e);
			}
			catch(Exception $e)
			{
				throw new PDOException($e->getMessage());
			}

			if(is_null($_db_name))
			{
				return self::getInstance()->connections[$key];
			}

			if(!self::getInstance()->select_db($_db_name, $key))
			{
				throw new Exception('Could not select database ' . $_db_name . " (".$_host.", ".$_username.")");
			}
			
			return self::getInstance()->connections[$key];
		}

		/**
		 *	Smart insertQuery which allows an associative array with columns and values.
		 *		Also, ofcourse, with Binds and prepared statements
		 */
		public static function insert(string $_table, array $_fields, int $_connection_key = null): PDOStatement
		{
			$connection = self::getInstance()->getConnection($_connection_key);

			/**
			 *	Prepare the bindings
			 */
			foreach($_fields AS &$data)
			{
				if(is_string($data) && str_starts_with(strtolower($data ?? ""), "now("))
				{
					$data = "NOW()";
				}
				elseif(is_string($data) && str_starts_with(strtolower($data ?? ""), "uuid("))
				{
					preg_match('#\((.*?)\)#', $data, $match);
					$data = (strtolower($data ?? "") == "uuid()") ? "UUID_TO_BIN('".Uuid::generate()."')" : "UUID_TO_BIN('".$match[1]."')";
				}
				elseif(is_string($data) && str_starts_with(strtolower($data ?? ""), "point("))
				{
					$data = $data;	
				}
				else
				{
					$data = "?";
				}
			}

			// array => json
			$all_fields = array_values($_fields);
			array_walk($all_fields, fn(&$v) => (is_array($v)) ? $v = json_encode($v) : $v = $v); // Convert all arrays to json

			self::getInstance()->sql = "INSERT INTO " . $_table . " (" . "`" . implode("`, `", array_keys($_fields)) . "`" . ") VALUES(" . implode(", ", $all_fields) . ")";

			$statement = $connection->prepare(self::getInstance()->sql, $all_fields);

			try
			{
				$statement->execute($all_fields);
			}
			catch(PDOException $e)
			{
				self::getInstance()->debug($e);
			}

			return $statement;
		}

		/**
		 *	Smart updateQuery which updates a current row with an associative array
		 *		Just put in an array with key and values, as column => value
		 *			Specify a Where-statement through an array, like:
		 *				array('id' => $product_id);
		 */
		public static function update(string $_table, array $_fields, array $_where, int $_connection_key = null): PDOStatement
		{
			unset($_fields['id']); // Never alter 'id' (Auto-increment column)

			if(empty($_fields) || empty($_where))
			{
				throw new Exception("No Fields nor Where-statement specified for Update-query");
			}

			$connection = self::getInstance()->getConnection($_connection_key);

			/**
			 *	Prepare the bindings
			 */
			$columns_values = $where_statement = array();

			foreach($_fields AS $column => &$data)
			{
				$as = "?";

				if(is_null($data))
				{
					$as = "NULL";
					$data = null;
				}
				elseif(is_string($data) && str_starts_with(strtolower($data ?? ""), "now"))
				{
					$as = "NOW()";
					$data = null;
				}
				elseif(is_string($data) && str_starts_with(strtolower($data ?? ""), "uuid("))
				{
					preg_match('#\((.*?)\)#', $data, $match);
					$as = (strtolower($data ?? "") == "uuid") ? "UUID_TO_BIN('".Uuid::generate()."')" : "UUID_TO_BIN('".$match[1]."')";
					$data = null;
				}
				elseif(is_string($data) && str_starts_with(strtolower($data ?? ""), "point("))
				{
					$data = $data;	
				}

                $columns_values[] = "`" . $column . "` = " . $as;
			}

			foreach($_where AS $column => &$data)
			{
				if(is_null($data))
				{
					$data = " IS NULL";
				}
				elseif(is_array($data))
				{
					$data = $data[0] . $data[1];
				}

				$where_statement[] = "`" . $column . "` = ?";
			}

			// array => json
			$all_fields = array_merge(array_filter(array_values($_fields)), array_values($_where));
			array_walk($all_fields, fn(&$v) => (is_array($v)) ? $v = json_encode($v) : $v = $v); // Convert all arrays to json

			self::getInstance()->sql = "UPDATE ".$_table." SET " . implode(", ", $columns_values) . " WHERE " . implode(" AND ", $where_statement);

			$statement = $connection->prepare(self::getInstance()->sql);

			try
			{
				$statement->execute($all_fields);
			}
			catch(PDOException $e)
			{
				self::getInstance()->debug($e);
			}

			return $statement;
		}

		/* Actually run a query */
		public static function query(string $_sql, array | null $_args = null, int $_connection_key = null): PDOStatement
		{
			$connection = self::getInstance()->getConnection($_connection_key);

			self::getInstance()->sql = $_sql;

			if(empty($_args))
			{
				try
				{
					return $connection->query(self::getInstance()->sql);
				}
				catch(PDOException $e)
				{
					self::getInstance()->debug($e);
				}
				catch(Exception $e)
				{
					self::getInstance()->debug($e);
				}
			}

			$args = (is_string($_args)) ? array($_args) : $_args;
			array_walk($args, fn(&$v) => (is_array($v)) ? $v = json_encode($v) : $v = $v); // Convert all arrays to json

			try
			{
				$statement = $connection->prepare(self::getInstance()->sql);
				$statement->execute($args);
			}
			catch(PDOException $e)
			{
				self::getInstance()->debug($e);
			}
			catch(Exception $e)
			{
				self::getInstance()->debug($e);
			}

			return $statement;
		}

		/**
		 *	Gets columns from a table
		 */
		public static function getColumns(string $_database, string $_table): array
		{
			$query = self::getInstance()->query("SHOW COLUMNS FROM ".$_database.".`".$_table."`");

			if(self::getInstance()->num_rows($query) == 0)
			{
				return array();
			}

			$columns = array();

			while($row = self::getInstance()->fetch_assoc($query))
			{
				$columns[$row['Field']] = $row['Type'];
			}

			return $columns;
		}

		public static function fetch_assoc($statement): mixed
		{
			return $statement->fetch(PDO::FETCH_ASSOC);
		}
		
		public static function num_rows($statement): int
		{
			return $statement->rowCount();
		}
		
		public static function last_insert_id(int $_connection_key = null): int
		{
			$connection = self::getInstance()->getConnection($_connection_key);
			
			return $connection->lastInsertId();
		}

		public static function select_db(string $_db_name, int $_connection_key = null)
		{
			return self::getInstance()->query("USE " . $_db_name, $_connection_key);
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
			foreach(self::getInstance()->connections AS $key => $conn)
			{
				unset(self::getInstance()->connections[$key]);
			}
		}

		public static function disconnect($connection_key = 0): void
		{
			unset(self::getInstance()->connections[$connection_key]);
		}

		private static function debug($e)
		{
			$trace = $e->getTrace()[1];

			$string = "file: " . $trace['file'] . ", line: " . $trace['line'] . ", function: " . $trace['function'] . ", class: " . $trace['class'];

			throw new PDOException("SQL-error: " . $e->getMessage() . " (" . self::getInstance()->sql .") - (" . $string . ")");
		}

		/**
		 *	Find out which connection we should use. Could be from a Key
		 */
		public static function getConnection(int $_connection_key = null): PDO
		{
			if(empty(self::getInstance()->connections))
			{
				throw new Exception("No connections found");
			}
			
			if(is_numeric($_connection_key))
			{
				if(!isset(self::getInstance()->connections[$_connection_key]))
				{
					throw new Exception("The connection (".$_connection_key.") doesnt exist");
				}
				
				return self::getInstance()->connections[$_connection_key];
			}
			elseif(is_null($_connection_key))
			{
				/* Return the first connected DB */
				return self::getInstance()->connections[0];
			}
			
			throw new Exception("No counntions found (last");
		}

		public static function isConnected(): bool
		{
			return (empty(self::getInstance()->connections)) ? false : true;
		}

		public static function escape(string $_string, int $_connection_key = null)
		{
			$connection = self::getInstance()->getConnection($_connection_key);

			if(is_array($_string))
			{
				foreach($_string AS $key => $value)
				{
					$_string[$key] = substr($connection->quote($value), 1, -1);
				}
			}
			else
			{
				$_string = substr($connection->quote($_string), 1, -1);
			}

			return $_string;
		}
	}

	class PDOStatement extends \PDOStatement
	{
		public function as(string $_obj, string $_method = null): array
		{
			$rows = $this->fetchAll(PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE);

			if(empty($rows))
			{
				return array();
			}

			if(!empty($_method))
			{
				return array_combine(array_keys($rows), array_map(fn($row, $id) => $_obj::$_method(['id' => $id] + $row), $rows, array_keys($rows)));
			}

			return array_combine(array_keys($rows), array_map(fn($row, $id) => new $_obj(['id' => $id] + $row), $rows, array_keys($rows)));
		}

		public function asArray(): array
		{
			return $this->fetchAll(PDO::FETCH_ASSOC);
		}

		public function asKeyValue(): array
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