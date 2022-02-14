<?php
	class Util{
		public static function hex_dump($string){
			$hex = '';
			for ($i = 0; $i < strlen($string); $i++) {
			    $hex .= str_pad(dechex(ord($string[$i])), 2, '0', STR_PAD_LEFT) . ' ';
			}
			return $hex;
		}

		public static function log($msg){
			$line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
			echo $line;
			file_put_contents(dirname(__FILE__) . '/' . $argv[0] . '.log', $line, FILE_APPEND);
		}
	}