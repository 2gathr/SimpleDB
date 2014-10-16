<?php
	require_once 'NestedTraPDO.class.php';
	
	class SimplePDOException extends Exception {}
	
	class SimplePDO {
		private $db, $host, $dbName, $lastQuery = array ();
		private static $pdoTypes = array (
			'b' => PDO::PARAM_BOOL,
			'i' => PDO::PARAM_INT,
			's' => PDO::PARAM_STR,
			'n' => PDO::PARAM_NULL
			);
		private static $pdoOptions = array (
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'
			);
		const REGEX_PLACEHOLDER = <<<'EOT'
/^((?:(?:'(?:\\'|\\[^'\\])?(?:[^'\\]|[^'\\]\\[^'\\]|(?:[^'\\]|\\\\)\\'|\\\\)*'|"(?:\\"|\\[^"\\])?(?:[^"\\]|[^"\\]\\[^"\\]|(?:[^"\\]|\\\\)\\"|\\\\)*")|[^?"'])*\?){
EOT;
		
		public function __construct ($host, $db, $user, $pw, $sqlMode = 'TRADITIONAL,STRICT_ALL_TABLES,NO_ZERO_IN_DATE') {
			try {
				$this -> host = $host;
				$this -> dbName = $db;
				$this -> db = new NestedTraPDO (
					'mysql:host='.$host.';dbname='.$db,
					$user,
					$pw,
					self::$pdoOptions
					);
				$this -> db -> exec ('SET SESSION sql_mode = "'.$sqlMode.'"');
			} catch (PDOException $e) {
				throw $e;
			}
		}
		
		public function query () {
			try {
				$this -> lastQuery = array ();
				$this -> lastQuery['arguments'] = $arg = func_get_args ();
				if (count ($arg) == 0 || count ($arg) == 2) {
					throw new DbException ('bad argument number', 100);
				}
				$query = trim ($arg[0]);
				$queryType = strtolower (preg_split ('/\\s/', $query, 2)[0]);
				$this -> lastQuery['type'] = strtoupper ($queryType);
				switch (count ($arg)) {
					case 1:
						switch ($queryType) {
							case 'select': case 'show':
								$ret = $this -> db -> query ($query) -> fetchAll ();
								break;
							case 'insert':
								$this -> db -> exec ($query);
								$ret = $this -> db -> lastInsertId ();
								break;
							default:
								$ret = $this -> db -> exec ($query);
						}	
						break;
					default:
						$this -> lastQuery['phCount'] = $phCount = self::getPlaceholderCount ($query);
						if (!is_string ($arg[1])) {
							throw new SimplePDOException ('placeholder types is not a string', 101);
						}
						if ($phCount != strlen ($arg[1])) {
							throw new SimplePDOException ('placeholder types count doesn\'t match placeholder count', 102);
						}
						if (is_array ($arg[2])) {
							if (count ($arg) != 3) throw new SimplePDOException ('array for values given but too many arguments', 103);
							$phVal = array ();
							foreach ($arg[2] as $key => $val) {
								$phVal[] = $val;
							}
						} else $phVal = array_slice ($arg, 2);
						$this -> lastQuery['phValues'] = $phVal;
						if ($phCount != count ($phVal)) {
							throw new SimplePDOException ('placeholder values count doesn\'t match placeholder count', 104);
						}
						$stmt = $this -> db -> prepare ($query);
						for ($i = 0; $i < count ($phVal); $i ++) {
							$phType = substr ($arg[1], $i, 1);
							if (!isset (self::$pdoTypes[$phType])) {
								throw new SimplePDOException ('unknown type of value: "'.$type.'"', 105);
							}
							$stmt -> bindValue ($i + 1, $phVal[$i], self::$pdoTypes[$phType]);
						}
						$ret = $stmt -> execute ();
						switch ($queryType) {
							case 'select': case 'show':
								$ret = $stmt -> fetchAll ();
								break;
							case 'insert':
								$ret = $this -> db -> lastInsertId ();
						}
				}
				return $ret;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function beginTra () {
			try {
				return $this -> db -> beginTransaction ();
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function commitTra () {
			try {
				return $this -> db -> commit ();
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function rollbackTra () {
			try {
				return $this -> db -> rollBack ();
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function getDbName () {
			return $this -> dbName;
		}
		
		public function getHost () {
			return $this -> host;
		}
		
		public function getLastQuery () {
			if (empty ($this -> lastQuery)) {
				throw new SimplePDOException ('no query executed', 106);
			}
			return $this -> lastQuery;
		}
		
		public function activeTra () {
			return $this -> db -> inTransaction ();
		}
		
		protected static function getPlaceholderCount ($string) {
			for ($phCount = 0; preg_match (self::REGEX_PLACEHOLDER.($phCount + 1).'}/', $string) == 1; $phCount++);
			return $phCount;
		}
	}
?>
