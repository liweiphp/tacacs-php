<?php
/**
 * Tacacs_Client is a sample TACACS+ client for authentication purposes.
 *
 * This source code is provided as a demostration for TACACS+ authentication.
 *
 * PHP version 5
 *
 * @category Authentication
 * @package  TacacsPlus
 * @author   Martín Claro <martin.claro@gmail.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://gitlab.com/martinclaro
 */
namespace TACACS;

use Psr\Log\LoggerInterface;
use TACACS\Common\Packet\Util;

/**
 * Client represents a TACACS+ Client.
 *
 * @category Authentication
 * @package  TacacsPlus
 * @author   Martín Claro <martin.claro@gmail.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://gitlab.com/martinclaro
 */
class Client
{
    /**
     * @var LoggerInterface
     */
    protected $logger = null;
    protected $addr = '127.0.0.1';
    protected $port = 49;
    protected $secret = 'secretkey';
    protected $socket = null;
    protected $socketTimeout = 2;
    protected $lastSeqNo = 0;

    protected $startPacketBuilder;
    protected $replyPacketBuilder;

    /**
     * Class construct
     *
     * @param LoggerInterface $logger The logger
     */
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;

        $this->log(__CLASS__." created.");
    }

    /**
     * Set server
     *
     * @param string $addr   The addr
     * @param string $port   The port
     * @param string $secret The secret
     *
     * @return void
     */
    public function setServer($addr, $port, $secret)
    {
        $this->addr = $addr;
        $this->port = $port;
        $this->secret = $secret;
    }

    /**
     * Set socket timeout
     *
     * @param string $sec Timeout in seconds
     *
     * @return void
     */
    public function setTimeout($sec)
    {
        $this->socketTimeout = $sec;
    }

    /**
     * Authenticate
     *
     * @param string $username The username
     * @param string $password The password
     * @param string $port     The port
     * @param string $addr     The addr
     *
     * @return boolean
     */
    public function authenticate($username, $password, $port = null, $addr = null)
    {
        if ($this->connect()) {
            $sessionId = $this->genSessionId();
            $this->lastSeqNo = 1;

            // START
            $builder = $this->getStartPacketBuilder();
            $builder->setSecret($this->secret);
            $builder->setUsername($username);
            $builder->setPassword($password);
            $builder->setPort($port);
            $builder->setRemoteAddress($addr);
            $builder->setSequenceNumber($this->lastSeqNo);
            $builder->setSessionId($sessionId);
            $start = $builder->build();
            $this->send($start);
            $this->log(print_r($start, true));

            // REPLY
            $reply = $this->recv();
            $this->log(print_r($reply, true));


            $this->lastSeqNo = $reply->getHeader()->getSequenceNumber();

            if ($reply->getBody()->getStatus() == TAC_PLUS_AUTHEN_STATUS_PASS) {
                return true;
            } elseif ($reply->getBody()->getStatus() == TAC_PLUS_AUTHEN_STATUS_ERROR) {
                return false;
            } elseif ($reply->getBody()->getStatus() == TAC_PLUS_AUTHEN_STATUS_FAIL) {
                return false;
            } else {
                $this->log(
                    'authenticate() failed: reason: unsupported CONTINUE packet flow or invalid shared key.'
                );
                return false;
            }

            $this->disconnect();
        } else {
            $this->log(
                'authenticate() failed: reason: unable to connect to TACACS+ server.'
            );
            return false;
        }
    }

    /**
     * Get Start packet builder
     *
     * @return StartPacketBuilder
     */
    protected function getStartPacketBuilder()
    {
        if (!$this->startPacketBuilder) {
            $this->startPacketBuilder = new \TACACS\Authentication\Builder\StartPacketBuilder();
        }
        return $this->startPacketBuilder;
    }

    /**
     * Get Reply packet builder
     *
     * @return ReplyPacketBuilder
     */
    protected function getReplyPacketBuilder()
    {
        if (!$this->replyPacketBuilder) {
            $this->replyPacketBuilder = new \TACACS\Authentication\Builder\ReplyPacketBuilder();
        }
        return $this->replyPacketBuilder;
    }

    /**
     * Connect
     *
     * @return boolean
     */
    protected function connect()
    {
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            $this->log(
                "socket_create() failed: reason: " .
                socket_strerror(socket_last_error()) . ""
            );
            return false;
        }

        $sec = (int)floor($this->socketTimeout);
        $usec = (int)floor(($this->socketTimeout - $sec) * 1000000);

        $result = socket_set_option(
            $this->socket,
            SOL_SOCKET,
            SO_RCVTIMEO,
            array('sec' => $sec, 'usec' => $usec)
        );
        if ($result === false) {
            $this->log(
                "socket_set_option() failed: reason: " .
                socket_strerror(socket_last_error($this->socket)) . ""
            );
            return false;
        }

        $result = socket_set_option(
            $this->socket,
            SOL_SOCKET,
            SO_SNDTIMEO,
            array('sec' => $sec, 'usec' => $usec)
        );
        if ($result === false) {
            $this->log(
                "socket_set_option() failed: reason: " .
                socket_strerror(socket_last_error($this->socket)) . ""
            );
            return false;
        }

        $result = @socket_connect($this->socket, $this->addr, $this->port);
        if ($result === false) {
            $this->log(
                "socket_connect() failed: reason: " .
                socket_strerror(socket_last_error($this->socket)) . ""
            );
            return false;
        }

        return true;
    }

    /**
     * Disconnect
     *
     * @return void
     */
    protected function disconnect()
    {
        @socket_close($this->socket);
        $this->log("Disconnected!");
    }

    /**
     * Send
     *
     * @param Packet $packet The packet
     *
     * @return void
     */
    protected function send($packet)
    {
        $this->log("Sending TACACS+ message... ");

        $data = $packet->toBinary();

        @socket_write($this->socket, $data, Util::binaryLength($data));
        $this->log("DONE (wrote ". Util::binaryLength($data) ." bytes)!");

        $unpackMask = 'H' . TAC_PLUS_HDR_SIZE . 'header/H*body';
        $unpack = unpack($unpackMask, $data);
        $unpackHeader = $unpack['header'];
        $unpackBody = $unpack['body'];

        $this->log("SENT: ". implode($unpack));
        $this->log("SENT (Header): ". $unpackHeader);
        $this->log("SENT (Body): " . $unpackBody);
    }

    /**
     * Recv
     *
     * @return string
     */
    protected function recv()
    {
        $this->log("Reading TACACS+ response... ");

        $out = null;
        $bytes = 0;
        if (false !== ($bytes = @socket_recv($this->socket, $out, 2048, MSG_WAITALL))) {
            $this->log("DONE (read $bytes bytes)!");
        } else {
            $this->log("ERROR READING SOCKET!");
        }

        $unpackMask = 'H' . TAC_PLUS_HDR_SIZE . 'header/H*body';
        $unpack = unpack($unpackMask, $out);
        $unpackHeader = $unpack['header'];
        $unpackBody = $unpack['body'];

        $this->log("RECV: ". implode($unpack));
        $this->log("RECV (Header): ". $unpackHeader);
        $this->log("RECV (Body): " . $unpackBody);

        $builder = $this->getReplyPacketBuilder();
        $builder->setSecret($this->secret);
        $reply = $builder->build();
        $reply->parseBinary($out);

        return $reply;
    }

    /**
     * Generates a session id
     *
     * @return string
     */
    protected function genSessionId()
    {
        mt_srand();
        return mt_rand(1, (pow(2, 16)-1));
    }

    /**
     * Log
     *
     * @param mixed $obj The record to log
     *
     * @return void
     */
    protected function log($obj = "")
    {
        if ($this->logger) {
            $this->logger->debug($obj);
        }
    }

    /**
     * Set logger
     *
     * @param LoggerInterface $logger The logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}
