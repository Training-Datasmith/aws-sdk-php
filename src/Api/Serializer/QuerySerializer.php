<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\Service;
use Aws\Command_Interface;
use Aws\Endpoint_V2\Endpoint_Provider_V2;
use Aws\Endpoint_V2\Endpoint_V2serializer_Trait;
use Aws\Endpoint_V2\Ruleset\Ruleset_Endpoint;
use Guzzle_Http\Psr7\Request;
use Psr\Http\Message\Request_Interface;
/**
 * Serializes a query protocol request.
 * @internal
 */
class Query_Serializer
{
    use Endpoint_V2serializer_Trait;
    private $endpoint;
    private $param_builder;
    public function __construct(private Service $api, $endpoint, ?callable $param_builder = null)
    {
        $this->endpoint = $endpoint;
        $this->param_builder = $param_builder ?: new Query_Param_Builder();
    }
    /**
     * When invoked with an AWS command, returns a serialization array
     * containing "method", "uri", "headers", and "body" key value pairs.
     *
     * @param CommandInterface $command Command to serialize into a request.
     * @param null $endpoint Endpoint resolved using EndpointProviderV2
     * @return RequestInterface
     */
    public function __invoke(Command_Interface $command, $endpoint = null)
    {
        $operation = $this->api->get_operation($command->get_name());
        $body = ['Action' => $command->get_name(), 'Version' => $this->api->get_metadata('apiVersion')];
        $command_args = $command->to_array();
        // Only build up the parameters when there are parameters to build
        if ($command_args) {
            $body += call_user_func($this->param_builder, $operation->get_input(), $command_args);
        }
        $body = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
        $headers = ['Content-Length' => strlen($body), 'Content-Type' => 'application/x-www-form-urlencoded'];
        $request_uri = $operation['http']['requestUri'] ?? null;
        if ($endpoint instanceof Ruleset_Endpoint) {
            $this->set_endpoint_v2request_options($endpoint, $headers);
        }
        $absolute_uri = str_ends_with((string) $this->endpoint, '/') ? $this->endpoint : $this->endpoint . $request_uri;
        return new Request('POST', $absolute_uri, $headers, $body);
    }
}