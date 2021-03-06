<?PHP
require_once 'lib/base.class.php';
require_once 'lib/commandInterface.php';
require_once 'lib/eventInterface.php';

class connection extends base {
	
	var $socket, $ex, $config;
	var $run = true;

	function __construct($config) {
		$this->config = $config;
		$this->connect();
		$this->login();
		$this->joinChannel();
		$this->mainLoop();
	}

	function connect() {
		$this->socket = fsockopen($this->config['server'], $this->config['port']);
	}

	function sendData($cmd, $msg = null) {
		
		if($msg == null) {
			fputs($this->socket, $cmd . "\r\n");
		} else {
			fputs($this->socket, $cmd . ' ' . $msg . "\r\n");
		}
		
	}

	function joinChannel() {

		if(is_array($this->config['channels'])) {
			
			foreach($this->config['channels'] as $single_channel) {
				$this->sendData('JOIN', $single_channel);
			}

		} else {
			$this->sendData('JOIN', $this->config['channels']);
		}

	}

	function protectUser($user = '') {

		if ($user == '') {
			$user = strstr($this->ex[0], '!', true);
		}

		$this->sendData('MODE', $this->ex[2] . ' +a ' . $user);
	}

	function login() {
		$this->sendData('USER', $this->config['nick'] . ' irc.orbsix.com ' . $this->config['nick'] . ' :' . $this->config['name']);
		$this->sendData('NICK', $this->config['nick']);
		
		do{
			// We need  wait until the MOTD is done before continuing
			$data = fgets($this->socket, 128);
		} while (strpos($data, '/MOTD') == false);

	}

	function doCommand($channel, $command, $params) {
		$command = $this->removeCRLF($command);
		
		if (file_exists("lib/commands/$command/$command.class.php")) {
			require_once "lib/commands/$command/$command.class.php";
			$commandObj = new $command($channel, $params);
			
			if ($commandObj instanceof icommand) {
				$commandObj->handleCommand($channel, $params);
				$response = $commandObj->getResponse();

				if(is_array($response)) {

					foreach($response as $single_response) {
						$this->sendData($single_response);
					}

				} else {
					$this->sendData($response);
				}

			} else {
				$this->logger($event.'.class.php does not conform to its interface.');
			}

		} else {
			$this->logger("lib/commands/$command/$command.class.php does not exist.");
		}

	}

	function handleEvents() {
		$strip_chars = array("\r", "\n");
        $event = str_replace($strip_chars, '', $this->ex[1]);
        
		if (file_exists("lib/events/$event/$event.class.php")) {
            require_once "lib/events/$event/$event.class.php";
            $eventObj = new $event();
            
			if ($eventObj instanceof ievent) {
				$eventObj->handleEvent($this->ex);
				$response = $eventObj->getResponse();
				
				if(is_array($response)) {

					foreach($response as $single_response) {
						$this->sendData($single_response);
					}

				} else {
					$this->sendData($response);
				}

            } else {
				$this->logger($event.'.class.php does not conform to its interface.');
			}
        
		} else {
            $this->logger("lib/events/$event/$event.class.php does not exist.");
        }

	}

	function parseStream() {
		$command = @ltrim($this->ex[3], ':');
		
		if (substr($command, 0, 1) == $this->config['command_start']) {
			$command = ltrim($command, $this->config['command_start']);
			$params = array_slice($this->ex, 4);
			$channel = $this->ex[2];
			$this->logger('COMMAND: ' . $command);
			
			foreach ($params as $param) {
				$this->logger("\tPARAM: " . $param);
			}
			
			$this->doCommand($channel, $command, $params);
		}
		
	}

	function mainLoop() {

		do {
			$data = fgets($this->socket, 128);
			$this->ex = explode(' ', $data);
			
			if ($this->ex[0] == 'PING') {
				$this->sendData('PONG', $this->ex[1]);
			}

			$this->handleEvents();
			$this->parseStream();
			
			unset($data);
		} while ($this->run);

	}

}

?>
