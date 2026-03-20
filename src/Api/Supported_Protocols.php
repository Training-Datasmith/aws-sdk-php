<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Priority ordered collection of supported AWS protocols.
 */
enum Supported_Protocols : string
{
    case JSON = 'json';
    case CBOR = 'smithy-rpc-v2-cbor';
    case REST_JSON = 'rest-json';
    case REST_XML = 'rest-xml';
    case QUERY = 'query';
    case EC2 = 'ec2';
    /**
     * Check if a protocol is valid.
     *
     * @return bool True if the protocol is supported, otherwise false.
     */
    public static function is_supported(string $protocol): bool
    {
        return self::try_from($protocol) !== null;
    }
}