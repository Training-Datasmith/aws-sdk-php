<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Aws\Result;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Parses query (XML) responses (e.g., EC2, SQS, and many others)
 */
class Query_Parser extends Abstract_Parser
{
    use Payload_Parser_Trait;
    /**
     * @param Service   $api                Service description
     * @param XmlParser $xmlParser          Optional XML parser
     * @param bool      $honorResultWrapper Set to false to disable the peeling
     *                                      back of result wrappers from the
     *                                      output structure.
     */
    public function __construct(Service $api, ?Xml_Parser $xml_parser = null, private $honor_result_wrapper = true)
    {
        parent::__construct($api);
        $this->parser = $xml_parser ?: new Xml_Parser();
    }
    public function __invoke(Command_Interface $command, Response_Interface $response): \Aws\Result
    {
        $output = $this->api->get_operation($command->get_name())->get_output();
        // Read the full payload, even in non-seekable streams
        $raw_body = Abstract_Parser::get_body_contents($response);
        // Just parse when the body is not empty
        $xml = !empty($raw_body) ? $this->parse_xml($raw_body, $response) : null;
        // Empty request bodies should not be deserialized.
        if (is_null($xml)) {
            return new Result();
        }
        if ($this->honor_result_wrapper && $output['resultWrapper']) {
            $xml = $xml->{$output['resultWrapper']};
        }
        return new Result($this->parser->parse($output, $xml));
    }
    public function parse_member_from_stream(Stream_Interface $stream, Structure_Shape $member, $response)
    {
        $xml = $this->parse_xml($stream, $response);
        return $this->parser->parse($member, $xml);
    }
}