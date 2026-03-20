<?php

declare (strict_types=1);
namespace Aws\Api\Error_Parser;

use Aws\Api\Parser\Abstract_Parser;
use Aws\Api\Parser\Payload_Parser_Trait;
use Aws\Api\Parser\Xml_Parser;
use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Parses XML errors.
 */
class Xml_Error_Parser extends Abstract_Error_Parser
{
    use Payload_Parser_Trait;
    protected \Aws\Api\Parser\Xml_Parser $parser;
    public function __construct(?Service $api = null, ?Xml_Parser $parser = null)
    {
        parent::__construct($api);
        $this->parser = $parser ?: new Xml_Parser();
    }
    /**
     * @return mixed[]
     */
    public function __invoke(Response_Interface $response, ?Command_Interface $command = null): array
    {
        $response = Abstract_Parser::get_response_with_caching_stream($response);
        $code = (string) $response->get_status_code();
        $data = ['type' => $code[0] == '4' ? 'client' : 'server', 'request_id' => null, 'code' => null, 'message' => null, 'parsed' => null];
        $raw_body = Abstract_Parser::get_body_contents($response);
        if (!empty($raw_body)) {
            $this->parse_body($this->parse_xml($raw_body, $response), $data);
        } else {
            $this->parse_headers($response, $data);
        }
        $this->populate_shape($data, $response, $command);
        return $data;
    }
    private function parse_headers(Response_Interface $response, array &$data): void
    {
        if ($response->get_status_code() == '404') {
            $data['code'] = 'NotFound';
        }
        $data['message'] = $response->get_status_code() . ' ' . $response->get_reason_phrase();
        if ($request_id = $response->get_header_line('x-amz-request-id')) {
            $data['request_id'] = $request_id;
            $data['message'] .= " (Request-ID: {$request_id})";
        }
    }
    private function parse_body(\Simple_Xml_Element $body, array &$data): void
    {
        $data['parsed'] = $body;
        $prefix = $this->register_namespace_prefix($body);
        if ($temp_xml = $body->xpath("//{$prefix}Code[1]")) {
            $data['code'] = (string) $temp_xml[0];
        }
        if ($temp_xml = $body->xpath("//{$prefix}Message[1]")) {
            $data['message'] = (string) $temp_xml[0];
        }
        $temp_xml = $body->xpath("//{$prefix}RequestId[1]");
        if (isset($temp_xml[0])) {
            $data['request_id'] = (string) $temp_xml[0];
        }
    }
    protected function register_namespace_prefix(\Simple_Xml_Element $element): string
    {
        $namespaces = $element->get_doc_namespaces();
        if (!isset($namespaces[''])) {
            return '';
        }
        // Account for the default namespace being defined and PHP not
        // being able to handle it :(.
        $element->register_x_path_namespace('ns', $namespaces['']);
        return 'ns:';
    }
    protected function payload(Response_Interface $response, Structure_Shape $member)
    {
        $raw_body = Abstract_Parser::get_body_contents($response);
        if (empty($raw_body)) {
            return $raw_body;
        }
        $xml_body = $this->parse_xml($raw_body, $response);
        $prefix = $this->register_namespace_prefix($xml_body);
        $error_body = $xml_body->xpath("//{$prefix}Error");
        if (is_array($error_body) && !empty($error_body[0])) {
            return $this->parser->parse($member, $error_body[0]);
        }
        return $raw_body;
    }
}