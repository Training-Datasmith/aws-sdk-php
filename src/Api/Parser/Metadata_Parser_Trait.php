<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Date_Time_Result;
use Aws\Api\Shape;
use Psr\Http\Message\Response_Interface;
trait Metadata_Parser_Trait
{
    /**
     * Extract a single header from the response into the result.
     */
    protected function extract_header($name, Shape $shape, Response_Interface $response, array &$result)
    {
        $value = $response->get_header_line($shape['locationName'] ?: $name);
        // Empty values should not be deserialized
        if ($value === null || $value === '') {
            return;
        }
        switch ($shape->get_type()) {
            case 'float':
            case 'double':
                $value = (float) $value;
                break;
            case 'long':
            case 'integer':
                $value = (int) $value;
                break;
            case 'boolean':
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'blob':
                $value = base64_decode($value);
                break;
            case 'timestamp':
                try {
                    $value = Date_Time_Result::from_timestamp($value, !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : null);
                    break;
                } catch (\Exception) {
                    // If the value cannot be parsed, then do not add it to the
                    // output structure.
                    return;
                }
            case 'string':
                if ($shape['jsonvalue']) {
                    $value = $this->parse_json(base64_decode($value), $response);
                }
                break;
        }
        $result[$name] = $value;
    }
    /**
     * Extract a map of headers with an optional prefix from the response.
     */
    protected function extract_headers($name, Shape $shape, Response_Interface $response, array &$result)
    {
        // Check if the headers are prefixed by a location name
        $result[$name] = [];
        $prefix = $shape['locationName'];
        $prefix_len = strlen((string) $prefix);
        foreach ($response->get_headers() as $k => $values) {
            if (!$prefix_len) {
                $result[$name][$k] = implode(', ', $values);
            } elseif (stripos($k, (string) $prefix) === 0) {
                $result[$name][substr($k, $prefix_len)] = implode(', ', $values);
            }
        }
    }
    /**
     * Places the status code of the response into the result array.
     */
    protected function extract_status($name, Response_Interface $response, array &$result)
    {
        $result[$name] = (int) $response->get_status_code();
    }
}