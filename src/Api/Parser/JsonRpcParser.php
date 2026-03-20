<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Operation;
use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Aws\Result;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Implements JSON-RPC parsing (e.g., DynamoDB)
 */
class Json_Rpc_Parser extends Abstract_Parser
{
    use Payload_Parser_Trait;
    /**
     * @param Service    $api    Service description
     * @param JsonParser $parser JSON body builder
     */
    public function __construct(Service $api, ?Json_Parser $parser = null)
    {
        parent::__construct($api);
        $this->parser = $parser ?: new Json_Parser();
    }
    public function __invoke(Command_Interface $command, Response_Interface $response)
    {
        $operation = $this->api->get_operation($command->get_name());
        return $this->parse_response($response, $operation);
    }
    /**
     * This method parses a response based on JSON RPC protocol.
     *
     * @param ResponseInterface $response the response to parse.
     * @param Operation $operation the operation which holds information for
     *        parsing the response.
     */
    private function parse_response(Response_Interface $response, Operation $operation): \Aws\Result
    {
        if (null === $operation['output']) {
            return new Result([]);
        }
        $output_shape = $operation->get_output();
        foreach ($output_shape->get_members() as $member_name => $member_props) {
            if (!empty($member_props['eventstream'])) {
                return new Result([$member_name => new Event_Parsing_Iterator($response->get_body(), $output_shape->get_member($member_name), $this)]);
            }
        }
        $body = $response->get_body();
        if ($body->is_seekable()) {
            $body->rewind();
        }
        $result = $this->parse_member_from_stream($body, $operation->get_output(), $response);
        return new Result(is_null($result) ? [] : $result);
    }
    public function parse_member_from_stream(Stream_Interface $stream, Structure_Shape $member, $response)
    {
        return $this->parser->parse($member, $this->parse_json($stream, $response));
    }
}