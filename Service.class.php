<?php
	declare(ticks = 1);

	class Service{
		function __construct(){
			$shutdownfn = [$this, '__shutdown'];

			register_shutdown_function($shutdownfn);
			pcntl_signal(SIGINT, $shutdownfn);
			pcntl_signal(SIGTERM, $shutdownfn);

			// connect memcached
			try{
				$this->_mem = new Memcached();
				$this->_mem->addServer('127.0.0.1', 11211);
			} catch(Exception $ex){
				echo "[service] cannot connect to Memcached server!\n";
				return;	
			}

			// run service
			$this->onRun();
		}

		// store property in memcache
		protected function setProperty($prop, $val){
			$item = [
				'data' => $val,
				'created' => time(),
				'read' => false
			];

			$this->_mem->set($prop, json_encode($item));
		}

		protected function getProperty($prop){
			return ($item = $this->getPropertyRaw($prop)) ? $item['data'] : null;
		}

		// retrieve property from memcache
		protected function getPropertyRaw($prop){
			$json = $this->_mem->get($prop);
			if($json === null){
				return null;
			}

			$item = null;
			try{
				$item = json_decode($json);
			} catch(Exception $ex){
				return null;
			}

			return $item;
		}

		// returns if property has been changed
		protected function propertyChanged($prop){
			return ($item = $this->getPropertyRaw($prop)) && $item['read'] === false;
		}

		// sets property marked
		protected function markProperty($prop){
			if(($item = $this->getPropertyRaw($prop))){
				$item['read'] = true;
				$this->_mem->set($prop, json_encode($item));
				return true;
			}

			return false;
		}

		protected function onRun(){
			while(true){
				sleep(1);
			}
		}
		// triggered when services exits or is manually interrupted
		protected function onShutdown(){}

		// shutdown fn
		protected function __shutdown(){
			$this->_db = null;
			$this->onShutdown();
		}
	}