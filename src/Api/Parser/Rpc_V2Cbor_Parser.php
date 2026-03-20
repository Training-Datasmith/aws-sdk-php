<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Cbor\Cbor_Decoder;
use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Psr\Http\Message\Stream_Interface;
/**
 * Parses responses according to Smithy RPC V2 CBOR protocol standards.
 *
 * https://smithy.io/2.0/additional-specs/protocols/smithy-rpc-v2.html
 *
 * @internal
 */
final class Rpc_V2cbor_Parser extends Abstract_Rpc_V2parser
{
    use Rpc_V2parser_Trait;
    protected static string $smithy_protocol = 'rpc-v2-cbor';
    private Cbor_Decoder $decoder;
    /**
     * @param Service $api Service description
     */
    public function __construct(Service $api)
    {
        $this->decoder = new Cbor_Decoder();
        parent::__construct($api);
    }
    /**
     * @param $response
     *
     */
    public function parse_member_from_stream(Stream_Interface $stream, Structure_Shape $member, $response): mixed
    {
        return $this->resolve_output_shape($member, $this->parse_cbor($stream, $response));
    }
}