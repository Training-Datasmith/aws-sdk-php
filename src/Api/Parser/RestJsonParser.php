<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Implements REST-JSON parsing (e.g., Glacier, Elastic Transcoder)
 */
class Rest_Json_Parser extends Abstract_Rest_Parser
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
    protected function payload(Response_Interface $response, Structure_Shape $member, array &$result)
    {
        $raw_body = Abstract_Parser::get_body_contents($response);
        // Parse JSON if we have content
        if (!empty($raw_body)) {
            $parsed_json = $this->parse_json($raw_body, $response);
        } else {
            // An empty response body should be deserialized as null
            $result = null;
            return;
        }
        $parsed_body = $this->parser->parse($member, $parsed_json);
        if (is_string($parsed_body) && $member['document']) {
            // Document types can be strings: replace entire result
            $result = $parsed_body;
        } else {
            // Merge array/object results into existing result
            $result = array_merge($result, (array) $parsed_body);
        }
    }
    public function parse_member_from_stream(Stream_Interface $stream, Structure_Shape $member, $response)
    {
        $json_body = $this->parse_json($stream, $response);
        if ($json_body) {
            return $this->parser->parse($member, $json_body);
        }
        return [];
    }
}