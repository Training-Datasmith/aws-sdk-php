<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Parser\Exception\Parser_Exception;
trait Payload_Parser_Trait
{
    /**
     * @param string $json
     *
     * @throws ParserException
     *
     * @return array
     */
    private function parse_json($json, $response)
    {
        $json_payload = json_decode($json, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new Parser_Exception('Error parsing JSON: ' . json_last_error_msg(), 0, null, ['response' => $response]);
        }
        return $json_payload;
    }
    /**
     * @param string $xml
     *
     * @throws ParserException
     *
     * @return \SimpleXMLElement
     */
    protected function parse_xml($xml, $response)
    {
        $prior_setting = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $xml_payload = new \Simple_Xml_Element($xml);
            if ($error = libxml_get_last_error()) {
                throw new \RuntimeException($error->message);
            }
        } catch (\Exception $e) {
            throw new Parser_Exception("Error parsing XML: {$e->get_message()}", 0, $e, ['response' => $response]);
        } finally {
            libxml_use_internal_errors($prior_setting);
        }
        return $xml_payload;
    }
}