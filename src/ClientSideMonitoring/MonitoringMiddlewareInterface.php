<?php

declare (strict_types=1);
namespace Aws\Client_Side_Monitoring;

use Aws\Command_Interface;
use Aws\Exception\Aws_Exception;
use Aws\Result_Interface;
use Psr\Http\Message\Request_Interface;
/**
 * @internal
 */
interface Monitoring_Middleware_Interface
{
    /**
     * Data for event properties to be sent to the monitoring agent.
     *
     * @return array
     */
    public static function get_request_data(Request_Interface $request);
    /**
     * Data for event properties to be sent to the monitoring agent.
     *
     * @param ResultInterface|AwsException|\Exception $klass
     * @return array
     */
    public static function get_response_data($klass);
    public function __invoke(Command_Interface $cmd, Request_Interface $request);
}