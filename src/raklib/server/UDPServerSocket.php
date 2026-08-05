<?php



declare(strict_types=1);

namespace raklib\server;



use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_recvfrom;
use function socket_sendto;
use function socket_set_nonblock;
use function socket_set_option;
use function strlen;
use const AF_INET;
use const SO_RCVBUF;
use const SO_REUSEADDR;
use const SO_SNDBUF;
use const SOCK_DGRAM;
use const SOL_SOCKET;
use const SOL_UDP;

class UDPServerSocket{
	/** @var \ThreadedLogger */
	protected \ThreadedLogger $logger;
	/** @var \Socket */
	protected \Socket $socket;

	public function __construct(\ThreadedLogger $logger, int $port = 19132, string $interface = "0.0.0.0"){
		$this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		//socket_set_option($this->socket, SOL_SOCKET, SO_BROADCAST, 1); //Allow sending broadcast messages
		if(@socket_bind($this->socket, $interface, $port) === true){
			socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 0);
			$this->setSendBuffer(1024 * 1024 * 8)->setRecvBuffer(1024 * 1024 * 8);
		}else{
			$logger->critical("**** FAILED TO BIND TO " . $interface . ":" . $port . "!");
			$logger->critical("Perhaps a server is already running on that port?");
			exit(1);
		}
		socket_set_nonblock($this->socket);
	}

	public function getSocket() : \Socket{
		return $this->socket;
	}

	public function close() : void{
		socket_close($this->socket);
	}

	/**
	 * @param string &$buffer
	 * @param string &$source
	 * @param int    &$port
	 *
	 * @return int|false
	 */
	public function readPacket(&$buffer, &$source, &$port){
		return socket_recvfrom($this->socket, $buffer, 65535, 0, $source, $port);
	}

	public function writePacket(string $buffer, string $dest, int $port) : int|false{
		return socket_sendto($this->socket, $buffer, strlen($buffer), 0, $dest, $port);
	}

	public function setSendBuffer(int $size) : self{
		@socket_set_option($this->socket, SOL_SOCKET, SO_SNDBUF, $size);

		return $this;
	}

	public function setRecvBuffer(int $size) : self{
		@socket_set_option($this->socket, SOL_SOCKET, SO_RCVBUF, $size);

		return $this;
	}

}
