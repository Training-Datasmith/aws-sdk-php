<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\Service;
use Aws\Api\Structure_Shape;
/**
 * @internal
 */
class Rest_Xml_Serializer extends Rest_Serializer
{
    private readonly \Aws\Api\Serializer\Xml_Body $xml_body;
    /**
     * @param Service $api      Service API description
     * @param string  $endpoint Endpoint to connect to
     * @param XmlBody $xmlBody  Optional XML formatter to use
     */
    public function __construct(Service $api, $endpoint, ?Xml_Body $xml_body = null)
    {
        parent::__construct($api, $endpoint);
        $this->xml_body = $xml_body ?: new Xml_Body($api);
    }
    protected function payload(Structure_Shape $member, array $value, array &$opts)
    {
        $opts['headers']['Content-Type'] = 'application/xml';
        $body = $this->get_xml_body($member, $value);
        $opts['headers']['Content-Length'] = strlen($body);
        $opts['body'] = $body;
    }
    private function get_xml_body(Structure_Shape $member, array $value): string
    {
        $xml_body = $this->xml_body->build($member, $value);
        $xml_body = str_replace("'", '&apos;', $xml_body);
        $xml_body = str_replace('\r', '&#13;', $xml_body);
        return str_replace('\n', '&#10;', $xml_body);
    }
}