<?php

namespace Nbj;

use ZMQSocket;
use RuntimeException;
use ZMQSocketException;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;

/**
 * Class DelegationProxyConnection
 */
class DelegationProxyConnection
{
    /**
     * Holds the socket the DelegationProxy is connected to
     *
     * @var ZMQSocket $socket
     */
    protected $socket = null;

    /**
     * Holds the path to the local ipc socket
     *
     * @var string $dsn
     */
    protected $dsn = null;

    /**
     * Holds the service name once set
     *
     * @var string $serviceName
     */
    protected $serviceName = null;

    /**
     * DelegationProxy constructor.
     *
     * Private to force the developer to use connectTo()
     */
    private function __construct()
    {
        $this->dsn = sprintf(
            'tcp://%s:%s',
            env('DELEGATION_PROXY_HOST', 'engage-delegation-proxy'),
            env('DELEGATION_PROXY_PORT', 5557)
        );
    }

    public static function from($serviceName)
    {
        return (new static)
            ->setServiceName($serviceName);
    }

    /**
     * Creates a new instance of DelegationProxyConnection by connecting it to a socket
     *
     * @param ZMQSocket $socket
     *
     * @return DelegationProxyConnection
     *
     * @throws ZMQSocketException
     */
    public function connectTo(ZMQSocket $socket)
    {
        return $this
            ->setSocket($socket)
            ->establishConnection();
    }

    /**
     * Sets the socket for the connection
     *
     * @param ZMQSocket $socket
     *
     * @return $this
     */
    protected function setSocket(ZMQSocket $socket)
    {
        $this->socket = $socket;

        return $this;
    }

    /**
     * Sets the service name used in logs
     *
     * @param $name
     *
     * @return $this
     */
    protected function setServiceName($name)
    {
        $this->serviceName = $name;

        return $this;
    }

    /**
     * Establishes the connections to all the sockets
     *
     * @return $this
     *
     * @throws ZMQSocketException
     */
    protected function establishConnection()
    {
        // Bail-out if socket is already connected
        if ($this->isConnected()) {
            Log::info(sprintf('Service [%s] - Reconnecting socket', $this->serviceName));

            return;
        }

        try {
            $this->socket->connect($this->dsn);
            Log::info(sprintf('Service [%s] - Connecting socket to dsn [%s]', $this->serviceName, $this->dsn));

            // As it takes a small amount of time before
            // a socket accepts message after first connect
            // we need to sleep until the socket is ready
            usleep(200000);
        } catch (ZMQSocketException $exception) {
            $message = sprintf('Service [%s] - failed to connect socket to dsn [%s] - Exception: %s', $this->serviceName, $this->dsn, $exception->getMessage());

            Log::error($message);
        }

        return $this;
    }

    /**
     * Checks if the socket is connected
     *
     * @return bool
     *
     * @throws ZMQSocketException
     */
    public function isConnected()
    {
        $endpoints = collect($this->socket->getEndpoints())
            ->map(function (array $endpointGroup) {
                return collect($endpointGroup);
            });

        return (bool) $endpoints['connect']->isNotEmpty();
    }

    /**
     * Syntactic sugar for negating isConnected()
     *
     * @return bool
     *
     * @throws ZMQSocketException
     */
    public function isNotConnected()
    {
        return ! $this->isConnected();
    }

    /**
     * Sends a message through the socket
     *
     * @param $channel
     * @param $topic
     * @param $message
     *
     * @return $this
     *
     * @throws ZMQSocketException
     */
    public function sendMessage($channel, $topic, $message)
    {
        $this->guardAgainstNotConnected();
        $this->guardAgainstInvalidChannelOrTopic($channel, $topic);

        if ($this->isNotJson($message)) {
            $message = json_encode($message);
        }

        // Build and send zmq message over the socket
        $zmqMessage = [$channel, $topic, $message];
        $this->sendZmqMessage($zmqMessage);

        return $this;
    }

    /**
     * Sends the zmq message through the socket in multiple parts
     *
     * @param $message
     *
     * @throws RuntimeException
     */
    protected function sendZmqMessage($message)
    {
        try {
            $this->socket->sendMulti($message);
        } catch (ZMQSocketException $exception) {
            $message = sprintf('Service [%s] - failed sending message [%s] - Exception: %s', $this->serviceName, json_encode($message), $exception->getMessage());
            Log::error($message);

            throw new RuntimeException($message);
        }
    }

    /**
     * Guards against socket not being connected
     *
     * @throws RuntimeException
     * @throws ZMQSocketException
     */
    protected function guardAgainstNotConnected()
    {
        if ($this->isNotConnected()) {
            $message = sprintf('Service [%s] - Cannot send message, socket is not connected', $this->serviceName);
            Log::error($message);

            throw new RuntimeException($message);
        }
    }

    /**
     * Guards against channel and topic being invalid
     *
     * @param $channel
     * @param $topic
     *
     * @throws InvalidArgumentException
     */
    protected function guardAgainstInvalidChannelOrTopic($channel, $topic)
    {
        if ($this->isNotValid($channel)) {
            $message = sprintf('Service [%s] - Cannot send message, channel is invalid, expected non-empty string, [%s] given', $this->serviceName, gettype($channel));
            Log::error($message);

            throw new InvalidArgumentException($message);
        }

        if ($this->isNotValid($topic)) {
            $message = sprintf('Service [%s] - Cannot send message, topic is invalid, expected non-empty string, [%s] given', $this->serviceName, gettype($topic));
            Log::error($message);

            throw new InvalidArgumentException($message);
        }
    }

    /**
     * Checks if the input is valid
     * It must be:
     * - A string
     * - Not empty
     * - Not null
     *
     * @param mixed $input
     *
     * @return bool
     */
    protected function isNotValid($input)
    {
        return empty($input) || is_null($input) || !is_string($input);
    }

    /**
     * Checks if a message is json
     *
     * @param string $message
     *
     * @return bool
     */
    protected function isJson($message)
    {
        // We can't decode anything but a string, so we
        // bail-out here if message is not
        if (!is_string($message)) {
            return false;
        }

        return (bool) json_decode($message);
    }

    /**
     * Checks if a message is not json
     *
     * @param string $message
     *
     * @return bool
     */
    protected function isNotJson($message)
    {
        return ! $this->isJson($message);
    }
}
