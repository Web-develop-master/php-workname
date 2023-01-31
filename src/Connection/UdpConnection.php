<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Workerman\Connection;

use Workerman\Protocols\ProtocolInterface;

/**
 * UdpConnection.
 */
class UdpConnection extends ConnectionInterface implements \JsonSerializable
{
    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public string $transport = 'udp';

    /**
     * Udp socket.
     *
     * @var resource
     */
    protected $socket;

    /**
     * Remote address.
     *
     * @var string
     */
    protected string $remoteAddress = '';

    /**
     * Construct.
     *
     * @param resource $socket
     * @param string $remoteAddress
     */
    public function __construct($socket, string $remoteAddress)
    {
        $this->socket = $socket;
        $this->remoteAddress = $remoteAddress;
    }

    /**
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return void|boolean
     */
    public function send(mixed $sendBuffer, bool $raw = false)
    {
        if (false === $raw && $this->protocol) {
            /** @var ProtocolInterface $parser */
            $parser = $this->protocol;
            $sendBuffer = $parser::encode($sendBuffer, $this);
            if ($sendBuffer === '') {
                return;
            }
        }
        return \strlen($sendBuffer) === \stream_socket_sendto($this->socket, $sendBuffer, 0, $this->isIpV6() ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort() : $this->remoteAddress);
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp(): string
    {
        $pos = \strrpos($this->remoteAddress, ':');
        if ($pos) {
            return \trim(\substr($this->remoteAddress, 0, $pos), '[]');
        }
        return '';
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort(): int
    {
        if ($this->remoteAddress) {
            return (int)\substr(\strrchr($this->remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * Get local IP.
     *
     * @return string
     */
    public function getLocalIp(): string
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return \substr($address, 0, $pos);
    }

    /**
     * Get local port.
     *
     * @return int
     */
    public function getLocalPort(): int
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)\substr(\strrchr($address, ':'), 1);
    }

    /**
     * Get local address.
     *
     * @return string
     */
    public function getLocalAddress(): string
    {
        return (string)@\stream_socket_get_name($this->socket, false);
    }


    /**
     * Close connection.
     *
     * @param mixed|null $data
     * @return void
     */
    public function close(mixed $data = null, bool $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        $this->eventLoop = $this->errorHandler = null;
    }

    /**
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }
    
    /**
     * Get the json_encode information.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'transport' => $this->transport,
            'getRemoteIp' => $this->getRemoteIp(),
            'remotePort' => $this->getRemotePort(),
            'getRemoteAddress' => $this->getRemoteAddress(),
            'getLocalIp' => $this->getLocalIp(),
            'getLocalPort' => $this->getLocalPort(),
            'getLocalAddress' => $this->getLocalAddress(),
            'isIpV4' => $this->isIpV4(),
            'isIpV6' => $this->isIpV6(),
        ];
    }
}
