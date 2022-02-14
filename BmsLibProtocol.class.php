<?php
	class BmsLibProtocol {
		public $bms_cell_max = null;
		public $bms_cell_min = null;
		public $bms_capacity = null;

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
				Util::log("protocol: error [message empty]\n");
				return null;
			}

			//printf("protocol: parse: %02X %02X %02X %X\n", $msg[1], $msg[2], $msg[3], $msg[4]);

			if($msg[1] != 1 || $msg[2] != 0x03){
				//Util::log("protocol: error [battery address: " . $msg[1] . ", cmd type: " . $msg[2] . "]\n";
				return null;
			}

			// assembly address
			$address = ($msg[3] << 8) + $msg[4];
			Util::log(sprintf("protocol: Respond to address: %02X (%02X %02X)\n", $address, $msg[3] << 8, $msg[4]));

			switch($address){
				// battery charge voltage limit (0.1V)
				case 0x70:
					return self::ushort10(57.4);
				// discharge voltage limit (0.1V)
				case 0x71:
					return self::ushort10(44.8);
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
					$status |= ($this->bms_cell_max < 4.2) << 7; // charge enable
					$status |= 1 << 6; // discharge enable
					$status |= ($this->bms_cell_min < 3.150) << 5; // charge immediately (SOC <= 9%)
					$status |= ($this->bms_cell_min > 3.150 && $this->bms_cell_min <= 3.2) << 4; // charge immediately (9<SOC<=14%)
					$status |= 0 << 3; // full charge request
					$status |= 0 << 2; // small current charge request (always 0)
					return self::ushort($status);
				// SOC%
				case 0x33:
					return self::ushort($this->bms_capacity);
			}

			Util::log("protocol: Unknown data address\n");
			return null;
		}

		public static function uint($l){ return [1, 0x03, 0, 2, ($l >> 24) & 0xFF, ($l >> 16) & 0xFF, ($l >> 8) & 0xFF, $l & 0xFF]; }
		public static function ushort10($l){ return self::ushort($l * 10); }
		public static function ushort($l){ return [1, 0x03, 0, 1, $l >> 8, $l & 0xFF]; }

	}
