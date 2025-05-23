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
	 *		2024-05-10: Added support for Enums
	 *		2024-08-31: Refactored with help from AI..
	 *		2025-01-19: Added support for auto-JSON-parsed columns
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
		protected function connect(
			string $_host, 
			string $_username, 
			mixed $_password, 
			string $_db_name = null, 
			string $_ssl_certificate_path = null
		): PDO 
		{
			$this->validateSSLPath($_ssl_certificate_path);

			$options = $this->getPDOOptions($_ssl_certificate_path);

			$pdo = $this->createPDOInstance($_host, $_username, $_password, $options);

			$key = $this->storeConnection($pdo);

			if ($_db_name !== null) 
			{
				$this->selectDBOrFail($_db_name, $key, $_host, $_username);
			}

			return $this->connections[$key];
		}

		/**
		 *	Smart insertQuery which allows an associative array with columns and values.
		 *		Also, ofcourse, with Binds and prepared statements
		 */
		protected function insert(string $_table, array $_fields, int $_connection_key = null): PDOStatement
		{
			$_table = $this->sanitizeIdentifier($_table);
			$columns_values = $this->prepareColumnsAndValues($_fields);
			$column_names = implode(", ", array_map(fn($col) => "`" . $this->sanitizeIdentifier($col) . "`", array_keys($_fields)));

			$this->sql = sprintf(
				"INSERT INTO %s (%s) VALUES(%s)",
				$_table,
				$column_names,
				implode(", ", $columns_values['placeholders'])
			);

			return $this->prepareStatement($this->sql, $columns_values['values'], $_connection_key);
		}

		/**
		 *	Smart updateQuery which updates a current row with an associative array
		 *		Just put in an array with key and values, as column => value
		 *			Specify a Where-statement through an array, like:
		 *				array('id' => $product_id);
		 */
		protected function update(string $_table, array $_fields, array $_where, int $_connection_key = null): PDOStatement
		{
			$_table = $this->sanitizeIdentifier($_table);

			// Remove the 'id' field to avoid altering the auto-increment column
			unset($_fields['id']);

			if(empty($_fields) || empty($_where)) 
			{
				throw new Exception("No Fields nor Where-statement specified for Update-query");
			}

			$columns_values = $this->prepareColumnsAndValues($_fields, true);
			$where_clause = $this->prepareWhereClause($_where);

			$this->sql = sprintf(
				"UPDATE %s SET %s WHERE %s",
				$_table,
				implode(", ", $columns_values['placeholders']),
				implode(" AND ", $where_clause)
			);

			return $this->prepareStatement($this->sql, array_merge($columns_values['values'], array_values($_where)), $_connection_key);
		}

		/* Actually run a query */
		protected function query(string $_sql, array | null $_args = null, int $_connection_key = null): PDOStatement
		{
			$this->sql = $_sql;

			return $this->prepareStatement($this->sql, $_args ?? [], $_connection_key);
		}

		/**
		 *	Gets columns from a table
		 */
		protected function getColumns(string $_database, string $_table): array
		{
			$query = $this->query("SHOW COLUMNS FROM `$_database`.`$_table`");

			if($this->num_rows($query) === 0) 
			{
				return [];
			}

			$columns = [];

			while ($row = $this->fetch_assoc($query)) 
			{
				$columns[$row['Field']] = $row['Type'];
			}

			return $columns;
		}

		protected function fetch_assoc($statement): mixed
		{
			return $statement->fetch(PDO::FETCH_ASSOC);
		}
		
		protected function num_rows($statement): int
		{
			return $statement->rowCount();
		}
		
		protected function last_insert_id(int $_connection_key = null): int
		{
			$connection = $this->getConnection($_connection_key);
			
			return $connection->lastInsertId();
		}

		protected function select_db(string $_db_name, int $_connection_key = null)
		{
			return $this->query("USE " . $_db_name); //, $_connection_key);
		}

		/* New way to fetch just one column instad of mysql_result */
		protected function fetch_column($statement)
		{
			return $statement->fetchColumn();
		}		

		/**
		 * Call on object destruction
		 * 		closes the connection to the database
		 */
		public function __destruct()
		{
			foreach($this->connections AS $key => $conn)
			{
				unset($this->connections[$key]);
			}
		}

		protected function disconnect($connection_key = 0): void
		{
			unset($this->connections[$connection_key]);
		}

		/**
		 * 	A smarter debugger for finding out where the erroring sql query were made in the code
		 */
		protected function debug($e): void
		{
			$full_trace = $e->getTrace();
			$most_interesting_frame = null;

			foreach ($full_trace AS $frame) 
			{
				if (!isset($frame['file']) || !isset($frame['class']) || in_array($frame['class'], [__CLASS__, 'PDOStatement'])) 
				{
					continue;
				}

				// The moment we find a frame that isn't in the database wrapper, that's what we want.
				$most_interesting_frame = $frame;
				break;
			}

			// Build a message
			$errorDetails = sprintf(
				"SQL-error: %s (SQL: %s)",
				$e->getMessage(),
				$this->sql
			);

			// If we found a user-land frame, append its info
			if ($most_interesting_frame) 
			{
				$file = $most_interesting_frame['file'];
				$line = $most_interesting_frame['line'] ?? '';
				$func = $most_interesting_frame['function'] ?? '';
				$cls  = $most_interesting_frame['class']    ?? '';

				$errorDetails .= sprintf(
					"\nOccurred in %s at line %s, in function %s of class %s",
					$file,
					$line,
					$func,
					$cls
				);
			} 
			else 
			{
				// We didn't find a frame outside the database code
				$errorDetails .= "\n(No frame outside Database found.)";
			}

    		throw new PDOException($errorDetails, (int)$e->getCode(), $e);
		}

		/**
		 *	Find out which connection we should use. Could be from a Key
		 */
		protected function getConnection(int $_connection_key = null): PDO
		{
			if(empty($this->connections))
			{
				throw new Exception("No connections found");
			}
			
			if(is_numeric($_connection_key))
			{
				if(!isset($this->connections[$_connection_key]))
				{
					throw new Exception("The connection (".$_connection_key.") doesnt exist");
				}
				
				return $this->connections[$_connection_key];
			}
			
			return $this->connections[0];
		}

		protected function isConnected(): bool
		{
			return (empty($this->connections)) ? false : true;
		}

		// Prepare the columns and values for INSERT/UPDATE statements
		private function prepareColumnsAndValues(array $fields, bool $forUpdate = false): array
		{
			$placeholders = [];
			$values = [];

			foreach ($fields as $column => $data) 
			{
				$column = $this->sanitizeIdentifier($column); // Sanitize column name

				$data = $this->processSpecialValues($data); // Process special values

				if ($data instanceof SqlExpression)
				{
					if ($forUpdate)
					{
						$placeholders[] = "`$column` = " . $data->getExpression();
					}
					else
					{
						$placeholders[] = $data->getExpression();
					}
					// Do not add to $values
				}
				elseif ($forUpdate && $data === null) 
				{
					$placeholders[] = "`$column` = NULL";
				} 
				else 
				{
					$placeholders[] = $forUpdate ? "`$column` = ?" : "?";
					$values[] = is_array($data) ? json_encode($data) : ($data instanceof \UnitEnum ? $data->value : $data);
				}
			}

			return ['placeholders' => $placeholders, 'values' => $values];
		}

		// Helper method to prepare the WHERE clause
		private function prepareWhereClause(array $_where): array
		{
			return array_map(function($column, $data) 
			{
				$column = $this->sanitizeIdentifier($column);
				
				if (is_null($data))
				{
					return "`$column` IS NULL";
				} 
				elseif (is_array($data)) 
				{
					return "`$column` " . $data[0] . " ?";
				} 
				
				return "`$column` = ?";
			}, array_keys($_where), $_where);
		}

		private function prepareStatement(string $sql, array $args = [], int $connection_key = null): PDOStatement
		{
			$connection = $this->getConnection($connection_key);

			$statement = $connection->prepare($sql);

			array_walk($args, fn(&$v) => $v = is_array($v) ? json_encode($v) : ($v instanceof \UnitEnum ? $v->value : $v));

			try 
			{
				$statement->execute($args);
			} 
			catch (PDOException | Exception $e) 
			{
				$this->debug($e);
			}

			return $statement;
		}

		protected function escape(string $_string, int $_connection_key = null): string | array
		{
			$connection = $this->getConnection($_connection_key);

			if(is_array($_string))
			{
				return array_map(fn($value) => substr($connection->quote($value), 1, -1), $_string);
			}
				
			return substr($connection->quote($_string), 1, -1);
		}

		private function validateSSLPath(?string $_ssl_certificate_path): void
		{
			if ($_ssl_certificate_path !== null && !is_file($_ssl_certificate_path)) 
			{
				throw new Exception("Database ssl_certificate ({$_ssl_certificate_path}) does not exist");
			}
		}

		private function getPDOOptions(?string $_ssl_certificate_path): array
		{
			$options = [
				PDO::ATTR_PERSISTENT => false,
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
			];

			if ($_ssl_certificate_path !== null) {
				$options[PDO::MYSQL_ATTR_SSL_CA] = $_ssl_certificate_path;
			}

			return $options;
		}

		private function createPDOInstance(string $_host, string $_username, string | int $_password, array $options): PDO
		{
			$driver = "mysql:host={$_host}; charset=utf8mb4";

			try 
			{
				$pdo = new PDO($driver, $_username, $_password, $options);
				$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ PDOStatement::class ]);
			} 
			catch (PDOException $e) 
			{
				$this->debug($e);
			} 
			catch (Exception $e) 
			{
				throw new PDOException($e->getMessage());
			}

			return $pdo;
		}

		private function storeConnection(PDO $pdo): int
		{
			$key = count($this->connections);
			$this->connections[$key] = $pdo;
			return $key;
		}

		private function selectDBOrFail(string $_db_name, int $key, string $_host, string $_username): void
		{
			if (!$this->select_db($_db_name, $key)) 
			{
				throw new Exception("Could not select database {$_db_name} ({$_host}, {$_username})");
			}
		}

		private function processSpecialValues(mixed $data): mixed
		{
			if(!is_string($data))
			{
				return $data; // Return the value unchanged if it doesn't match any special cases
			}
			
			$lower_data = strtolower($data);

			if(str_starts_with($lower_data, "now(")) 
			{
				return new SqlExpression("NOW()");
			} 
			elseif(str_starts_with($lower_data, "uuid(")) 
			{
				preg_match('#\((.*?)\)#', $data, $match);
				$uuid = ($match[1] ?? '') === '' ? Uuid::generate() : $match[1];

				return new SqlExpression("UUID_TO_BIN('" . $uuid . "')");
			}
			elseif(str_starts_with($lower_data, "point(")) 
			{
				return new SqlExpression($data); // No processing needed for POINT()
			}

			return $data; 
		}

		// Simple sanitization to prevent injection via table/column names
		private function sanitizeIdentifier(string $identifier): string
		{
			// Allow letters, numbers, underscores, and dots
			if (preg_match('/^[a-zA-Z0-9_.`]+$/', $identifier) !== 1) 
			{
				throw new Exception("Invalid identifier: " . $identifier);
			}

			return $identifier;
		}
	}

	class PDOStatement extends \PDOStatement
	{
		private ?array $column_meta_cache = null;

		public function as(string $_obj, string $_method = null): array
		{
			$rows = $this->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

			if(empty($rows))
			{
				return array();
			}
			elseif(!empty($_method))
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

		/**
		 * Override fetch() so *any* row-by-row fetching decodes JSON.
		 */
		public function fetch(int $mode = PDO::FETCH_ASSOC, ...$args): mixed
		{
			$row = parent::fetch($mode, ...$args);
			if ($row === false || $row === null) 
			{
				return $row;
			}

			/** 
			 * 	Only decode if it's an associative array (FETCH_ASSOC)
			 * 	If the mode is something else (like FETCH_OBJ), you need
			 *	a slightly different approach
			 */
			if ($mode === \PDO::FETCH_ASSOC || $mode === (\PDO::FETCH_ASSOC|\PDO::FETCH_UNIQUE)) 
			{
				$this->initColumnMetaCache();
				$this->decodeJsonColumns($row);
			}

			return $row;
		}

		/**
		 *  Override fetchAll() so *batch fetching* also decodes JSON.
		 */
		public function fetchAll($mode = \PDO::FETCH_ASSOC, ...$args): array
		{
			$rows = parent::fetchAll($mode, ...$args);
			if (empty($rows)) 
			{
				return $rows;
			}
			
			// If returning associative arrays, decode them
			if ($mode === \PDO::FETCH_ASSOC || $mode === (\PDO::FETCH_ASSOC|\PDO::FETCH_UNIQUE)) 
			{
				$this->initColumnMetaCache();

				foreach ($rows AS &$row) 
				{
					$this->decodeJsonColumns($row);
				}
			}

			return $rows;
		}

		/**
		 * 	Given an associative array row, JSON-decode any columns
		 * 	marked as JSON in the metadata cache.
		 */
		private function decodeJsonColumns(array &$row): void
		{
			if(empty($this->column_meta_cache)) 
			{
				return;
			}

			// For each column that we know is JSON, decode it
			foreach($this->column_meta_cache AS $colName => $meta) 
			{
				// Confirm that this column actually exists in the row
				if(empty($row[$colName]) || !array_key_exists($colName, $row)) 
				{
					continue;
				}
				elseif(!$this->isMaybeJsonColumn($meta)) 
				{
					continue;
				}
				elseif(!in_array($row[$colName][0], ['{', '[']))
				{
					continue;
				}

				// Since the native json_validate under the hood packages the JSON-decode function, we shouldnt do it twice
				try
				{
					$row[$colName] = json_decode($row[$colName], true, 512, JSON_THROW_ON_ERROR);
				}
				catch(Exception $e) {}
			}
		}

		/**
		 * 	Initialize (if needed) the column metadata cache,
		 * 	which will tell us which columns are JSON.
		 */
		private function initColumnMetaCache(): void
		{
			if($this->column_meta_cache !== null) 
			{
				return; // Already cached
			}

			$this->column_meta_cache = [];
			$column_count = $this->columnCount();

			// Loop through each column index
			for($i = 0; $i < $column_count; $i++) 
			{
				$meta = $this->getColumnMeta($i);

				// Key by column name, store the whole meta array
				$this->column_meta_cache[$meta['name']] = $meta;
			}
		}

		private function isMaybeJsonColumn(array $meta): bool
		{
			// Some drivers use 'mysql:decl_type', others 'native_type' => 'JSON'
			$declType = $meta['mysql:decl_type'] ?? null;
			$nativeType = $meta['native_type'] ?? null;

			if($declType === 'JSON' || $nativeType === 'JSON') 
			{
				return true;
			}
			elseif(!empty($declType) || !empty($nativeType)) 
			{
				return false;
			}
			elseif($meta['pdo_type'] != PDO::PARAM_STR) 
			{
				return false;	
			}
			elseif(empty($meta['flags']) || !in_array("blob", $meta['flags'])) 
			{
				return false;
			}

			return true;
		}
	}

	class PDOException extends Exception
	{
		
	}

	class SqlExpression
	{
		private string $expression;

		public function __construct(string $expression)
		{
			$this->expression = $expression;
		}

		public function getExpression(): string
		{
			return $this->expression;
		}
	}
?>