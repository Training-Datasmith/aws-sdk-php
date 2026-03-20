<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Cbor\Exception\Cbor_Exception;
use Aws\Api\Date_Time_Result;
use Aws\Api\Parser\Exception\Parser_Exception;
use Aws\Api\Shape;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * Shared parsing logic for RPC V2 Parsers.
 *
 * @internal
 */
trait Rpc_V2parser_Trait
{
    /**
     * Resolves output shape fields that are present in the response
     *
     *
     */
    protected function resolve_output_shape(Shape $shape, mixed $value): mixed
    {
        if ($value === null) {
            return $value;
        }
        switch ($shape['type']) {
            case 'structure':
                $target = [];
                foreach ($shape->get_members() as $name => $member) {
                    $location_name = $member['locationName'] ?: $name;
                    if (isset($value[$location_name])) {
                        $target[$name] = $this->resolve_output_shape($member, $value[$location_name]);
                    }
                }
                return $target;
            case 'list':
                $target = [];
                foreach ($value as $v) {
                    $target[] = $this->resolve_output_shape($shape->get_member(), $v);
                }
                return $target;
            case 'map':
                $target = [];
                foreach ($value as $k => $v) {
                    if ($v !== null) {
                        $target[$k] = $this->resolve_output_shape($shape->get_value(), $v);
                    }
                }
                return $target;
            case 'timestamp':
                try {
                    $value = Date_Time_Result::from_epoch($value);
                } catch (\Exception $e) {
                    trigger_error('Unable to parse timestamp value for ' . $shape->get_name() . ': ' . $e->get_message(), E_USER_WARNING);
                }
                return $value;
            default:
                return $value;
        }
    }
    /**
     * Parses CBOR-encoded response data from RPC V2 CBOR services.
     *
     *
     */
    protected function parse_cbor(Stream_Interface $stream, Response_Interface $response): mixed
    {
        try {
            $cbor_string = (string) $stream;
            return empty($cbor_string) ? null : $this->decoder->decode($cbor_string);
        } catch (Cbor_Exception $e) {
            throw new Parser_Exception("Malformed Response: error parsing CBOR: {$e->get_message()}", 0, $e, ['response' => $response]);
        }
    }
}