<?php
	class Serial{
		protected static $params = '-echo -echoe -echok raw speed 9600';
		protected static $port = "/dev/ttyUSB2";
		protected static $wait_timeout = 500;
		protected static $data_timeout = 0.1;
		private $fd = null;

		public function opened(){ return $this->fd !== null && is_resource($this->fd); }

		function __construct(){
			exec(sprintf("stty -F %s %s", self::$port, self::$params));

			Util::log("bridge: opening port " . self::$port . " ... ");
			try{
				$this->fd = fopen(self::$port, "w+b");
			} catch(Exception $ex){
				Util::log("failed to open port (" . $ex->getMessage() . ")\n");
				$this->fd = null;
				return;
			}

			if($this->fd !== false){
				pcntl_signal(SIGINT, [$this, "close"]);
				if(!stream_set_blocking($this->fd, false)){
					Util::log("bridge: failed to set stream blocking!\n");
				}
				Util::log("bridge: opened!\n");
				return;
			}

			Util::log("bridge: failed to open " . self::$port . "!\n");
		}

		function __destruct(){
			$this->close();
		}

		public function close(){
			if(is_resource($this->fd)){
				Util::log("bridge: closing port " . self::$port . "\n");
				fclose($this->fd);
				$this->fd = null;
			}
		}

		public function write($raw_cmd){
			if(!is_resource($this->fd)){
				return;
			}

			//Util::log("bridge: sending command: cmd len: " . strlen($raw_cmd) . "\n";
			if(fwrite($this->fd, $raw_cmd) === false){
				Util::log("bridge: failed to write into " . self::$port . "!\n");
				return null;
			}

			fflush($this->fd);
		}

		public function read(){
			$haveData = false;
			$timer = null;
			$chars = [];
			//Util::log("bridge: start reading.";

			// do reading process 
			do {
				// stop when resource is unavailable
				if($this->fd === null){
					return null;
				}

				// read non-blocking
				$char = fread($this->fd, 1);
				// when char is empty, skip and wait until buffer fills with some chars
				if($char === ''){
					usleep(self::$wait_timeout * 1000);
					// if we have still no data reset timer and continue
					if($timer !== null && (microtime(true) - $timer) >= self::$data_timeout){
						//Util::log("timeout|");
						// break the loop
						break;
					}

					continue;
				}

				// when some characters are available
				$chars[] = $char;
				// setup timer to reach reading timeout
				if(!$haveData){
					// reading start time
					$timer = microtime(true);
				}
				// have data
				$haveData = true;

			} while($this->fd !== null && is_resource($this->fd));

			//Util::log(" complete\n");
			// reading ended timeout has been reached
			return implode('', $chars);
		}
	}