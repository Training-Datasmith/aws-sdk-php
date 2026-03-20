<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\Service;
use Aws\Command_Interface;
use Aws\Endpoint_V2\Endpoint_V2serializer_Trait;
use Aws\Endpoint_V2\Ruleset\Ruleset_Endpoint;
use Guzzle_Http\Psr7\Request;
use Psr\Http\Message\Request_Interface;
/**
 * Prepares a JSON-RPC request for transfer.
 * @internal
 */
class Json_Rpc_Serializer
{
    use Endpoint_V2serializer_Trait;
    private \Aws\Api\Serializer\Json_Body $json_formatter;
    /** @var string */
    private $endpoint;
    /** @var string */
    private $content_type;
    /**
     * @param Service  $api           Service description
     * @param string   $endpoint      Endpoint to connect to
     * @param JsonBody $jsonFormatter Optional JSON formatter to use
     */
    public function __construct(private Service $api, $endpoint, ?Json_Body $json_formatter = null)
    {
        $this->endpoint = $endpoint;
        $this->json_formatter = $json_formatter ?: new Json_Body($this->api);
        $this->content_type = Json_Body::get_content_type($this->api);
    }
    /**
     * When invoked with an AWS command, returns a serialization array
     * containing "method", "uri", "headers", and "body" key value pairs.
     *
     * @param CommandInterface $command Command to serialize into a request.
     * @param $endpointProvider Provider used for dynamic endpoint resolution.
     * @param $clientArgs Client arguments used for dynamic endpoint resolution.
     *
     * @return RequestInterface
     */
    public function __invoke(Command_Interface $command, $endpoint = null)
    {
        $operation_name = $command->get_name();
        $operation = $this->api->get_operation($operation_name);
        $command_args = $command->to_array();
        $body = $this->json_formatter->build($operation->get_input(), $command_args);
        $headers = ['X-Amz-Target' => $this->api->get_metadata('targetPrefix') . '.' . $operation_name, 'Content-Type' => $this->content_type, 'Content-Length' => strlen($body)];
        if ($endpoint instanceof Ruleset_Endpoint) {
            $this->set_endpoint_v2request_options($endpoint, $headers);
        }
        $request_uri = $operation['http']['requestUri'] ?? null;
        $absolute_uri = str_ends_with($this->endpoint, '/') ? $this->endpoint : $this->endpoint . $request_uri;
        return new Request($operation['http']['method'], $absolute_uri, $headers, $body);
    }
}