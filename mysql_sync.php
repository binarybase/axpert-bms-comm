#!/usr/bin/php
<?php

// ./jbdtool -j -t serial:/dev/ttyUSB1,9600

	define('BASE_DIR', dirname(__FILE__));
	require_once(BASE_DIR . 'DB.class.php');
	require_once(BASE_DIR . 'Service.class.php');
	require_once(BASE_DIR . 'Util.class.php');

	define('MYSQL_HOST', '172.16.0.12');
	define('MYSQL_DB', 'home');
	define('MYSQL_USER', 'home');
	define('MYSQL_PASS', '');

	class MysqlSync extends Service{
		public function onRun(){
			// connect mysql
			$this->_db = new DB(MYSQL_HOST, MYSQL_DB, MYSQL_USER, MYSQL_PASS);

			while(true){
				if($this->propertyChanged('axpert')){
					Util::log("axpert params changed");
					$this->logAxpert($this->getProperty('axpert'));
					// mark property as read until it changes
					$this->markProperty('axpert');
				}

				if($this->propertyChanged('bms')){
					Util::log("bms params changed");
					$this->logBms($this->getProperty('bms'));
					$this->markProperty('bms');
				}

				sleep(1);
			}
		}

		public function logAxpert($result){
			// 20211124144504
			// -- QPIGS
			// 239.4 49.9 239.4 49.9 0478 0428 006 377 47.70
			// 10 - 001
			// 11 - 055
			// 12 - 0021 00.2 089.9 00.00 00000
			// 17 - 00010110
			// 18 - 00 00 00026 010
			// -- QMOD
			// 22 - L
			// -- QPIGS2
			// 00.1 090.0 00017
			// 
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

			$result = preg_replace('/[^A-Z0-9\s\.]/', '', $result);
			$values = explode(' ', $result);

			switch($values[21]){
				case 'P':	// power on
				case 'S':	// standby
				case 'L':	// line
				case 'B':	// battery
				case 'F':	// fault
				case 'H':	// power saving
					break;
				default:
					// error
					echo "Invalid mode value: " . $values[21] . "\n";
					return;
			}

			$last_date = $this->_db->query("SELECT date FROM `inverter` ORDER BY date DESC LIMIT 1")->getSingle();
			if(date('YmdHi') == date('YmdHi', strtotime($last_date))){
				$doUpdate = true;
			}

			$b = new DBQueryBuilder($doUpdate ? 'UPDATE `inverter`' : 'INSERT INTO `inverter`');
			if($doUpdate){
				$b->setUpdate();
				$b->where("date", $last_date);
			} else {
				$b->setInsert();
			}

			$b->addRecord([
				'date' => date('Y-m-d H:i:s'),
				'grid_voltage' => $values[0],
				'ac_out_voltage' => $values[2],
				'ac_out_power' => self::ltrim($values[4]),
				'output_load' => self::ltrim($values[6]),
				'batt_voltage' => self::ltrimf($values[8]),
				'batt_charge_current' => self::ltrim($values[9]),
				'inverter_temp' => self::ltrim($values[11]),
				'pv1_current' => self::ltrimf($values[12]),
				'pv1_voltage' => self::ltrimf($values[13]),
				'batt_discharge_current' => self::ltrim($values[15]),
				'pv_active' => self::ltrimf($values[13]) > 50 || self::ltrimf($values[23]) > 50,
				'charging_grid' => substr($values[16], 7, 1) == '1',
				'charging_pv' => substr($values[16], 6, 1) == '1',
				'pv_power' => self::ltrim($values[19]) + self::ltrim($values[24]),
				'mode' => $values[21],
				'pv2_current' => self::ltrimf($values[22]),
				'pv2_voltage' => self::ltrimf($values[23]),
				'output_priority' => ($out_source = self::ltrim($values[42])) == 0 ? 'USB' : ($out_source == 1 ? 'SUB' : 'SBU')
			]);

			$this->_db->query($b);
		}

		protected function logBms($values){
			$last_date = $this->_db->query("SELECT date FROM `bms` ORDER BY date DESC LIMIT 1")->getSingle();
			if(date('YmdHi') == date('YmdHi', strtotime($last_date))){
				$doUpdate = true;
			}

			$b = new DBQueryBuilder($doUpdate ? 'UPDATE `bms`' : 'INSERT INTO `bms`');
			if($doUpdate){
				$b->setUpdate();
				$b->where("date", $last_date);
			} else {
				$b->setInsert();
			}

			$b->addRecord([
				'date' => date('Y-m-d H:i:s'),
				'voltage' => $values->Voltage,
				'current' => $values->Current,
				'remaining_capacity' => $values->RemainingCapacity,
				'percent_capacity' => $values->PercentCapacity,
				'cell_0' => $values->Cells[0],
				'cell_1' => $values->Cells[1],
				'cell_2' => $values->Cells[2],
				'cell_3' => $values->Cells[3],
				'cell_4' => $values->Cells[4],
				'cell_5' => $values->Cells[5],
				'cell_6' => $values->Cells[6],
				'cell_7' => $values->Cells[7],
				'cell_8' => $values->Cells[8],
				'cell_9' => $values->Cells[9],
				'cell_10' => $values->Cells[10],
				'cell_11' => $values->Cells[11],
				'cell_12' => $values->Cells[12],
				'cell_13' => isset($values->Cells[13]) ? $values->Cells[13] : 0,
				'cell_min' => $values->CellMin,
				'cell_max' => $values->CellMax,
				'cell_diff' => $values->CellDiff,
				'temp1' => $values->Temps[0],
				'temp2' => $values->Temps[1]
			]);

			$this->_db->query($b);
		}
	}