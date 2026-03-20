<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\Service;
use Aws\Api\Structure_Shape;
/**
 * Serializes requests for the REST-JSON protocol.
 * @internal
 */
class Rest_Json_Serializer extends Rest_Serializer
{
    private readonly \Aws\Api\Serializer\Json_Body $json_formatter;
    /** @var string */
    private $content_type;
    /**
     * @param Service  $api           Service API description
     * @param string   $endpoint      Endpoint to connect to
     * @param JsonBody $jsonFormatter Optional JSON formatter to use
     */
    public function __construct(Service $api, $endpoint, ?Json_Body $json_formatter = null)
    {
        parent::__construct($api, $endpoint);
        $this->content_type = Json_Body::get_content_type($api);
        $this->json_formatter = $json_formatter ?: new Json_Body($api);
    }
    protected function payload(Structure_Shape $member, array|string $value, array &$opts)
    {
        $opts['headers']['Content-Type'] = $this->content_type;
        $body = $this->json_formatter->build($member, $value);
        $opts['headers']['Content-Length'] = strlen($body);
        $opts['body'] = $body;
    }
}