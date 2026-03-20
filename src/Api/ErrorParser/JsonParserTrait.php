<?php

declare (strict_types=1);
namespace Aws\Api\Error_Parser;

use Aws\Api\Parser\Abstract_Parser;
use Aws\Api\Parser\Payload_Parser_Trait;
use Aws\Api\Structure_Shape;
use Psr\Http\Message\Response_Interface;
/**
 * Provides basic JSON error parsing functionality.
 */
trait Json_Parser_Trait
{
    use Payload_Parser_Trait;
    private function generic_handler(Response_Interface $response): array
    {
        $code = (string) $response->get_status_code();
        $error_code = null;
        $error_type = null;
        // Parse error code and type for query compatible services
        if ($this->api && !is_null($this->api->get_metadata('awsQueryCompatible')) && $response->has_header('x-amzn-query-error')) {
            $aws_query_error = $this->parse_aws_query_compatible_header($response);
            if ($aws_query_error) {
                $error_code = $aws_query_error['code'];
                $error_type = $aws_query_error['type'];
            }
        }
        // Parse error code from X-Amzn-Errortype header
        if (!$error_code && $response->has_header('X-Amzn-Errortype')) {
            $error_code = $this->extract_error_code($response->get_header_line('X-Amzn-Errortype'));
        }
        $parsed_body = null;
        $raw_body = Abstract_Parser::get_body_contents($response);
        if (!empty($raw_body)) {
            $parsed_body = $this->parse_json($raw_body, $response);
        }
        // Parse error code from response body
        if (!$error_code && $parsed_body) {
            $error_code = $this->parse_error_from_body($parsed_body);
        }
        if (!isset($error_type)) {
            $error_type = $code[0] == '4' ? 'client' : 'server';
        }
        return ['request_id' => $response->get_header_line('x-amzn-requestid'), 'code' => $error_code ?? null, 'message' => null, 'type' => $error_type, 'parsed' => $parsed_body];
    }
    /**
     * Parse AWS Query Compatible error from header
     *
     * @return array|null Returns ['code' => string, 'type' => string] or null
     */
    private function parse_aws_query_compatible_header(Response_Interface $response): ?array
    {
        $query_error = $response->get_header_line('x-amzn-query-error');
        $parts = explode(';', $query_error);
        if (count($parts) === 2 && $parts[0] && $parts[1]) {
            return ['code' => $parts[0], 'type' => $parts[1]];
        }
        return null;
    }
    /**
     * Parse error code from response body
     */
    private function parse_error_from_body(?array $parsed_body): ?string
    {
        if (!$parsed_body || !isset($parsed_body['code']) && !isset($parsed_body['__type'])) {
            return null;
        }
        $error_code = $parsed_body['code'] ?? $parsed_body['__type'];
        return $this->extract_error_code($error_code);
    }
    /**
     * Extract error code from raw error string containing # and/or : delimiters
     */
    private function extract_error_code(string $raw_error_code): string
    {
        // Handle format with both # and uri (e.g., "namespace#http://foo-bar")
        if (str_contains($raw_error_code, ':') && str_contains($raw_error_code, '#')) {
            $start = strpos($raw_error_code, '#') + 1;
            $end = strpos($raw_error_code, ':', $start);
            return substr($raw_error_code, $start, $end - $start);
        }
        // Handle format with uri only : (e.g., "ErrorCode:http://foo-bar.com/baz")
        if (str_contains($raw_error_code, ':')) {
            return substr($raw_error_code, 0, strpos($raw_error_code, ':'));
        }
        // Handle format with only # (e.g., "namespace#ErrorCode")
        if (str_contains($raw_error_code, '#')) {
            return substr($raw_error_code, strpos($raw_error_code, '#') + 1);
        }
        return $raw_error_code;
    }
    protected function payload(Response_Interface $response, Structure_Shape $member)
    {
        $raw_body = Abstract_Parser::get_body_contents($response);
        if (!empty($raw_body)) {
            $json_body = $this->parse_json($raw_body, $response);
        } else {
            $json_body = $raw_body;
        }
        return $this->parser->parse($member, $json_body);
    }
}