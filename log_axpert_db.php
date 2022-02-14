#!/usr/bin/php
<?php
	define('BASE_DIR', dirname(__FILE__) . '/');
	require_once(BASE_DIR . 'Service.class.php');
	require_once(BASE_DIR . 'Serial.class.php');
	require_once(BASE_DIR . 'Util.class.php');

// -- QPIGS
	// 0 - 239.4 (grid voltage)
	// 1 - 49.9 (grid freq)
	// 2 - 239.4 (ac out)
	// 3 - 49.9 (ac freq)
	// 4 - 0478 (ac out apparent power)
	// 5 - 0428 (ac out active power)
	// 6 - 006 (output load %)
	// 7 - 377 (bus voltage)
	// 8 - 47.70 (batt voltage)
	// 9 - 001 (batt charge current)
	// 10 - 055 (batt capacity %)
	// 11 - 0021 (inverter temp)
	// 12 - 00.2 (input current SCC1)
	// 13 - 089.9 (input volt SCC1)
	// 14 - 00.00 (batt volt from SCC)
	// 15 - 00000 (batt discharge current)
	// 16 - 00010110 (device status, 6 - scc charging, 7 - ac charging)
	// 17 - 00 (batt offset for fans)
	// 18 - 00 (eeprom version)
	// 19 - 00026 (SCC1 charging power watt)
	// 20 - 010 (device flags)
// -- QMOD
	// 21 - L
// -- QPIGS2
	// 22 - 00.1 (scc2 input current)
	// 23 - 090.0 (scc2 voltage) 
	// 24 - 00017 (scc2 charging power watt)

// -- QPGS0
	// 0 - 0
	// 1 - 92932104104465
	// 2 - L (work mode)
	// 3 - 00 (fault code)
	// 4 - 226.2 (grid volt)
	// 5 - 50.03 (grid freq)
	// 6 - 226.2 (ac out volt)
	// 7 - 50.03 (ac out freq)
	// 8 - 2014 (ac out apparent power)
	// 9 - 2014 (ac out active power)
	// 10 - 027 (load %)
	// 11 - 46.6 (batt V)
	// 12 - 001 (batt charge current)
	// 13 - 044 (batt %)
	// 14 - 089.9 (scc1 voltage)
	// 15 - 001 (total charge current)
	// 16 - 02014 (totl out apparent power)
	// 17 - 02014 (total out active power)
	// 18 - 027 (ac out percentage)
	// 19 - 11100010 (device status)
	// 20 - 0 (output mode 0 - single device)
	// 21 - 1 (charger source priority, 0 - U, 1 - S, 2 - SU, 3 - S only)
	// 22 - 060 (batt charge max current limit)
	// 23 - 080 (batt charge max current)
	// 24 - 02 (ac charge max current)
	// 25 - 00 (scc1 current)
	// 26 - 000 (batt discharge current)
	// 27 - 090.0 (scc2 voltage)
	// 28 - 00 (scc2 current)

	class AxpertComm extends Serial{
		protected static $port = "/dev/ttyUSB1";
		protected static $params = "cs8 -parenb -cstopb -echo raw speed 2400";
		protected static $wait_timeout = 1000;
		protected static $data_timeout = 1000;

		private $QPIGS = ["\x51\x50\x49\x47\x53\xB7\xA9\x0D", 110];
		private $QPIGS2 = ["\x51\x50\x49\x47\x53\x32\x68\x2D\x0D", 21];
		private $QMOD = ["\x51\x4D\x4F\x44\x96\x1F\x0D", 5];
		private $QPGS0 = ["\x51\x50\x47\x53\x30\x3F\xDA\x0D", 142];
		private $QPIRI = ["\x51\x50\x49\x52\x49\xF8\x54\x0D", 108];

		public function command($cmd){
			if(!property_exists($this, $cmd)){
				Util::log("invalid command " . $cmd . "\n");
				return null;
			}

			$this->write($this->{$cmd}[0]);

			$cmd_len = $this->{$cmd}[1];
			$tries = 5;
			do{
				if(!is_resource($this->fd)){
					break;
				}

				$response = $this->read($this->{$cmd}[1]);
				if(!$response){
					Util::log("failed\n");
					break;
				}

				if(strpos($response, 'NAKss') !== false){
					Util::log("got NAKss\n";
					usleep(500000);
					Util::log("sending command again (tries: " . $tries . ")\n");
					$this->write($this->{$cmd}[0]);
				}

				else if(substr($response, 0, 1) == '('){
					// skip command without valid length
					if(strlen($response) != $cmd_len)
						return null;

					//Util::log("got valid response " . preg_replace('/[^\(\s\.a-z0-9]/i', '', $response) . ", response len: " . strlen($response) . "\n";
					Util::log("got valid response " . strlen($response) . "\n");

					return trim(substr($response, 1, strrpos($response, "\x0D")));
				}

			} while (--$tries);

			Util::log("command timeout\n";
		}
	}

	class AxpertLogger extends Service{
		protected function onRun(){
			$this->comm = new AxpertComm();

			while(true){
				$qpigs = $this->comm->command("QPIGS");
				$qmod = $this->comm->command("QMOD");
				$qpigs2 = $this->comm->command("QPIGS2");
				$qpiri = $this->comm->command("QPIRI");

				if(!$qpigs || !$qmod || !$qpigs2 || !$qpiri){
					Util::log("Failed to get one of specified commands\n");
					usleep(500000);
					continue;
				}

				$this->setProperty('axpert', implode(' ', [$qpigs, $qmod, $qpigs2, $qpiri]));
				usleep(500000);
			}
		}

		protected function onShutdown(){
			$this->comm->close();
		}
	}

	new AxpertLogger();