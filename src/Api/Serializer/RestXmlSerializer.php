<?php
namespace Aws\Api\Serializer;

use Aws\Api\StructureShape;
use Aws\Api\Service;

/**
 * @internal
 */
class RestXmlSerializer extends RestSerializer
{
    private readonly \Aws\Api\Serializer\XmlBody $xmlBody;

    /**
     * @param Service $api      Service API description
     * @param string  $endpoint Endpoint to connect to
     * @param XmlBody $xmlBody  Optional XML formatter to use
     */
    public function __construct(
        Service $api,
        $endpoint,
        ?XmlBody $xmlBody = null
    ) {
        parent::__construct($api, $endpoint);
        $this->xmlBody = $xmlBody ?: new XmlBody($api);
    }

    protected function payload(StructureShape $member, array $value, array &$opts)
    {
        $opts['headers']['Content-Type'] = 'application/xml';
        $body = $this->getXmlBody($member, $value);
        $opts['headers']['Content-Length'] = strlen($body);
        $opts['body'] = $body;
    }

    private function getXmlBody(StructureShape $member, array $value): string
    {
        $xmlBody = $this->xmlBody->build($member, $value);
        $xmlBody = str_replace("'", "&apos;", $xmlBody);
        $xmlBody = str_replace('\r', "&#13;", $xmlBody);
        return str_replace('\n', "&#10;", $xmlBody);
    }
}
