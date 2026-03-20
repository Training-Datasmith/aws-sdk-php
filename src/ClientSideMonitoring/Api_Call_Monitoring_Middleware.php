<?php

declare (strict_types=1);
namespace Aws\Client_Side_Monitoring;

use Aws\Command_Interface;
use Aws\Exception\Aws_Exception;
use Aws\Monitoring_Events_Interface;
use Aws\Result_Interface;
use Psr\Http\Message\Request_Interface;
/**
 * @internal
 */
class Api_Call_Monitoring_Middleware extends Abstract_Monitoring_Middleware
{
    /**
     * Api Call Attempt event keys for each Api Call event key
     */
    private static array $event_keys = ['FinalAwsException' => 'AwsException', 'FinalAwsExceptionMessage' => 'AwsExceptionMessage', 'FinalSdkException' => 'SdkException', 'FinalSdkExceptionMessage' => 'SdkExceptionMessage', 'FinalHttpStatusCode' => 'HttpStatusCode'];
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
        return [];
    }
    /**
     * {@inheritdoc}
     */
    public static function get_response_data($klass)
    {
        if ($klass instanceof Result_Interface) {
            $data = ['AttemptCount' => self::get_result_attempt_count($klass), 'MaxRetriesExceeded' => 0];
        } elseif ($klass instanceof \Exception) {
            $data = ['AttemptCount' => self::get_exception_attempt_count($klass), 'MaxRetriesExceeded' => self::get_max_retries_exceeded($klass)];
        } else {
            throw new \InvalidArgumentException('Parameter must be an instance of ResultInterface or Exception.');
        }
        return $data + self::get_final_attempt_data($klass);
    }
    private static function get_result_attempt_count(Result_Interface $result): int
    {
        if (isset($result['@metadata']['transferStats']['http'])) {
            return count($result['@metadata']['transferStats']['http']);
        }
        return 1;
    }
    private static function get_exception_attempt_count(\Exception $e): int
    {
        $attempt_count = 0;
        if ($e instanceof Monitoring_Events_Interface) {
            foreach ($e->get_monitoring_events() as $event) {
                if (isset($event['Type']) && $event['Type'] === 'ApiCallAttempt') {
                    $attempt_count++;
                }
            }
        }
        return $attempt_count;
    }
    /**
     * @return mixed[]
     */
    private static function get_final_attempt_data(\Exception $klass): array
    {
        $data = [];
        if ($klass instanceof Monitoring_Events_Interface) {
            $final_attempt = self::get_final_attempt($klass->get_monitoring_events());
            if (!empty($final_attempt)) {
                foreach (self::$event_keys as $call_key => $attempt_key) {
                    if (isset($final_attempt[$attempt_key])) {
                        $data[$call_key] = $final_attempt[$attempt_key];
                    }
                }
            }
        }
        return $data;
    }
    private static function get_final_attempt(array $events)
    {
        for (end($events); key($events) !== null; prev($events)) {
            $current = current($events);
            if (isset($current['Type']) && $current['Type'] === 'ApiCallAttempt') {
                return $current;
            }
        }
        return null;
    }
    private static function get_max_retries_exceeded(\Exception $klass): int
    {
        if ($klass instanceof Aws_Exception && $klass->is_max_retries_exceeded()) {
            return 1;
        }
        return 0;
    }
    /**
     * {@inheritdoc}
     */
    protected function populate_request_event_data(Command_Interface $cmd, Request_Interface $request, array $event)
    {
        $event = parent::populate_request_event_data($cmd, $request, $event);
        $event['Type'] = 'ApiCall';
        return $event;
    }
    /**
     * {@inheritdoc}
     */
    protected function populate_result_event_data($result, array $event)
    {
        $event = parent::populate_result_event_data($result, $event);
        $event['Latency'] = (int) (floor(microtime(true) * 1000) - $event['Timestamp']);
        return $event;
    }
}