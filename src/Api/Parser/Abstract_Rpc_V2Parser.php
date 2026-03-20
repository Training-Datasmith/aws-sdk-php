<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Operation;
use Aws\Api\Parser\Exception\Parser_Exception;
use Aws\Command_Interface;
use Aws\Result;
use Psr\Http\Message\Response_Interface;
/**
 * Base implementation for Smithy RPC V2 protocol parsers.
 *
 * Implementers MUST define the following static property representing
 * the `Smithy-Protocol` header value:
 *    self::HEADER_SMITHY_PROTOCOL => static::$smithyProtocol
 *
 * @internal
 */
abstract class Abstract_Rpc_V2parser extends Abstract_Parser
{
    private const HEADER_SMITHY_PROTOCOL = 'Smithy-Protocol';
    protected static string $smithy_protocol;
    public function __invoke(Command_Interface $command, Response_Interface $response)
    {
        $operation = $this->api->get_operation($command->get_name());
        return $this->parse_response($response, $operation);
    }
    /**
     * Parses a response according to Smithy RPC V2 protocol standards.
     *
     * @param ResponseInterface $response the response to parse.
     * @param Operation $operation the operation which holds information for
     *        parsing the response.
     */
    private function parse_response(Response_Interface $response, Operation $operation): Result
    {
        $smithy_protocol_header = $response->get_header_line(self::HEADER_SMITHY_PROTOCOL);
        if ($smithy_protocol_header !== static::$smithy_protocol) {
            $status_code = $response->get_status_code();
            throw new Parser_Exception("Malformed response: Smithy-Protocol header mismatch (HTTP {$status_code}). " . 'Expected ' . static::$smithy_protocol);
        }
        if ($operation['output'] === null) {
            return new Result([]);
        }
        $output_shape = $operation->get_output();
        foreach ($output_shape->get_members() as $member_name => $member_props) {
            if (!empty($member_props['eventstream'])) {
                return new Result([$member_name => new Event_Parsing_Iterator($response->get_body(), $output_shape->get_member($member_name), $this)]);
            }
        }
        $result = $this->parse_member_from_stream($response->get_body(), $output_shape, $response);
        return new Result(is_null($result) ? [] : $result);
    }
}