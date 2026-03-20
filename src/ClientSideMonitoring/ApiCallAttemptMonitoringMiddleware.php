<?php

declare (strict_types=1);
namespace Aws\Client_Side_Monitoring;

use Aws\Command_Interface;
use Aws\Credentials\Credentials_Interface;
use Aws\Exception\Aws_Exception;
use Aws\Response_Container_Interface;
use Aws\Result_Interface;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * @internal
 */
class Api_Call_Attempt_Monitoring_Middleware extends Abstract_Monitoring_Middleware
{
    /**
     * Standard middleware wrapper function with CSM options passed in.
     *
     * @param mixed  $options
     * @param string $region
     * @param string $service
     * @return callable
     */
    public static function wrap(callable $credential_provider, $options, $region, $service)
    {
        return fn(callable $handler) => new static($handler, $credential_provider, $options, $region, $service);
    }
    /**
     * {@inheritdoc}
     */
    public static function get_request_data(Request_Interface $request): array
    {
        return ['Fqdn' => $request->get_uri()->get_host()];
    }
    /**
     * {@inheritdoc}
     */
    public static function get_response_data($klass)
    {
        if ($klass instanceof Result_Interface) {
            return ['AttemptLatency' => self::get_result_attempt_latency($klass), 'DestinationIp' => self::get_result_destination_ip($klass), 'DnsLatency' => self::get_result_dns_latency($klass), 'HttpStatusCode' => self::get_result_http_status_code($klass), 'XAmzId2' => self::get_result_header($klass, 'x-amz-id-2'), 'XAmzRequestId' => self::get_result_header($klass, 'x-amz-request-id'), 'XAmznRequestId' => self::get_result_header($klass, 'x-amzn-RequestId')];
        }
        if ($klass instanceof Aws_Exception) {
            return ['AttemptLatency' => self::get_aws_exception_attempt_latency($klass), 'AwsException' => substr((string) self::get_aws_exception_error_code($klass), 0, 128), 'AwsExceptionMessage' => substr((string) self::get_aws_exception_message($klass), 0, 512), 'DestinationIp' => self::get_aws_exception_destination_ip($klass), 'DnsLatency' => self::get_aws_exception_dns_latency($klass), 'HttpStatusCode' => self::get_aws_exception_http_status_code($klass), 'XAmzId2' => self::get_aws_exception_header($klass, 'x-amz-id-2'), 'XAmzRequestId' => self::get_aws_exception_header($klass, 'x-amz-request-id'), 'XAmznRequestId' => self::get_aws_exception_header($klass, 'x-amzn-RequestId')];
        }
        if ($klass instanceof \Exception) {
            return ['HttpStatusCode' => self::get_exception_http_status_code($klass), 'SdkException' => substr((string) self::get_exception_code($klass), 0, 128), 'SdkExceptionMessage' => substr((string) self::get_exception_message($klass), 0, 512), 'XAmzId2' => self::get_exception_header($klass, 'x-amz-id-2'), 'XAmzRequestId' => self::get_exception_header($klass, 'x-amz-request-id'), 'XAmznRequestId' => self::get_exception_header($klass, 'x-amzn-RequestId')];
        }
        throw new \InvalidArgumentException('Parameter must be an instance of ResultInterface, AwsException or Exception.');
    }
    private static function get_result_attempt_latency(Result_Interface $result): ?int
    {
        if (isset($result['@metadata']['transferStats']['http'])) {
            $attempt = end($result['@metadata']['transferStats']['http']);
            if (isset($attempt['total_time'])) {
                return (int) floor($attempt['total_time'] * 1000);
            }
        }
        return null;
    }
    private static function get_result_destination_ip(Result_Interface $result)
    {
        if (isset($result['@metadata']['transferStats']['http'])) {
            $attempt = end($result['@metadata']['transferStats']['http']);
            if (isset($attempt['primary_ip'])) {
                return $attempt['primary_ip'];
            }
        }
        return null;
    }
    private static function get_result_dns_latency(Result_Interface $result): ?int
    {
        if (isset($result['@metadata']['transferStats']['http'])) {
            $attempt = end($result['@metadata']['transferStats']['http']);
            if (isset($attempt['namelookup_time'])) {
                return (int) floor($attempt['namelookup_time'] * 1000);
            }
        }
        return null;
    }
    private static function get_result_http_status_code(Result_Interface $result)
    {
        return $result['@metadata']['statusCode'];
    }
    private static function get_aws_exception_attempt_latency(Aws_Exception $e): ?int
    {
        $attempt = $e->get_transfer_info();
        if (isset($attempt['total_time'])) {
            return (int) floor($attempt['total_time'] * 1000);
        }
        return null;
    }
    private static function get_aws_exception_error_code(Aws_Exception $e)
    {
        return $e->get_aws_error_code();
    }
    private static function get_aws_exception_message(Aws_Exception $e)
    {
        return $e->get_aws_error_message();
    }
    private static function get_aws_exception_destination_ip(Aws_Exception $e)
    {
        $attempt = $e->get_transfer_info();
        return $attempt['primary_ip'] ?? null;
    }
    private static function get_aws_exception_dns_latency(Aws_Exception $e): ?int
    {
        $attempt = $e->get_transfer_info();
        if (isset($attempt['namelookup_time'])) {
            return (int) floor($attempt['namelookup_time'] * 1000);
        }
        return null;
    }
    private static function get_aws_exception_http_status_code(Aws_Exception $e)
    {
        $response = $e->get_response();
        if ($response !== null) {
            return $response->get_status_code();
        }
        return null;
    }
    private static function get_exception_http_status_code(\Exception $e)
    {
        if ($e instanceof Response_Container_Interface) {
            $response = $e->get_response();
            if ($response instanceof Response_Interface) {
                return $response->get_status_code();
            }
        }
        return null;
    }
    private static function get_exception_code(\Exception $e): ?string
    {
        if (!$e instanceof Aws_Exception) {
            return $e::class;
        }
        return null;
    }
    private static function get_exception_message(\Exception $e): ?string
    {
        if (!$e instanceof Aws_Exception) {
            return $e->get_message();
        }
        return null;
    }
    /**
     * {@inheritdoc}
     */
    protected function populate_request_event_data(Command_Interface $cmd, Request_Interface $request, array $event)
    {
        $event = parent::populate_request_event_data($cmd, $request, $event);
        $event['Type'] = 'ApiCallAttempt';
        return $event;
    }
    /**
     * {@inheritdoc}
     */
    protected function populate_result_event_data($result, array $event)
    {
        $event = parent::populate_result_event_data($result, $event);
        $provider = $this->credential_provider;
        /** @var CredentialsInterface $credentials */
        $credentials = $provider()->wait();
        $event['AccessKey'] = $credentials->get_access_key_id();
        $session_token = $credentials->get_security_token();
        if ($session_token !== null) {
            $event['SessionToken'] = $session_token;
        }
        if (empty($event['AttemptLatency'])) {
            $event['AttemptLatency'] = (int) (floor(microtime(true) * 1000) - $event['Timestamp']);
        }
        return $event;
    }
}