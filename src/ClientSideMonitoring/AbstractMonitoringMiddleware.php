<?php

declare(strict_types=1);

namespace Aws\ClientSideMonitoring;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MonitoringEventsInterface;
use Aws\ResponseContainerInterface;
use Aws\ResultInterface;
use GuzzleHttp\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
abstract class AbstractMonitoringMiddleware implements MonitoringMiddlewareInterface
{
    private static \Socket|bool|null $socket = null;

    private $nextHandler;
    protected $credentialProvider;

    protected static function getAwsExceptionHeader(AwsException $e, $headerName)
    {
        $response = $e->getResponse();
        if ($response !== null) {
            $header = $response->getHeader($headerName);
            if (!empty($header[0])) {
                return $header[0];
            }
        }
        return null;
    }

    protected static function getResultHeader(ResultInterface $result, $headerName)
    {
        return $result['@metadata']['headers'][$headerName] ?? null;
    }

    protected static function getExceptionHeader(\Exception $e, $headerName)
    {
        if ($e instanceof ResponseContainerInterface) {
            $response = $e->getResponse();
            if ($response instanceof ResponseInterface) {
                $header = $response->getHeader($headerName);
                if (!empty($header[0])) {
                    return $header[0];
                }
            }
        }
        return null;
    }

    /**
     * Constructor stores the passed in handler and options.
     *
     * @param $options
     * @param $region
     * @param $service
     */
    public function __construct(
        callable $handler,
        callable $credentialProvider,
        private $options,
        protected $region,
        protected $service
    ) {
        $this->nextHandler = $handler;
        $this->credentialProvider = $credentialProvider;
    }

    /**
     * Standard invoke pattern for middleware execution to be implemented by
     * child classes.
     *
     * @return Promise\PromiseInterface
     */
    public function __invoke(CommandInterface $cmd, RequestInterface $request)
    {
        $handler = $this->nextHandler;
        $eventData = null;
        $enabled = $this->isEnabled();

        if ($enabled) {
            $cmd['@http']['collect_stats'] = true;
            $eventData = $this->populateRequestEventData(
                $cmd,
                $request,
                $this->getNewEvent($cmd, $request)
            );
        }

        $g = function ($value) use ($eventData, $enabled) {
            if ($enabled) {
                $eventData = $this->populateResultEventData(
                    $value,
                    $eventData
                );
                $this->sendEventData($eventData);

                if ($value instanceof MonitoringEventsInterface) {
                    $value->appendMonitoringEvent($eventData);
                }
            }
            if ($value instanceof \Exception || $value instanceof \Throwable) {
                return Promise\Create::rejectionFor($value);
            }
            return $value;
        };

        return Promise\Create::promiseFor($handler($cmd, $request))->then($g, $g);
    }

    private function getClientId()
    {
        return $this->unwrappedOptions()->getClientId();
    }

    private function getNewEvent(
        CommandInterface $cmd,
        RequestInterface $request
    ): array {
        return [
            'Api' => $cmd->getName(),
            'ClientId' => $this->getClientId(),
            'Region' => $this->getRegion(),
            'Service' => $this->getService(),
            'Timestamp' => (int) floor(microtime(true) * 1000),
            'UserAgent' => substr(
                $request->getHeaderLine('User-Agent') . ' ' . \Aws\default_user_agent(),
                0,
                256
            ),
            'Version' => 1,
        ];
    }

    private function getHost()
    {
        return $this->unwrappedOptions()->getHost();
    }

    private function getPort()
    {
        return $this->unwrappedOptions()->getPort();
    }

    private function getRegion()
    {
        return $this->region;
    }

    private function getService()
    {
        return $this->service;
    }

    /**
     * Returns enabled flag from options, unwrapping options if necessary.
     *
     * @return bool
     */
    private function isEnabled()
    {
        return $this->unwrappedOptions()->isEnabled();
    }

    /**
     * Returns $eventData array with information from the request and command.
     *
     * @return array
     */
    protected function populateRequestEventData(
        CommandInterface $cmd,
        RequestInterface $request,
        array $event
    ) {
        $dataFormat = static::getRequestData($request);
        foreach ($dataFormat as $eventKey => $value) {
            if ($value !== null) {
                $event[$eventKey] = $value;
            }
        }
        return $event;
    }

    /**
     * Returns $eventData array with information from the response, including
     * the calculation for attempt latency.
     *
     * @param ResultInterface|\Exception $result
     * @return array
     */
    protected function populateResultEventData(
        $result,
        array $event
    ) {
        $dataFormat = static::getResponseData($result);
        foreach ($dataFormat as $eventKey => $value) {
            if ($value !== null) {
                $event[$eventKey] = $value;
            }
        }
        return $event;
    }

    /**
     * Checks if the socket is created. If PHP version is greater or equals to 8 then,
     * it will check if the var is instance of \Socket otherwise it will check if is
     * a resource.
     *
     * @return bool Returns true if the socket is created, false otherwise.
     */
    private function isSocketCreated(): bool
    {
        // Before version 8, sockets are resources
        // After version 8, sockets are instances of Socket
        if (PHP_MAJOR_VERSION >= 8) {
            $socketClass = '\Socket';
            return self::$socket instanceof $socketClass;
        }
        return is_resource(self::$socket);
    }

    /**
     * Creates a UDP socket resource and stores it with the class, or retrieves
     * it if already instantiated and connected. Handles error-checking and
     * re-connecting if necessary. If $forceNewConnection is set to true, a new
     * socket will be created.
     *
     * @return Resource
     */
    private function prepareSocket(bool $forceNewConnection = false)
    {
        if (!$this->isSocketCreated()
            || $forceNewConnection
            || socket_last_error(self::$socket)
        ) {
            self::$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_clear_error(self::$socket);
            socket_connect(self::$socket, $this->getHost(), $this->getPort());
        }

        return self::$socket;
    }

    /**
     * Sends formatted monitoring event data via the UDP socket connection to
     * the CSM agent endpoint.
     *
     * @return int
     */
    private function sendEventData(array $eventData): int|false
    {
        $socket = $this->prepareSocket();
        $datagram = json_encode($eventData);
        $result = socket_write($socket, $datagram, strlen($datagram));
        if ($result === false) {
            $this->prepareSocket(true);
        }
        return $result;
    }

    /**
     * Unwraps options, if needed, and returns them.
     *
     * @return ConfigurationInterface
     */
    private function unwrappedOptions()
    {
        if (!($this->options instanceof ConfigurationInterface)) {
            try {
                $this->options = ConfigurationProvider::unwrap($this->options);
            } catch (\Exception) {
                // Errors unwrapping CSM config defaults to disabling it
                $this->options = new Configuration(
                    false,
                    ConfigurationProvider::DEFAULT_HOST,
                    ConfigurationProvider::DEFAULT_PORT
                );
            }
        }
        return $this->options;
    }
}
