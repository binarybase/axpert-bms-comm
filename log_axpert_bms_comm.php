#!/usr/bin/php
<?php
	define('BASE_DIR', '/www/home/');
	require(BASE_DIR . 'DB.class.php');
	require(BASE_DIR . 'Sensors.class.php');

	declare(ticks = 1);

	//  7e 30 30 30 32 34 36 34 46 30 30 30 30 46 44 39 41 0d
	//  7e 30 30 35 32 34 36 34 46 30 30 30 30 46 44 39 35 0d
	//  7e 30 30 34 32 34 36 34 46 30 30 30 30 46 44 39 36 0d
	//  7e 30 30 33 32 34 36 34 46 30 30 30 30 46 44 39 37 0d
	//  7e 30 30 32 32 34 36 34 46 30 30 30 30 46 44 39 38 0d
	//  7e 30 30 31 32 34 36 34 46 30 30 30 30 46 44 39 39 0d

	/*	7e - prefix
		30 - version
		30 - adr
		30 - cid1
		32 - cid2
		34 - len
		36 - 
		34 - 
		46 - 
		30 - 
		30 - 
		30
		30
		46
		44
		39
		41
		0d - postfix

	class BmsPylProtocol {
		// parse message and returns response with Crc
		public function handle($msg){
			if(($response = $this->parse($msg)) !== null){
				$package = implode('', array_map(function($c){ return pack('C', $c); }, $response));
				return $package . self::crc16($package);
			}
		}

		protected function parse($data){
			$msg = unpack('C*', $data);
			if(count($msg) < 10){
				do_log("protocol: error [message empty]\n";
				return null;
			}

			if($msg[0] != 0x7e){
				do_log("protocol: missing prefix\n";
				return null;
			}



			do_log("protocol: Unknown data address\n";
			return null;
		}

		public static function uint($l){ return [1, 0x03, 0, 2, ($l >> 24) & 0xFF, ($l >> 16) & 0xFF, ($l >> 8) & 0xFF, $l & 0xFF]; }
		public static function ushort10($l){ return self::ushort($l * 10); }
		public static function ushort($l){ return [1, 0x03, 0, 1, $l >> 8, $l & 0xFF]; }

	}*/

	class BmsLicProtocol {
		function __construct(){ $this->sensors = new Sensors(); }
		public static function crc16($data){
			$crc = 0xFFFF;
			for ($i = 0; $i < strlen($data); $i++)
			{
				$crc ^=ord($data[$i]);
		 		for ($j = 8; $j !=0; $j--)
				{
					if (($crc & 0x0001) !=0)
					{
						$crc >>= 1;
						$crc ^= 0xA001;
					}
					else
					$crc >>= 1;
				}		
			}
			$highCrc=floor($crc/256);
			$lowCrc=($crc-$highCrc*256);
			return chr($lowCrc).chr($highCrc);
		}

		// parse message and returns response with Crc
		public function handle($msg){
			if(($response = $this->parse($msg)) !== null){
				$package = implode('', array_map(function($c){ return pack('C', $c); }, $response));
				return $package . self::crc16($package);
			}
		}

		protected function parse($data){
			$msg = unpack('C*', $data);
			if(count($msg) < 4){
				do_log("protocol: error [message empty]\n");
				return null;
			}

			//printf("protocol: parse: %02X %02X %02X %X\n", $msg[1], $msg[2], $msg[3], $msg[4]);

			if($msg[1] != 1 || $msg[2] != 0x03){
				//do_log("protocol: error [battery address: " . $msg[1] . ", cmd type: " . $msg[2] . "]\n";
				return null;
			}

			// get current bms status
			$bms = $this->sensors->getBms();
			// assembly address
			$address = ($msg[3] << 8) + $msg[4];
			do_log(sprintf("protocol: Respond to address: %02X (%02X %02X)\n", $address, $msg[3] << 8, $msg[4]));

			switch($address){
				// battery charge voltage limit (0.1V)
				case 0x70:
					return self::ushort10(56.7);
				// discharge voltage limit (0.1V)
				case 0x71:
					return self::ushort10(45.5);
				// charge current limit
				case 0x72:
					return self::ushort10(100);
				// discharge current limit
				case 0x73:
					return self::ushort10(120);
				// total capacity mAh
				case 0x34:
					return self::uint(275000);
				// charge discharge status
				case 0x74:
					$status = 0;
					$status |= ($bms->cell_max < 4.15) << 7; // charge enable
					$status |= 1 << 6; // discharge enable
					$status |= ($bms->cell_min < 3.250) << 5; // charge immediately (SOC <= 9%)
					$status |= ($bms->cell_min > 3.250 && $bms->cell_min <= 3.3) << 4; // charge immediately (9<SOC<=14%)
					$status |= 0 << 3; // full charge request
					$status |= 0 << 2; // small current charge request (always 0)
					return self::ushort($status);
				// SOC%
				case 0x33:
					return self::ushort($bms->percent_capacity);
			}

			do_log("protocol: Unknown data address\n");
			return null;
		}

		public static function uint($l){ return [1, 0x03, 0, 2, ($l >> 24) & 0xFF, ($l >> 16) & 0xFF, ($l >> 8) & 0xFF, $l & 0xFF]; }
		public static function ushort10($l){ return self::ushort($l * 10); }
		public static function ushort($l){ return [1, 0x03, 0, 1, $l >> 8, $l & 0xFF]; }

	}

	class AxpertBmsBridge{
		private static $port = "/dev/ttyUSB2";
		private $fd = null;

		public function opened(){ return $this->fd !== null && is_resource($this->fd); }

		function __construct(){
			exec(sprintf("stty -F %s -echo -echoe -echok raw speed 9600", self::$port));

			do_log("bridge: opening port " . self::$port . " ... ");
			try{
				$this->fd = fopen(self::$port, "w+b");
			} catch(Exception $ex){
				do_log("failed to open port (" . $ex->getMessage() . ")\n");
				$this->fd = null;
				return;
			}

			if($this->fd !== false){
				pcntl_signal(SIGINT, [$this, "close"]);
				if(!stream_set_blocking($this->fd, false)){
					do_log("bridge: failed to set stream blocking!\n");
				}
				do_log("bridge: opened!\n");
				return;
			}

			do_log("bridge: failed to open " . self::$port . "!\n");
		}

		function __destruct(){
			$this->close();
		}

		public function close(){
			if(is_resource($this->fd)){
				do_log("bridge: closing port " . self::$port . "\n");
				fclose($this->fd);
				$this->fd = null;
			}
		}

		public function write($raw_cmd){
			if(!is_resource($this->fd)){
				return;
			}

			//do_log("bridge: sending command: cmd len: " . strlen($raw_cmd) . "\n";
			if(fwrite($this->fd, $raw_cmd) === false){
				do_log("bridge: failed to write into " . self::$port . "!\n");
				return null;
			}

			fflush($this->fd);
		}

		public function read(){
			$haveData = false;
			$timer = null;
			$chars = [];
			//do_log("bridge: start reading.";

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
					usleep(500);
					// if we have still no data reset timer and continue
					if($timer !== null && (microtime(true) - $timer) >= 0.1){
						//do_log("timeout|");
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

			//do_log(" complete\n");
			// reading ended timeout has been reached
			return implode('', $chars);
		}
	}

	$a = null;
	$a = new AxpertBmsBridge();
	$protocol = new BmsLicProtocol();

	while($a->opened()){
		$request = $a->read();
		if(strlen($request) === 0){
			continue;
		}

		//do_log("Request: " . hex_dump($request) . "\n";
		if(($response = $protocol->handle($request)) !== null){
			//do_log("Response: " .hex_dump($response) . "\n";
			$a->write($response);
		}
	}

	//echo hex_dump($protocol->handle(pack('C*', 1, 0x03, 0x00, 0x33)));

	$shutdown_fn = function() use ($a) {
		$a->close();
		exit;
	};

	register_shutdown_function($shutdown_fn);
	pcntl_signal(SIGINT, $shutdown_fn);
	pcntl_signal(SIGTERM, $shutdown_fn);

	function hex_dump($string){
		$hex = '';
		for ($i = 0; $i < strlen($string); $i++) {
		    $hex .= str_pad(dechex(ord($string[$i])), 2, '0', STR_PAD_LEFT) . ' ';
		}
		return $hex;
	}


	function do_log($msg){
		echo "[" . date('Y-m-d H:i:s') . "] " . $msg;
	}
