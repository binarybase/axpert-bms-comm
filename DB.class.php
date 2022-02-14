<?php
	class DBQueryBuilder{
		private $_base = '';
		private $_additional = '';
		// where params and tokens
		private $_pdo_params = array();
		private $_pdo_tokens = array();
		private $_limit = null;
		private $_ended = false;
		private $_orderby = '';
		private $_groupby = array();
		private $_joins = array();
		private $_isUpdate = false;
		private $_isInsert = false;
		private $_hasFields = false;

		function __construct($base){ $this->_base = $base; }
		public function setUpdate(){ $this->_isUpdate = true; return $this; }
		public function setInsert(){ $this->_isInsert = true; return $this; }
		public function hasFields(){ return $this->_hasFields;}

		public function addRecord($record, $filterFn = null){
			$fields = array();
			$values = array();

			foreach($record as $property => $value){
				if(is_array($value) || is_object($value))
					continue;

				$data_type = null;

				if(is_callable($filterFn)){
					$data_type = $filterFn($property, $value);
					if(!$data_type || $data_type === null)
						continue;
				}

				// skip ID if update
				if(($this->_isUpdate && $property == 'id') || $data_type === false)
					continue;

				if($this->_isUpdate){
					$fields[] = $property . ' = :' . $property;
				} else if($this->_isInsert){
					$fields[] = $property;
					$values[] = ':' . $property;
				}
				$this->token($value, ':' . $property, $data_type);
			}

			if(count($fields) > 0){
				if($this->_isUpdate)
					$this->join("SET " . implode(',', $fields));
				else if($this->_isInsert)
					$this->join("(" . implode(',', $fields) . ") VALUES(" . implode(',', $values) . ")");

				$this->_hasFields = true;
			}
			return $this;
		}
		public function token($value, $token = null, $dateType = null){
			$tok_idx = $token === null ? ':tok' . count($this->_pdo_params) : $token;

			switch($dateType){
				case 'bool':
				case 'boolean':
					$value = $value == 1 || $value == '1' || $value == true || $value == 'on' ? true : false;
					break;
				case 'date':
					$value = empty($value) ? '' : self::sqlDate($value);
					break;
				case 'float':
				case 'decimal':
					$value = (float) $value;
					break;
				case 'int':
				case 'number':
					$value = (int) $value;
					break;
			}

			$this->_pdo_tokens[$tok_idx] = $value;
			return $tok_idx;
		}

		public function where($param, $value = null, $dateType = null){
			// a.id = [number]
			if(is_string($param) && $value !== null){
				$tok_idx = $this->token($value, null, $dateType);
				$this->_pdo_params[] = $param . ' = ' . $tok_idx;
			}

			// a.id = DAY(some_property)
			else if(is_string($param) && $value === null){
				$this->_pdo_params[] = $param;
			}

			return $this;
		}

		public function addBase($query){
			$this->_base .= "\n" . $query;
			return $this;
		}
		public function add($query){
			$this->_additional .= "\n" . $query;
			return $this;
		}

		public function limit($limit = null){ $this->_limit = $limit; return $this; }
		public function orderBy($order){ $this->_orderby = $order; return $this; }
		public function groupBy($property){
			if(is_array($property)){
				$this->_groupby[] = array_merge($this->_groupby, $property);
			} else{
				$this->_groupby[] = $property;
			}
		}
		public function join($join){ $this->_joins[] = $join; return $this; }
		public function end($tail = ''){
			$this->_ended = true;

			// add additional
			$this->addBase($this->_additional);

			// add join if any
			if(count($this->_joins) > 0){
				$this->addBase("\n" . implode("\n", $this->_joins));
			}

			// add where if any
			if(count($this->_pdo_params) > 0){
				$this->_base .= "\nWHERE " . implode(' AND ', $this->_pdo_params);
			}

			$this->_base .= "\n" . $tail;

			// add group by
			if($this->_groupby)
				$this->_base .= "\n" . 'GROUP BY ' . implode(',', $this->_groupby);

			// add order by
			if(!empty($this->_orderby))
				$this->_base .= "\n" . 'ORDER BY ' . $this->_orderby;

			// add limit at the end if exists
			if($this->_limit !== null){
				$this->_base .= "\n" . 'LIMIT ' . $this->_limit;
			}

			$this->_base .= "\n;";

			return $this->_base;
		}

		public function compile(){
			if(!$this->_ended)
				$this->end();

			return $this->_base;
		}
		
		public function getTokens(){
			return $this->_pdo_tokens;
		}
		
		public function exec(){
			return DB::query($this);
		}

		public static function sqlDate($t){
			if(!is_numeric($t))
				$t = strtotime(str_replace('T', ' ', $t));
			return $t > 0 ? date("Y-m-d H:i:s", $t) : '0000-00-00 00:00:00';
		}
	};
	
	class DBQuery{
		private $conn;
		private $res;
		private $query;
		private $param;
		
		function __construct($conn, $query, $param){
			$this->conn = $conn;
			$this->query = $query;
			$this->param = $param;

			$this->res = $this->conn->prepare($this->query);
			if(!$this->res){
				$this->exception();
			}

			if(isset($this->param[0]) && is_array($this->param[0])){
				foreach($this->param as $p){
					if(!$this->res->execute($p)){
						$this->exception();
					}
				}
			} else{
				if(!$this->res->execute($this->param)){
					$this->exception();
				}
			}
		}
		
		private function exception(){
			throw new Exception(implode("\n", $this->conn->errorInfo()));
		}

		public function getError(){
			return $this->conn->errorInfo();
		}

		public function query(){
			return $this->res->rowCount();
		}
		
		public function getOne($object = false){
			return $this->res->fetch($object ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC);
		}
		
		public function getAll($object = false){
			return $this->res->fetchAll($object ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC);
		}
		
		public function getSingle(){
			$t = $this->getOne();
			if(!$t) return null;
			list($s) = array_values($t);
			
			return $s;
		}
		
		public function rowCount(){
			return $this->res->rowCount();
		}
		public function getCount(){
			return $this->rowCount();
		}
		
		public function isAdded(){ return $this->rowCount() > 0; }
		public function lastInsertId(){ return $this->conn->lastInsertId(); }
		public function getLastId(){ return $this->conn->lastInsertId(); }
	};
	
	class DB{
		private $conn;
		private $user;
		private $host;
		private $dbname;

		function __destruct(){
			$this->conn = null;
		}

		function __construct($host, $db, $user, $password){
			$this->user = $user;
			$this->host = $host;
			$this->dbname = $db;
			
			$options=array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::MYSQL_ATTR_INIT_COMMAND => "SET CHARACTER SET utf8;",
				PDO::ATTR_TIMEOUT => 10,
				PDO::ATTR_PERSISTENT => false
			);
			$this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->dbname, $this->user, $password, $options);
			if(!$this->conn){
				throw new Exception("DB: Cannot set up the database connection :(");
				return false;
			}
			return true;
		}

		public function query($query,$param=array()){
			$isDBQueryBuilder = is_object($query) && $query instanceof DBQueryBuilder;
			$compiled_query = $isDBQueryBuilder ? $query->compile() : $query;

			return new DBQuery($this->conn, $compiled_query, $isDBQueryBuilder && count($bparams = $query->getTokens()) > 0 ? $bparams : $param);
		}
	};
