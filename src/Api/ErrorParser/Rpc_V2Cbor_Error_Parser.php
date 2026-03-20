<?php

declare (strict_types=1);
namespace Aws\Api\Error_Parser;

use Aws\Api\Cbor\Cbor_Decoder;
use Aws\Api\Parser\Rpc_V2parser_Trait;
use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * Parses errors according to Smithy RPC V2 CBOR protocol standards.
 *
 * https://smithy.io/2.0/additional-specs/protocols/smithy-rpc-v2.html
 *
 * @internal
 */
final class Rpc_V2cbor_Error_Parser extends Abstract_Rpc_V2error_Parser
{
    use Rpc_V2parser_Trait;
    private Cbor_Decoder $decoder;
    public function __construct(?Service $api = null)
    {
        $this->decoder = new Cbor_Decoder();
        parent::__construct($api);
    }
    /**
     *
     * @throws \Exception
     */
    protected function payload(Response_Interface $response, Structure_Shape $member): array
    {
        $body = $response->get_body();
        $cbor_body = $this->parse_cbor($body, $response);
        return $this->resolve_output_shape($member, $cbor_body);
    }
    protected function parse_body(Stream_Interface $body, Response_Interface $response): mixed
    {
        return $this->parse_cbor($body, $response);
    }
}