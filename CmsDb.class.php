<?php
	require_once 'SimpleDb.class.php';
	require_once 'CmsDbResult.class.php';
		
	class CmsDbException extends SimpleDbException {}
	
	class CmsDb extends SimpleDb {
		const STATE_DEFAULT = 1;
		const STATE_DELETED = 2;
		
		const KEY_UNIQUE = 1; //checks for unique values (among all current and not yet confirmed ones)
		const KEY_NEW = 2; //checks if all values (no matter if confirmed, unconfirmed or undone) are unique for each ID (usefuel e.g. for passwords to prevent users from reusing old ones)
		const KEY_DISTINCT = 4; //checks for unique values (among all values ever saved)
		
		const REGEX_VALUES = <<<'EOT'
('(\\'|\\[^'\\])?([^'\\]|[^'\\]\\[^'\\]|([^'\\]|\\\\)\\'|\\\\)*'|"(\\"|\\[^"\\])?([^"\\]|[^"\\]\\[^"\\]|([^"\\]|\\\\)\\"|\\\\)*"|(\d*\.)?\d+|\(((?R)|[^()'"])+\)|\?)
EOT;
		//table and column names cannot be longer than 64 characters (http://dev.mysql.com/doc/refman/5.7/en/identifiers.html)
		//because, in the case of CmsDb, the column name is appended to the table name with an underscore between both,
		//plus there is appended a "_view"/"_change",
		//table and column names can only be 28 characters long
		const REGEX_TABLE_NAME = <<<'EOT'
/^\w{1,28}(?<!\w_(id|key|state|key_col|\w+_view))$/i
EOT;
		const REGEX_COLUMN_NAME = <<<'EOT'
/^(\w|(?!28)\w{2}|(?!key)\w{3}|\w{4}|(?!state)\w{5}|\w{6}|(?!key_col)\w{7}\w{8,29})(?<!_view)$/
EOT;
		
		private $tables;
		private $lastQuery = array (); //for debugging purposes
		
		public function __construct ($host, $db, $user, $pw) {
			try {
				parent::__construct ($host, $db, $user, $pw);
				$this -> getTables ();
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function getTables () {
			try {
				$result = parent::query (
					'SELECT
						name
					FROM
						CmsDb_table'
				);
				$tables = array ();
				foreach ($result as $row) {
					$tables[] = $row['name'];
				}
				$this -> tables = implode ('|', $tables);
				return $tables;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function query () {
			try {
				$arg = func_get_args ();
				$this -> lastQuery = array ('funcArgs' => $arg);
				switch (func_num_args ()) {
					case 0:
					case 2:
						throw new CmsDbException ('bad argument number', 100);
					case 1:
						$phTypes = '';
						$phValues = array ();
						break;
					default:
						$phTypes = func_get_arg (1);
						if (is_array (func_get_arg (2))) {
							$phValues = func_get_arg (2);
						} else {
							$phValues = array_slice (func_get_args (), 2);
						}
				}
				$passedQuery = func_get_arg (0);
				$activeTra = true;
				if (preg_match ('/^\\s*INSERT\\s+INTO\\s+(?<table>'.$this -> tables.')\\s*\\((?<keys>.*)\\)\\s*VALUES\\s*\\((?<values>.*)\\)\\s*;?\\s*$/is', $passedQuery, $matches)) {
					//TO DO: default values, multiple inserts at once
					//table name in $matches['table']
					//keys to update in $matches['keys']
					//values to insert in $matches['values']
					$this -> lastQuery['extractedQueryType'] = 'INSERT';
					$this -> lastQuery['extractedTableName'] = $matches['table'];
					parent::beginTra ();
					$ret = $this -> insert ($matches['table'], $matches['keys'], $matches['values'], $phTypes, $phValues);
				} elseif (preg_match ('/^\\s*UPDATE\\s+(?<table>'.$this -> tables.')\\s+SET\\s+(?<set>\\w+\\s*=\\s*'.self::REGEX_VALUES.'(\\s*,\\s*\\w+\\s*=\\s*'.self::REGEX_VALUES.')*)\\s*(?<where>WHERE\\s+.*)?\\s*;?\\s*$/is', $passedQuery, $matches)) { //TO DO: POSSIBLE CHANGE instead of .* use (\w\s=\sREGEX_VALUES,\s)+
					//table name in $matches['table']
					//SET statements in $matches['set']
					//WHERE statements, if existent, in $matches['where']
					$this -> lastQuery['extractedQueryType'] = 'UPDATE';
					$this -> lastQuery['extractedTableName'] = $matches['table'];
					if (!isset ($matches['where'])) $matches['where'] = '';
					parent::beginTra ();
					$ret = $this -> update ($matches['table'], $matches['set'], $matches['where'], $phTypes, $phValues);
				} elseif (preg_match ('/^\\s*DELETE\\s+FROM\\s+(?<table>'.$this -> tables.')\\s+(?<where>WHERE\\s+.*)?\\s*;?\\s*$/is', $passedQuery, $matches)) {
					//table name in $matches['table']
					//WHERE statements, if existent, in $matches['where']
					$this -> lastQuery['extractedQueryType'] = 'DELETE';
					$this -> lastQuery['extractedTableName'] = $matches['table'];
					if (!isset ($matches['where'])) $matches['where'] = '';
					parent::beginTra ();
					$ret = $this -> delete ($matches['table'], $matches['where'], $phTypes, $phValues);
				} else { //SELECT query or query not concerning CmsDb tables
					$this -> lastQuery['executedQuery'] = $passedQuery;
					$activeTra = false;
					$ret = parent::query ($passedQuery, $phTypes, $phValues);
					$this -> lastQuery['SimpleDb'] = parent::getLastQuery ();
					return $ret;
				}
				parent::commitTra ();
				return $ret;
			} catch (Exception $e) {
				if ($activeTra) {
					try {
						parent::rollbackTra ();
					} catch (Exception $e) {
						throw $e;
					}
				}
				throw $e;
			}
		}
		
		private function insert ($tableName, $keyStmt, $valueStmt, $phTypes = '', array $phValues = array ()) {
			try {
				preg_match_all ('/\w+/', $keyStmt, $k);
				$keys = $k[0];
				preg_match_all ('/'.self::REGEX_VALUES.'/', $valueStmt, $v);
				$values = $v[0];
				$this -> lastQuery['extractedKeys'] = $keys;
				$this -> lastQuery['extractedValues'] = $values;
				if (count ($keys) != count ($values)) {
					throw new CmsDbException ('SQL Syntax Error: unequal number of keys and values', 151);
				}
				$insertId = parent::query (
					'SELECT
						MAX('.$tableName.'_id) + 1 as insert_idd
					FROM
						'.$tableName.'_state'
				);
				$insertId = (int) $insertId[0]['insert_id'];
				if ($insertId === NULL) {
					$insertId = 1;
				}
				$tokens = $this -> createTokens ($tableName);
				$query = '';
				for ($i = 0; $i < count ($keys); $i++) {
					$query .=
						'INSERT INTO '.$tableName.'_'.$keys[$i].'
							('.$tableName.'_id, '.$keys[$i].', change_id)
						VALUES
							('.$insertId.', '.$values[$i].', '.$tokens['change_id'].');';
				}
				$columns = $this -> getColumns ($tableName);
				foreach ($columns as $col) { //inserts default values
					if (!in_array ($col, $keys)) {
						$q =
							'INSERT INTO '.$tableName.'_'.$col.'
								('.$tableName.'_id, change_id)
							VALUES
								('.$insertId.', '.$tokens['changeId'].');';
						$this -> lastQuery['defaultValueQueries'][] = $q;
						parent::query ($q);
					}
				}
				$this -> lastQuery['executedQuery'] = $query;
				$result = parent::query ($query, $phTypes, $phValues);
				$this -> lastQuery['SimpleDb'] = parent::getLastQuery ();
				$this -> checkKeys ($tableName); //you can't use $keys as updatedColumns because the default values are updated as well
				return new CmsDbResult ($this, 'INSERT', $insertId, $tokens);
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		private function update ($tableName, $setStmt, $whereStmt = '', $phTypes = '', array $phValues = array ()) {
			try {
				$ids = $this -> getIds ($tableName, $whereStmt, $phTypes, $phValues, -1);
				if (count ($ids) == 0) {
					return 0; //0 rows affected
				}
				preg_match_all ('/(\w+)\\s*=\\s*('.self::REGEX_VALUES.')/', $setStmt, $set, PREG_SET_ORDER);
				//makes "$set[i][1] = $set[i][2]"
				$tokens = $this -> createTokens ($tableName);
				$query = '';
				$updatedColumns = arary ();
				foreach ($set as $s) {
					$updatedColumns[] = $s[1];
					$values = '';
					foreach ($ids as $id) {
						$values .= '('.$id.', '.$s[2].', '.$tokens['changeId'].'), ';
					}
					$values = substr ($values, 0, -2);
					$query .=
						'INSERT INTO '.$tableName.'_'.$s[1].'
							('.$tableName.'_id, '.$s[1].', change_id)
						VALUES
							'.$values.'; ';
				}
				$this -> lastQuery['executedQuery'] = $query;
				$phCount = parent::getPlaceholderCount ($whereStmt);
				$result = parent::query ($query, substr ($phTypes, 0, -$phCount), array_slice ($phValues, 0, -$phCount));
				$this -> lastQuery['SimpleDb'] = parent::getLastQuery ();
				$this -> checkKeys ($tableName, $updatedColumns);
				return new CmsDbResult ($this, 'UPDATE', count ($ids), $tokens);
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		private function delete ($tableName, $whereStmt = '', $phTypes = '', array $phValues = array ()) {
			try {
				return $this -> update ($tableName, 'state = '.self::STATE_DELETED, $whereStmt, $phTypes, $phValues);
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		private function getIds ($tableName, $whereStmt, $phTypes, array $phValues, $phOffset = 0) { //offset>=0 -> von offset an, offset<0 -> endet abs(offset) + 1 vor Ende
			try {
				$query =
					'SELECT
						id
					FROM
						'.$tableName.'
					'.$whereStmt;
				$phCount = parent::getPlaceholderCount ($whereStmt);
				if ($phCount) { //if there are placeholders
					if ($phOffset < 0) {
						$phOffset = 1 + $phOffset - $phCount;
					}
					$ids = parent::query ($query, substr ($phTypes, $phOffset, $phCount), array_slice ($phValues, $phOffset, $phCount));
				} else {
					$ids = parent::query (
						'SELECT
							id
						FROM
							'.$tableName
					);
				}
				$ret = array ();
				if ($ids) { //if $ids isn't empty
					foreach ($ids as $id) {
						$ret[] = $id['id'];
					}
				}
				return $ret;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function checkKeys ($tableName, array $updatedColumns = NULL) {
			try {
				if (isset ($updatedColumns)) {
					$keys = parent::query (
						'SELECT
							k.*
						FROM
							'.$tableName.'_key k
						LEFT JOIN
							'.$tableName.'_key_col c
						WHERE c.col IN(\''.implode ('\', \'', $updatedColumns).'\')'
					);
				} else {
					$keys = parent::query (
						'SELECT
							*
						FROM
							'.$tableName.'_key'
					);
				}
				$this -> lastQuery['checkedKeys'] = $keys;
				foreach ($keys as $key) {
					$result = parent::query (
						'SELECT
							col
						FROM
							'.$tableName.'_key_col
						WHERE key_id = ?',
						'i',
						$key['id']
					);
					$columns = array ();
					foreach ($result as $row) {
						$columns[] = $row['col'];
					}
					if ($key['types'] & self::KEY_UNIQUE) {
						$join = '';
						$where = '';
						$i = 1;
						foreach ($columns as $col) {
							$join .=' RIGHT JOIN
								'.$tableName.'_'.$col.' t'.$i.' ON t0.'.$tableName.'_id = t'.$i.'.'.$tableName.'_id';
							$where .=
								' AND t'.$i.'.id IN (
									SELECT
										MAX(a.id)
									FROM
										'.$tableName.'_'.$col.' a
									LEFT JOIN
										CmsDb_change b ON a.change_id = b.id
									LEFT JOIN
										CmsDb_autoconfirm c ON c.table_name = \''.$table_name.'_'.$col.'\'
									WHERE ISNULL(b.undone) AND (b.confirmed IS NOT NULL OR c.table_name IS NOT NULL)
									GROUP BY a.'.$tableName.'_id
									UNION ALL
										SELECT
											a.id
										FROM
											'.$tableName.'_'.$col.' a
										LEFT JOIN
											CmsDb_change b ON a.change_id = b.id
										WHERE ISNULL(b.confirmed)
								)';
							$i++;
						}
						$where = 'WHERE'.substr ($where, 4);
						//TODO handle NULL values like normal UNIQUE keys
						$result = parent::query (
							'SELECT
								COUNT(DISTINCT '.implode (', ', $columns).') as individual, COUNT(*) as combined
							FROM
								'.$tableName.'_state t0
							'.$join.'
							'.$where
						);
						if (count ($result) != 1) {
							throw new CmsDbException ('Internal Error: multiple rows returned for COUNT without GROUP BY statement', 152);
						}
						if ($result[0]['individual'] != $result[0]['combined']) {
							throw new CmsDbException ('Key Violation: KEY_UNIQUE for columns '.implode (', ', $columns), 153);
						}
					}
					if ($key['types'] & self::KEY_NEW) {
						if (count ($columns) != 1) {
							throw new CmsDbException ('Database Inconsistency: multiple columns for KEY_NEW', 154);
						}
						$result = parent::query (
							'SELECT
								COUNT(DISTINCT '.$columns[0].') as individual, COUNT(*) as combined
							FROM
								'.$tableName.'_'.$columns[0].'
							GROUP BY '.$tableName.'_id'
						);
						foreach ($result as $row) {
							if ($row['individual'] != $row['combined']) {
								throw new CmsDbException ('Key Violation: KEY_NEW for column '.$columns[0], 153);
							}
						}
					}
					if ($key['types'] & self::KEY_DISTINCT) {
						if (count ($columns) != 1) {
							throw new CmsDbException ('Database Inconsistency: multiple columns for KEY_DISTINCT', 154);
						}
						$result = parent::query (
							'SELECT
								COUNT(DISTINCT '.$columns[0].') as individual, COUNT(*) as combined
							FROM
								'.$tableName.'_'.$columns[0]
						);
						if (count ($result) != 1) {
							throw new CmsDbException ('Internal Error: multiple rows returned for COUNT without GROUP BY statement', 152);
						}
						if ($result[0]['individual'] != $result[0]['combined']) {
							throw new CmsDbException ('Key Violation: KEY_DISTINCT for column '.$columns[0], 153);
						}
					}
				}
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		private function getColumns ($tableName) { //returns all columns but the id column
			try {
				$result = parent::query (
					'SELECT
						COLUMN_NAME
					FROM
						INFORMATION_SCHEMA.COLUMNS
					WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
					'ss',
					parent::getDbName (),
					$tableName
				);
				$columns = array ();
				foreach ($result as $row) {
					if ($row['COLUMN_NAME'] != 'id') {
						$columns[] = $row['COLUMN_NAME'];
					}
				}
				return $columns;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		private function createTokens ($tableName = NULL) {
			try {
				//array ([token], [tokenHash]) for each
				$tokens = array (
					'confirm' => CmsSecurity::randomToken (),
					'undo' => CmsSecurity::randomToken ()
				);
				if (isset ($tableName)) {
					$type = 's';
				} else {
					$type = 'n';
				}
				$id = (int) parent::query (
					'INSERT INTO CmsDb_change
						(table_name, hashed_token_confirm, hashed_token_undo)
					VALUES
						(?, ?, ?)',
					$type.'ss',
					$tableName,
					$tokens['confirm']['tokenHash'],
					$tokens['undo']['tokenHash']
				);
				return array (
					'changeId' => $id,
					'tokens' => array (
						'confirm' => $tokens['confirm']['token'],
						'undo' => $tokens['undo']['token']
					)
				);
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		private function updateChange ($changeId, $token, $colToken, $colAction) {
			try {
				$change = parent::query (
					'SELECT
						*
					FROM
						CmsDb_change
					WHERE id = ?',
					'i',
					$change_id
				);
				if (empty ($change)) {
					throw new CmsDbException ('Change Error: unknown change id', 155);
				}
				if ($change[0][$colAction] !== NULL) {
					throw new CmsDbException ('Change Error: change already '.$colAction, 155);
				}
				//verify ($hash, $raw) returns true/false
				if (!CmsSecurity::verify ($change[0][$colToken], $token)) {
					throw new CmsDbException ('Change Error: bad token', 155);
				}
				return parent::query (
					'UPDATE
						CmsDb_change
					SET
						'.$colAction.' = NOW()
					WHERE id = ?',
					'i',
					$changeId
				);
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function confirmChange ($changeId, $token) {
			try {
				return $this -> updateChange ($changeId, $token, 'hashed_token_confirm', 'confirmed');
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function undoChange ($changeId, $token) {
			try {
				return $this -> updateChange ($changeId, $token, 'hashed_token_undo', 'undone');
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function getLastQuery () {
			try {
				if (empty ($this -> lastQuery)) {
					throw new CmsDbException ('no query executed', 106);
				}
				return $this -> lastQuery;
			} catch (Exception $e) {
				throw $e;
			}
		}
	}
?>
