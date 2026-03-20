<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Aws\Endpoint_V2\Endpoint_V2serializer_Trait;
use Aws\Endpoint_V2\Ruleset\Ruleset_Endpoint;
use DateTimeInterface;
use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Request;
use Guzzle_Http\Psr7\Uri;
use Psr\Http\Message\Request_Interface;
/**
 * Base implementation for Smithy RPC V2 protocol serializers.
 *
 * Implementers MUST override the defaultHeader property to represent
 * protocol-specific default header values:
 *   self::HEADER_SMITHY_PROTOCOL => static::SMITHY_PROTOCOL,
 *   self::HEADER_CONTENT_TYPE => static::DEFAULT_CONTENT_TYPE,
 *   self::HEADER_ACCEPT => static::DEFAULT_ACCEPT
 *
 * Implementers must also implement `serialize()`, `resolveBlob()`, and `resolveTimestamp()
 * according to their respective protocol specifications.
 *
 * @internal
 */
abstract class Abstract_Rpc_V2serializer
{
    use Endpoint_V2serializer_Trait;
    protected const HEADER_SMITHY_PROTOCOL = 'Smithy-Protocol';
    protected const HEADER_CONTENT_TYPE = 'Content-Type';
    protected const HEADER_ACCEPT = 'Accept';
    protected static array $default_headers;
    private string|Uri $endpoint;
    private bool $is_use_endpoint_v2;
    /**
     * @param Service $api Service API description
     * @param string $endpoint Endpoint to connect to
     */
    public function __construct(private Service $api, string|Uri $endpoint)
    {
        $this->endpoint = Psr7\Utils::uri_for($endpoint);
    }
    /**
     * @param CommandInterface $command Command to serialize into a request.
     * @param mixed|null $endpoint
     *
     * @return RequestInterface
     */
    public function __invoke(Command_Interface $command, mixed $endpoint = null)
    {
        $command_args = $command->to_array();
        $command_name = $command->get_name();
        $operation = $this->api->get_operation($command_name);
        $headers = static::$default_headers;
        // Operations with no defined input type must not contain bodies
        // Content-Type must not be set
        if ($operation['input'] !== null) {
            $body = $this->serialize($operation->get_input(), $command_args);
            $headers['Content-Length'] = strlen($body);
        } else {
            unset($headers['Content-Type']);
        }
        if ($endpoint instanceof Ruleset_Endpoint) {
            $this->is_use_endpoint_v2 = true;
            $this->set_endpoint_v2request_options($endpoint, $headers);
            $this->endpoint = $endpoint->get_url();
        }
        $request_target = $this->build_request_target($command_name, $operation['http']['requestUri'] ?? '');
        $uri = new Uri($this->endpoint . $request_target);
        return new Request($operation['http']['method'], $uri, $headers, $body ?? null);
    }
    abstract public function serialize(Structure_Shape $input_shape, array $command_args): string;
    /**
     * Resolves arguments for blob shapes present in the request arguments
     * into a protocol-specific format.
     *
     *
     */
    abstract protected function resolve_blob(mixed $value): array;
    /**
     * Resolves arguments for timestamp shapes present in the request arguments
     * into a protocol-specific format.
     *
     * @param mixed $value
     */
    abstract protected function resolve_timestamp(int|float|string|DateTimeInterface $value): array;
    /**
     * Resolves input shape fields that are present in the request arguments
     *
     *
     */
    protected function resolve_input_shape(Shape $shape, mixed $value): mixed
    {
        switch ($shape->get_type()) {
            case 'structure':
                $data = [];
                foreach ($value as $k => $v) {
                    if ($v !== null && $shape->has_member($k)) {
                        $value_shape = $shape->get_member($k);
                        $data[$value_shape['locationName'] ?: $k] = $this->resolve_input_shape($value_shape, $v);
                    }
                }
                return $data;
            case 'list':
                $items = $shape->get_member();
                foreach ($value as $k => $v) {
                    $value[$k] = $this->resolve_input_shape($items, $v);
                }
                return $value;
            case 'map':
                $values = $shape->get_value();
                foreach ($value as $k => $v) {
                    $value[$k] = $this->resolve_input_shape($values, $v);
                }
                return $value;
            case 'timestamp':
                return $this->resolve_timestamp($value);
            case 'string':
                return (string) $value;
            case 'integer':
            case 'long':
                return (int) $value;
            case 'double':
            case 'float':
                return (float) $value;
            case 'blob':
                return $this->resolve_blob($value);
            default:
                return $value;
        }
    }
    /**
     * Builds request URI absolute path
     *
     *
     */
    private function build_request_target(string $command_name, string $request_uri): string
    {
        $request_uri = str_ends_with($request_uri, '/') ? $request_uri : $request_uri . '/';
        $target_prefix = $this->api->get_metadata('targetPrefix');
        return "{$request_uri}service/{$target_prefix}/operation/{$command_name}";
    }
}