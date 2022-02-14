#!/usr/bin/php
<?php
	define('BASE_DIR', dirname(__FILE__) . '/');
	require(BASE_DIR . 'Service.class.php');
	require(BASE_DIR . 'Serial.class.php');
	require(BASE_DIR . 'BmsLibProtocol.class.php');

	class AxpertBmsBridge extends Serial{
		protected static $port = '/dev/ttyUSB2';
		protected static $params = '-echo -echoe -echok raw speed 9600';
		protected static $wait_timeout = 500;
		protected static $data_timeout = 0.1;
	}

	class AxpertBmsService extends Service{
		protected function onRun(){
			$this->bridge = new AxpertBmsBridge();
			$protocol = new BmsLibProtocol();

			while($this->bridge->opened()){
				$request = $this->bridge->read();
				if(strlen($request) === 0){
					continue;
				}

				// update with current values
				$protocol->bms_cell_min = $this->getProperty('bms_cell_min');
				$protocol->bms_cell_max = $this->getProperty('bms_cell_max');
				$protocol->bms_capacity = $this->getProperty('bms_capacity');

				//do_log("Request: " . hex_dump($request) . "\n";
				if(($response = $protocol->handle($request)) !== null){
					//do_log("Response: " .hex_dump($response) . "\n";
					$this->bridge->write($response);
				}
			}
		}

		protected function onShutdown(){
			$this->bridge->close();
		}
	}

