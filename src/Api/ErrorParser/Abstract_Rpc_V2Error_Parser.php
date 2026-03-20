<?php

declare (strict_types=1);
namespace Aws\Api\Error_Parser;

use Aws\Api\Parser\Abstract_Parser;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * Base implementation for Smithy RPC V2 protocol error parsers.
 *
 * @internal
 */
abstract class Abstract_Rpc_V2error_Parser extends Abstract_Error_Parser
{
    private const HEADER_QUERY_ERROR = 'x-amzn-query-error';
    private const HEADER_ERROR_TYPE = 'x-amzn-errortype';
    private const HEADER_REQUEST_ID = 'x-amzn-requestid';
    /**
     *
     * @return array
     */
    public function __invoke(Response_Interface $response, ?Command_Interface $command = null)
    {
        $response = Abstract_Parser::get_response_with_caching_stream($response);
        $data = $this->parse_error($response);
        if (isset($data['parsed']['__type'])) {
            $data['message'] = $data['parsed']['message'] ?? null;
        }
        $this->populate_shape($data, $response, $command);
        return $data;
    }
    abstract protected function payload(Response_Interface $response, Structure_Shape $member): array;
    abstract protected function parse_body(Stream_Interface $body, Response_Interface $response): mixed;
    private function parse_error(Response_Interface $response): array
    {
        $status_code = (string) $response->get_status_code();
        $error_code = null;
        $error_type = null;
        if ($this->api?->get_metadata('awsQueryCompatible') !== null && $response->has_header(self::HEADER_QUERY_ERROR) && $aws_query_error = $this->parse_query_compatible_header($response)) {
            $error_code = $aws_query_error['code'];
            $error_type = $aws_query_error['type'];
        }
        if (!$error_code && $response->has_header(self::HEADER_ERROR_TYPE)) {
            $error_code = $this->extract_error_code($response->get_header_line(self::HEADER_ERROR_TYPE));
        }
        $parsed_body = null;
        $body = $response->get_body();
        if ($body->get_size()) {
            //TODO handle unseekable streams with CachingStream
            $parsed_body = array_change_key_case($this->parse_body($body, $response));
        }
        if (!$error_code && $parsed_body) {
            $error_code = $this->extract_error_code($parsed_body['code'] ?? $parsed_body['__type'] ?? '');
        }
        return ['request_id' => $response->get_header_line(self::HEADER_REQUEST_ID), 'code' => $error_code ?: null, 'message' => null, 'type' => $error_type ?? ($status_code[0] === '4' ? 'client' : 'server'), 'parsed' => $parsed_body];
    }
    /**
     * Parse AWS Query Compatible error from header
     *
     *
     * @return array|null Returns ['code' => string, 'type' => string] or null
     */
    private function parse_query_compatible_header(Response_Interface $response): ?array
    {
        $parts = explode(';', $response->get_header_line(self::HEADER_QUERY_ERROR));
        if (count($parts) === 2 && $parts[0] && $parts[1]) {
            return ['code' => $parts[0], 'type' => $parts[1]];
        }
        return null;
    }
    /**
     * Extract error code from raw error string containing # and/or : delimiters
     */
    private function extract_error_code(string $raw_error_code): string
    {
        // Handle format with both # and uri (e.g., "namespace#ErrorCode:http://foo-bar")
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
}