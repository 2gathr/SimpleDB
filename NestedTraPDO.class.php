<?php
	/*
		Released under the GNU General Public License, Version 3 (http://www.gnu.org/licenses/gpl-3.0-standalone.html)
		Source: http://www.kennynet.co.uk/2008/12/02/php-pdo-nested-transactions/
	*/
	
	class NestedTraPDO extends PDO {
		// Database drivers that support SAVEPOINTs.
		protected static $savepointTransactions = array ("pgsql", "mysql");
		
		// The current transaction level.
		protected $transLevel = 0;
		
		protected function nestable () {
			return in_array ($this -> getAttribute (PDO::ATTR_DRIVER_NAME), self::$savepointTransactions);
		}
		
		public function beginTransaction () {
			if (!$this -> nestable () || $this -> transLevel == 0) {
				parent::beginTransaction ();
			} else {
				$this -> exec ("SAVEPOINT LEVEL{$this->transLevel}");
			}
			
			$this -> transLevel++;
		}
		
		public function commit () {
			$this -> transLevel--;
			
			if (!$this -> nestable () || $this -> transLevel == 0) {
				parent::commit();
			} else {
				$this -> exec ("RELEASE SAVEPOINT LEVEL{$this->transLevel}");
			}
		}
		
		public function rollBack () {
			$this -> transLevel--;
			
			if (!$this -> nestable () || $this -> transLevel == 0) {
				parent::rollBack ();
			} else {
				$this -> exec ("ROLLBACK TO SAVEPOINT LEVEL{$this->transLevel}");
			}
		}
	}
?>
