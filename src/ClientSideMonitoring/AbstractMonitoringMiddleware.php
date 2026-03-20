<?php

declare (strict_types=1);
namespace Aws\Client_Side_Monitoring;

use Aws\Command_Interface;
use Aws\Exception\Aws_Exception;
use Aws\Monitoring_Events_Interface;
use Aws\Response_Container_Interface;
use Aws\Result_Interface;
use Guzzle_Http\Promise;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * @internal
 */
abstract class Abstract_Monitoring_Middleware implements Monitoring_Middleware_Interface
{
    private static \Socket|bool|null $socket = null;
    private $next_handler;
    protected $credential_provider;
    protected static function get_aws_exception_header(Aws_Exception $e, $header_name)
    {
        $response = $e->get_response();
        if ($response !== null) {
            $header = $response->get_header($header_name);
            if (!empty($header[0])) {
                return $header[0];
            }
        }
        return null;
    }
    protected static function get_result_header(Result_Interface $result, $header_name)
    {
        return $result['@metadata']['headers'][$header_name] ?? null;
    }
    protected static function get_exception_header(\Exception $e, $header_name)
    {
        if ($e instanceof Response_Container_Interface) {
            $response = $e->get_response();
            if ($response instanceof Response_Interface) {
                $header = $response->get_header($header_name);
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
    public function __construct(callable $handler, callable $credential_provider, private $options, protected $region, protected $service)
    {
        $this->next_handler = $handler;
        $this->credential_provider = $credential_provider;
    }
    /**
     * Standard invoke pattern for middleware execution to be implemented by
     * child classes.
     *
     * @return Promise\PromiseInterface
     */
    public function __invoke(Command_Interface $cmd, Request_Interface $request)
    {
        $handler = $this->next_handler;
        $event_data = null;
        $enabled = $this->is_enabled();
        if ($enabled) {
            $cmd['@http']['collect_stats'] = true;
            $event_data = $this->populate_request_event_data($cmd, $request, $this->get_new_event($cmd, $request));
        }
        $g = function ($value) use ($event_data, $enabled) {
            if ($enabled) {
                $event_data = $this->populate_result_event_data($value, $event_data);
                $this->send_event_data($event_data);
                if ($value instanceof Monitoring_Events_Interface) {
                    $value->append_monitoring_event($event_data);
                }
            }
            if ($value instanceof \Exception || $value instanceof \Throwable) {
                return Promise\Create::rejection_for($value);
            }
            return $value;
        };
        return Promise\Create::promise_for($handler($cmd, $request))->then($g, $g);
    }
    private function get_client_id()
    {
        return $this->unwrapped_options()->get_client_id();
    }
    private function get_new_event(Command_Interface $cmd, Request_Interface $request): array
    {
        return ['Api' => $cmd->get_name(), 'ClientId' => $this->get_client_id(), 'Region' => $this->get_region(), 'Service' => $this->get_service(), 'Timestamp' => (int) floor(microtime(true) * 1000), 'UserAgent' => substr($request->get_header_line('User-Agent') . ' ' . \Aws\default_user_agent(), 0, 256), 'Version' => 1];
    }
    private function get_host()
    {
        return $this->unwrapped_options()->get_host();
    }
    private function get_port()
    {
        return $this->unwrapped_options()->get_port();
    }
    private function get_region()
    {
        return $this->region;
    }
    private function get_service()
    {
        return $this->service;
    }
    /**
     * Returns enabled flag from options, unwrapping options if necessary.
     *
     * @return bool
     */
    private function is_enabled()
    {
        return $this->unwrapped_options()->is_enabled();
    }
    /**
     * Returns $eventData array with information from the request and command.
     *
     * @return array
     */
    protected function populate_request_event_data(Command_Interface $cmd, Request_Interface $request, array $event)
    {
        $data_format = static::get_request_data($request);
        foreach ($data_format as $event_key => $value) {
            if ($value !== null) {
                $event[$event_key] = $value;
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
    protected function populate_result_event_data($result, array $event)
    {
        $data_format = static::get_response_data($result);
        foreach ($data_format as $event_key => $value) {
            if ($value !== null) {
                $event[$event_key] = $value;
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
    private function is_socket_created(): bool
    {
        // Before version 8, sockets are resources
        // After version 8, sockets are instances of Socket
        if (PHP_MAJOR_VERSION >= 8) {
            $socket_class = '\Socket';
            return self::$socket instanceof $socket_class;
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
    private function prepare_socket(bool $force_new_connection = false)
    {
        if (!$this->is_socket_created() || $force_new_connection || socket_last_error(self::$socket)) {
            self::$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_clear_error(self::$socket);
            socket_connect(self::$socket, $this->get_host(), $this->get_port());
        }
        return self::$socket;
    }
    /**
     * Sends formatted monitoring event data via the UDP socket connection to
     * the CSM agent endpoint.
     *
     * @return int
     */
    private function send_event_data(array $event_data): int|false
    {
        $socket = $this->prepare_socket();
        $datagram = json_encode($event_data);
        $result = socket_write($socket, $datagram, strlen($datagram));
        if ($result === false) {
            $this->prepare_socket(true);
        }
        return $result;
    }
    /**
     * Unwraps options, if needed, and returns them.
     *
     * @return ConfigurationInterface
     */
    private function unwrapped_options()
    {
        if (!$this->options instanceof Configuration_Interface) {
            try {
                $this->options = Configuration_Provider::unwrap($this->options);
            } catch (\Exception) {
                // Errors unwrapping CSM config defaults to disabling it
                $this->options = new Configuration(false, Configuration_Provider::DEFAULT_HOST, Configuration_Provider::DEFAULT_PORT);
            }
        }
        return $this->options;
    }
}