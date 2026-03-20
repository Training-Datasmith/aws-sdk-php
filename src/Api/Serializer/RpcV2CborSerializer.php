<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\Cbor\Cbor_Encoder;
use Aws\Api\Cbor\Exception\Cbor_Exception;
use Aws\Api\Exception\Rpc_V2cbor_Exception;
use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use DateTimeInterface;
/**
 * Serializes requests according to Smithy RPC-V2 CBOR protocol standards.
 *
 * https://smithy.io/2.0/additional-specs/protocols/smithy-rpc-v2.html
 *
 * @internal
 */
final class Rpc_V2cbor_Serializer extends Abstract_Rpc_V2serializer
{
    /** @var array|string[]  */
    protected static array $default_headers = [self::HEADER_SMITHY_PROTOCOL => 'rpc-v2-cbor', self::HEADER_CONTENT_TYPE => 'application/cbor', self::HEADER_ACCEPT => 'application/cbor'];
    private readonly Cbor_Encoder $encoder;
    /**
     * @param Service $api Service API description
     * @param string $endpoint Endpoint to connect to
     */
    public function __construct(Service $api, string $endpoint)
    {
        $this->encoder = new Cbor_Encoder();
        parent::__construct($api, $endpoint);
    }
    /**
     *
     * @throws RpcV2CborException
     */
    public function serialize(Structure_Shape $input_shape, array $command_args): string
    {
        try {
            $resolved_input = $this->resolve_input_shape($input_shape, $command_args);
            return !empty($resolved_input) ? $this->encoder->encode($resolved_input) : $this->encoder->encode_empty_indefinite_map();
        } catch (Cbor_Exception $e) {
            throw new Rpc_V2cbor_Exception('Unable to encode CBOR document ' . $input_shape->get_name() . ': ' . $e->get_message() . PHP_EOL);
        }
    }
    /**
     * Wraps blob values in order to be encoded properly into
     * byte strings.
     *
     *
     * @return string[]
     * @throws RpcV2CborException
     */
    protected function resolve_blob(mixed $value): array
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
            if ($value === false) {
                throw new Rpc_V2cbor_Exception('Failed to read resource stream value during serialization');
            }
        }
        // Wrapper to differentiate byte string values during encoding
        return ['__cbor_bytes' => (string) $value];
    }
    /**
     * Wraps timestamp values in order to be encoded properly into
     * value tag 1.
     *
     * @param mixed $value
     *
     * @return string[]
     * @throws RpcV2CborException
     */
    protected function resolve_timestamp(int|float|string|DateTimeInterface $value): array
    {
        if (is_numeric($value)) {
            return ['__cbor_timestamp' => $value];
        }
        if ($value instanceof DateTimeInterface) {
            // Preserve milliseconds
            $micro = (int) $value->format('u');
            $value = $value->get_timestamp() + $micro / 1000000.0;
        } else {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                throw new Rpc_V2cbor_Exception('Request serialization failed: Invalid date/time: ' . $value);
            }
            $value = $timestamp;
        }
        // Wrapper to differentiate timestamp values during encoding
        return ['__cbor_timestamp' => $value];
    }
}