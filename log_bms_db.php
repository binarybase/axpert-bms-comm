#!/usr/bin/php
<?php

// ./jbdtool -j -t serial:/dev/ttyUSB1,9600

	define('BASE_DIR', dirname(__FILE__) . '/');
	require_once(BASE_DIR . 'Service.class.php');

	class BmsLogger extends Service{
		protected function onRun(){
			while(true){
				$response = $this->getCmdResponse();
				if(!empty($response)){
					$this->setProperty('bms', $response);
					$this->setProperty('bms_cell_min', $response->CellMin);
					$this->setProperty('bms_cell_max', $response->CellMax);
					$this->setProperty('bms_capacity', $response->PercentCapacity);
				}

				sleep(1);
			}
		}

		protected function getCmdResponse(){
			$response = shell_exec("timeout 15s " . dirname(__FILE__) . "/jbdtool -j -t serial:/dev/bms,9600");
			if(!$response || empty($response)){
				return null;
			}

			try{
				$json = json_decode($response);
				return $json;
			} catch(Exception $ex){
				return null;
			}
		}
	}
