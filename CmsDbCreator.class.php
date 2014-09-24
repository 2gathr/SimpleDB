<?php
	class CmsDbCreator {
		private $db, $name;
		private $columns = array ();
		
		private function __construct (SimpleDb $db, $name) { //$name is the table name
			try {
				$this -> db = $db;
				$this -> name = $name;
				$columns = $db -> query (
					'SELECT
						COLUMN_NAME
					FROM
						INFORMATION_SCHEMA.COLUMNS
					WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
					'ss',
					$db -> getDbName (),
					$name
				);
				if (count ($columns) > 1) { //there are at least the id column and a second one
					foreach ($columns as $col) {
						$this -> columns[] = $col['COLUMN_NAME'];
					}
				} else {
					throw new CmsDbException ('Database Inconsistency: less than 2 columns in table '.$name, 154);
				}
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public static function formatDatabase (SimpleDb $db) {
			try {
				$db -> query (
					'CREATE TABLE CmsDb_table (
						id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
						# maximum table name length is defined in CmsDb::REGEX_TABLE_NAME
						name varchar(28) NOT NULL,
						PRIMARY KEY (id)
					)'
				);
				$db -> query (
					'CREATE TABLE CmsDb_change (
						id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
						# maximum table name length is defined in CmsDb::REGEX_TABLE_NAME
						table_name varchar(28) NULL DEFAULT NULL,
						time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
						hashed_token_confirm varchar(255) NOT NULL,
						confirmed timestamp NULL DEFAULT NULL,
						hashed_token_undo varchar(255) NOT NULL,
						undone timestamp NULL DEFAULT NULL,
						PRIMARY KEY (id)
					)'
				);
				$db -> query (
					'CREATE TABLE CmsDb_autoconfirm (
						id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
						# maximum table name length is defined in CmsDb::REGEX_TABLE_NAME
						table_name varchar(28) NOT NULL,
						PRIMARY KEY (id)
					)'
				);
				$db -> query (
					'CREATE TABLE CmsDb_state (
						id int(10) UNSIGNED NOT NULL,
						name varchar(20) NOT NULL,
						PRIMARY KEY (id)
					)'
				);
				$db -> query (
					'INSERT INTO CmsDb_state
						(id, name)
					VALUES
						('.CmsDb::STATE_DEFAULT.', \'default\'),
						('.CmsDb::STATE_DELETED.', \'deleted\')'
				);
				return true;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public static function createTable (CmsDb $db, $name) {
			try {
				if (!preg_match (CmsDb::REGEX_TABLE_NAME, $name)) {
					throw new CmsDbException ('Creator Error: table name not allowed', 156);
				}
				$db -> query (
					'CREATE VIEW '.$name.' AS
						SELECT
							1'
				);
				$db -> query (
					'INSERT INTO CmsDb_table
						(name)
					VALUES
						("'.$name.'")'
				);
				$db -> getTables ();
				$db -> query (
					'CREATE TABLE '.$name.'_key (
						id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
						types int(10) UNSIGNED NOT NULL,
						PRIMARY KEY (id)
					)'
				);
				$db -> query (
					'CREATE TABLE '.$name.'_key_col (
						id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
						key_id int(10) UNSIGNED NOT NULL,
						# use of "col" because "column" is a reserved word
						# maximum column name length is defined in CmsDb::REGEX_COLUMN_NAME
						col varchar(28) NOT NULL,
						PRIMARY KEY (id),
						FOREIGN KEY (key_id) REFERENCES '.$name.'_key(id)
							ON DELETE CASCADE
							ON UPDATE CASCADE
					)'
				);
				$creator = new self ($db, $name);
				return $creator -> addColumn (
					'state',
					'int(10) UNSIGNED NOT NULL DEFAULT '.CmsDb::STATE_DEFAULT.'
						REFERENCES CmsDb_state(id)
							ON DELETE RESTRICT
							ON UPDATE CASCADE'
				);
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public static function alterTable (CmsDb $db, $name) {
			try {
				$result = $db -> query (
					'SELECT
						1
					FROM
						CmsDb_table
					WHERE name = \''.$name.'\''
				);
				if (empty ($result)) {
					throw new CmsDbException ('Creator Error: unknown table/table not CmsDb formatted: '.$name, 156);
				}
				return new self ($db, $name);
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public static function dropTable (CmsDb $db, $name) {
			try {
				//TODO
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function addColumn ($colName, $columnDefinition, $autoConfirm = false, $keys = 0) {
			try {
				if (!preg_match (CmsDb::REGEX_COLUMN_NAME, $colName)) {
					throw new CmsDbException ('Creator Error: column name not allowed', 156);
				}
				//TODO: exception if column already exists?? -> is automatically thrown by MySQL because table already exists
				$this -> columns[] = $colName;
				if ($autoConfirm) {
					$this -> db -> query (
						'INSERT INTO CmsDb_autoconfirm
							(table_name, autoconfirm)
						VALUES
							(?, ?)',
						'sb',
						$this -> name.'_'.$colName,
						$autoConfirm
					);
				}
				$this -> db -> query (
					'CREATE TABLE '.$this -> name.'_'.$colName.' (
						id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
						'.$this -> name.'_id int(10) UNSIGNED NOT NULL,
						'.$colName.' '.$columnDefinition.',
						change_id int(10) UNSIGNED NOT NULL,
						PRIMARY KEY (id),
						FOREIGN KEY (change_id) REFERENCES CmsDb_change(id)
							ON DELETE RESTRICT
							ON UPDATE CASCADE
					)'
				);
				$this -> db -> query (
					'CREATE VIEW '.$this -> name.'_'.$colName.'_view AS
						SELECT
							'.$this -> name.'_id, '.$colName.'
						FROM
							'.$this -> name.'_'.$colName.'
						WHERE id IN (
							SELECT
								MAX(a.id)
							FROM
								'.$this -> name.'_'.$colName.' a
							LEFT JOIN
								CmsDb_change b ON a.change_id = b.id
							LEFT JOIN
								CmsDb_autoconfirm c ON c.table_name = ?
							WHERE ISNULL(b.undone) AND (b.confirmed IS NOT NULL OR c.table_name IS NOT NULL)
							GROUP BY a.'.$this -> name.'_id
						)',
					's',
					$this -> name.'_'.$colName
				);
				$select = 't0.'.$this -> name.'_id AS id';
				$from = $this -> name.'_'.$this -> columns[0].' t0';
				foreach ($this -> columns as $i => $col) {
					$select .= ', t'.$i.'.'.$col;
					if ($i > 0) {
						$from .= ' LEFT JOIN '.$this -> name.'_'.$col.'_view t'.$i.' ON t0.'.$this -> name.'_id = t'.$i.'.'.$this -> name.'_id';
					}
				}
				$this -> db -> query (
					'CREATE OR REPLACE VIEW '.$this -> name.' AS
						SELECT
							'.$select.'
						FROM
							'.$from.'
						WHERE t0.state != ?', //t0 is always the state table
					'i',
					CmsDb::STATE_DELETED
				);
				if ($keys) {
					$this -> addKey ($keys, $colName);
				}
				//fill in default value for every id
				//if there is already data in the table/view, a default value
				//has to have been set in the column definition
				if ($this -> db -> query (
					'SELECT
						'.$this -> name.'_id
					FROM
						'.$this -> name.'_state
					LIMIT 1'
				)) {
					$this -> db -> query (
						'INSERT INTO '.$this -> name.'_'.$colName.'
							('.$this -> name.'_id)
						SELECT
							'.$this -> name.'_id
						FROM
							'.$this -> name.'_state'
					);
				}
				return $this;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function addKey ($types, $columns) { //TODO: add possibility of where clause?
			//$columns can be an array or a single column name
			try {
				if (!is_array ($columns)) {
					$columns = array($columns);
				}
				if (count ($columns) == 0) {
					throw new CmsDbException ('Creator Error: no columns given', 156);
				}
				foreach ($columns as $col) {
					if (!in_array ($col, $this -> columns)) {
						throw new CmsDbException ('Creator Error: unknown column: '.$col, 156);
					}
				}
				if ($types == 0 || $types ^ (CmsDb::KEY_UNIQUE | CmsDb::KEY_NEW | CmsDb::KEY_DISTINCT)) {
					throw new CmsDbException ('Creator Error: unknown key type flag', 156);
				}
				if ($types & (CmsDb::KEY_NEW | CmsDb::KEY_DISTINCT) && count ($columns > 1)) {
					throw new CmsDbException ('Creator Error: only one column allowed for KEY_NEW and KEY_DISTINCT', 156);
				}
				$this -> db -> beginTra ();
				$keyId = $this -> db -> query (
					'INSERT INTO '.$this -> name.'_key
						(types)
					VALUES
						(?)',
					'i',
					$types
				);
				$query = '';
				foreach ($columns as $col) {
					$this -> db -> query (
						'INSERT INTO '.$this -> name.'_key_col
							(key_id, col)
						VALUES
							(?, ?)',
						'is',
						$keyId,
						$col
					);
				}
				$this -> db -> checkKeys ($this -> name, $columns);
				$this -> db -> commitTra ();
				return $keyId;
			} catch (Exception $e) {
				try {
					$this -> db -> rollbackTra ();
				} catch (Exception $e) {
					throw $e;
				}
				throw $e;
			}
		}
		
		public function removeKey ($keyId) {
			try {
				if (!$this -> db -> query (
					'DELETE FROM
						'.$this -> name.'_key
					WHERE id = ?',
					'i',
					$keyId
				)) {
					throw new CmsDbException ('Creator Error: unknown key id', 156);
				}
				return $this;
			} catch (Exception $e) {
				throw $e;
			}
		}
	}
?>
