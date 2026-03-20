<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Implements REST-XML parsing (e.g., S3, CloudFront, etc...)
 */
class Rest_Xml_Parser extends Abstract_Rest_Parser
{
    use Payload_Parser_Trait;
    /**
     * @param Service   $api    Service description
     * @param XmlParser $parser XML body parser
     */
    public function __construct(Service $api, ?Xml_Parser $parser = null)
    {
        parent::__construct($api);
        $this->parser = $parser ?: new Xml_Parser();
    }
    protected function payload(Response_Interface $response, Structure_Shape $member, array &$result)
    {
        $body = $response->get_body();
        if ($body->is_seekable()) {
            $body->rewind();
        }
        $result += $this->parse_member_from_stream($body, $member, $response);
    }
    public function parse_member_from_stream(Stream_Interface $stream, Structure_Shape $member, $response)
    {
        $xml = $this->parse_xml($stream, $response);
        return $this->parser->parse($member, $xml);
    }
}