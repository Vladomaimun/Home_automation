<?php

const S_PORT_NAME = '/dev/ttyACM0';
const BAUD_RATE = 9600;
const READ_REQUEST = '?';
const DELAY = 50000;
const DB_USERNAME = 'kompir';
const DB_PASS = 'chumbedrum420';
const DB_NAME = 'home_automation';
const VALID_NAME = '([a-zA-Z][a-zA-Z0-9]*)';
const VALID_PERMISSIONS = '([RWN]|RW)';

class Comm_protocol_action
{
	public $command;
	public $IP;
	public $type;
	public $reply;
	public $module;

	function command_type()
	{
		switch($this->get_param())
		{
			case 'H':
				return 'send_IP';
				break;
			case 'I':
				return 'new_module';
				break;
			case 'D':
				return 'var_define';
				break;
			case 'W':
				return 'db_write';
				break;
			case 'R':
				return 'db_read';
				break;
			default:
				return 1;
		}
	}

	function new_module()
	{
		global $db;
		$new_module_name=$this->get_param();
		if($new_module_name===-1)
			$this->error(10);
		else
		{
			if(preg_match(VALID_NAME, $new_module_name)==0 || strlen($new_module_name)>255)
			{
				$this->error(12);
			}
			else
			{
				$modules_with_this_name=$db->query("SELECT * FROM modules WHERE name='".$new_module_name."'");
				if($modules_with_this_name->num_rows!=0)
					$this->error(7);
				else
				{
					$db->query("CREATE TABLE `home_automation`.`".$new_module_name."` ( `id` INT NOT NULL AUTO_INCREMENT , `variable_name` VARCHAR(255) NOT NULL , `permissions` VARCHAR(2) NOT NULL , `value` VARCHAR(32767) NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;");
					$db->query("INSERT INTO `modules` (`id`, `name`, `IP`) VALUES (NULL, '".$new_module_name."', '".$this->IP."');");
					$this->reply='###'.$this->IP.';A;Valid;###';
				}
			}
		}
	}

	function db_write()
	{
		global $db;
		/*
		1 parse
		2 check if exists
		3 check permissions
		4 write
		*/
	}

	function db_read()
	{
		global $db;
	}

	function var_define()
	{
		global $db;
		$new_variable_name;
		$permissions;
		$declaration=$this->get_param();
		if($declaration===-1)
			$this->error(10);
		else
		{
			$this->reply='###'.$this->IP.';A;';
			do
			{
				if(!$this->verify_declaration($declaration, $new_variable_name, $permissions))
				{
					$db->query('INSERT INTO '.$this->module." (variable_name, permissions) VALUES ('".$new_variable_name."', '".$permissions."');");
					$this->reply.='Valid;';
				}
				else
					$this->reply.='Error;';
				$declaration=$this->get_param();
			}
			while(!($declaration===-1));
			$this->reply.='###';
		}
	}

	function verify_declaration(&$declaration, &$new_variable_name, &$permissions)
	{
		global $db;
		$separator_position=strpos($declaration, '@');
		if($separator_position===FALSE)
		{
			$this->error(2);
			return -2;
		}
		$new_variable_name=substr($declaration, 0, $separator_position);
		$permissions=substr($declaration, $separator_position+1);
		if(preg_match(VALID_PERMISSIONS, $permissions)==0)
		{
			$this->error(9);
			return -9;
		}
		if(preg_match(VALID_NAME, $new_variable_name)==0 || strlen($new_variable_name)>255)
		{
			$this->error(11);
			return -11;
		}
		if(!isset($this->module))
		{
			$this->error(14);
			return -14;
		}
		$variables_with_this_name=$db->query("SELECT * FROM ".$this->module." WHERE variable_name='".$new_variable_name."';");
		if($variables_with_this_name->num_rows!=0)
		{
			$this->error(8);
			return -8;
		}
		return 0;
	}

	function error($code)
	{
		switch($code)
		{
			case 1:
				$error_msg='Message corrupted or invalid';
				break;
			case 2:
				$error_msg='Invalid syntax';
				break;
			case 3:
				$error_msg='Permission denied';
				break;
			case 4:
				$error_msg='Unknown command';
				break;
			case 5:
				$error_msg='Nonexistent module';
				break;
			case 6:
				$error_msg='Nonexistent varriable';
				break;
			case 7:
				$error_msg='Module already exists';
				break;
			case 8:
				$error_msg='Varriable already exists';
				break;
			case 9:
				$error_msg='Invalid permission declaration';
				break;
			case 10:
				$error_msg='Missing argument';
				break;
			case 11:
				$error_msg='Invalid variable name';
				break;
			case 12:
				$error_msg='Invalid module name';
				break;
			case 14:
				$error_msg='Unrecognized modle';
				break;
			default:
				$error_msg='Unknown error';
				$code=13;
		}
		echo '###'.$this->IP.';E;ERROR '.$code.': '.$error_msg.';###';	//$arduino->write
	}

	function get_module_name()
	{
		global $db;
		$db_result=$db->query("SELECT * FROM modules WHERE ip='".$this->IP."'");
		if($db_result->num_rows==1)
		{
			$module_name=$db_result->fetch_assoc();
			$this->module=$module_name['name'];
		}
	}

	function send_IP()
	{
		$this->reply='###'.$this->IP.';H;'.$_SERVER['SERVER_ADDR'].';###';
	}

	function get_param()
	{
		$p=strpos($this->command, ';');
		if($p===FALSE)
			return -1;
		else
		{
			$s=substr($this->command, 0, $p);
			$this->command=substr($this->command, $p+1);
			return $s;
		}
	}

	function verify_command()
	{
		global $db;
		$this->command=$db->real_escape_string($this->command);
		if(strcmp(substr($this->command, 0, 3), substr($this->command, -3))==0)
			return 0;
		else
			return 1;
	}

	function get_IP()
	{
		$this->command=substr($this->command, 3, -3);
		$this->IP=$this->get_param();
	}

	//Добави проверки за имена и стойности!
}

class Serial
{
	public $conn;

	function init($port_name)
	{
		$ser_port_opt=array('baud'=>BAUD_RATE, 'bits'=>8, 'stop'=>1, 'parity'=>0);
		$this->conn=dio_open(S_PORT_NAME, O_RDWR);
		dio_tcsetattr($this->conn, $ser_port_opt);
		//usleep(2000);
	}

	function end()
	{
		dio_close($this->conn);
	}

	function read(&$output)
	{
		dio_write($this->conn, READ_REQUEST);
		usleep(DELAY);
		$l=dio_read($this->conn,4);
		if($l==0)
			return -1;
		$output=dio_read($this->conn, $l);
		return $l;
	}

	function write(&$input)
	{
		usleep(DELAY);
		dio_write($this->conn, $input, strlen($input));
	}

	function cancur()
	{
		dio_write($this->conn, '0');
		usleep(DELAY);
		echo dio_read($this->conn, 100);
	}
}

?>