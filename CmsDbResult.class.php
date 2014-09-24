<?php
	class CmsDbResult {
		private $cmsDb, $lastInsertId, $affectedRows, $tokens;
		
		public function __construct (CmsDb $cmsDb, $queryType, $returnValue, array $tokens) {
			$this -> cmsDb = $cmsDb;
			$this -> tokens = $tokens;
			switch ($queryType) {
				case 'INSERT':
					$this -> lastInsertId = $returnValue;
					break;
				case 'UPDATE':
				case 'DELETE':
					$this -> affectedRows = $returnValue;
					break;
				default:
					throw new CmsDbException ('Internal Error: unknown query type', 152);
			}
		}
		
		public function lastInsertId () {
			if (isset ($this -> lastInsertId)) {
				return $this -> lastInsertId;
			} else {
				throw new CmsDbException ('Result Error: no last insert ID set', 157);
			}
		}
		
		public function affectedRows () {
			if (isset ($this -> affectedRows)) {
				return $this -> affectedRows;
			} else {
				throw new CmsDbException ('Result Error: no affected rows set', 157);
			}
		}
		
		public function confirm () {
			try {
				$this -> cmsDb -> confirmChange ($this -> tokens['changeId'], $this -> tokens['tokens']['confirm']);
				return $this;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function undo () {
			try {
				$this -> cmsDb -> undoChange ($this -> tokens['changeId'], $this -> tokens['tokens']['undo']);
				return $this;
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		public function getTokens () {
			return $this -> tokens;
		}
	}
?>
