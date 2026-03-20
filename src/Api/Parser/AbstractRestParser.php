<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Date_Time_Result;
use Aws\Api\Shape;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Aws\Result;
use Psr\Http\Message\Response_Interface;
/**
 * @internal
 */
abstract class Abstract_Rest_Parser extends Abstract_Parser
{
    use Payload_Parser_Trait;
    /**
     * Parses a payload from a response.
     *
     * @param ResponseInterface $response Response to parse.
     * @param StructureShape    $member   Member to parse
     * @param array             $result   Result value
     *
     * @return mixed
     */
    abstract protected function payload(Response_Interface $response, Structure_Shape $member, array &$result);
    public function __invoke(Command_Interface $command, Response_Interface $response)
    {
        $output = $this->api->get_operation($command->get_name())->get_output();
        $result = [];
        if ($payload = $output['payload']) {
            $this->extract_payload($payload, $output, $response, $result);
        } else {
            $response = Abstract_Parser::get_response_with_caching_stream($response);
            if ($response->get_body()->get_size() === null) {
                $raw_body = Abstract_Parser::get_body_contents($response);
                $is_empty = empty($raw_body);
            } else {
                $is_empty = $response->get_body()->get_size() === 0;
            }
            if (!$is_empty && count($output->get_members()) > 0) {
                // if no payload was found, then parse the contents of the body
                $this->payload($response, $output, $result);
            }
        }
        foreach ($output->get_members() as $name => $member) {
            switch ($member['location']) {
                case 'header':
                    $this->extract_header($name, $member, $response, $result);
                    break;
                case 'headers':
                    $this->extract_headers($name, $member, $response, $result);
                    break;
                case 'statusCode':
                    $this->extract_status($name, $response, $result);
                    break;
            }
        }
        return new Result($result);
    }
    private function extract_payload($payload, Structure_Shape $output, Response_Interface $response, array &$result): void
    {
        $member = $output->get_member($payload);
        $body = $response->get_body();
        if (!empty($member['eventstream'])) {
            $result[$payload] = new Event_Parsing_Iterator($body, $member, $this);
            return;
        }
        $response = Abstract_Parser::get_response_with_caching_stream($response);
        if ($member instanceof Structure_Shape) {
            //Unions must have at least one member set to a non-null value
            // If the body is empty, we can assume it is unset
            if ($response->get_body()->get_size() === null) {
                $raw_body = Abstract_Parser::get_body_contents($response);
                $is_empty = empty($raw_body);
            } else {
                $is_empty = $response->get_body()->get_size() === 0;
            }
            if (!empty($member['union']) && $is_empty) {
                return;
            }
            $result[$payload] = [];
            $this->payload($response, $member, $result[$payload]);
        } else {
            // Always set the payload to the body stream, regardless of content
            $result[$payload] = $body;
        }
    }
    /**
     * Extract a single header from the response into the result.
     */
    private function extract_header($name, Shape $shape, Response_Interface $response, array &$result): void
    {
        $value = $response->get_header_line($shape['locationName'] ?: $name);
        // Empty headers should not be deserialized
        if ($value === null || $value === '') {
            return;
        }
        switch ($shape->get_type()) {
            case 'float':
            case 'double':
                $value = match ($value) {
                    'NaN', 'Infinity', '-Infinity' => $value,
                    default => (float) $value,
                };
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
                try {
                    if ($shape['jsonvalue']) {
                        $value = $this->parse_json(base64_decode($value), $response);
                    }
                    // If value is not set, do not add to output structure.
                    if (!isset($value)) {
                        return;
                    }
                    break;
                } catch (\Exception) {
                    //If the value cannot be parsed, then do not add it to the
                    //output structure.
                    return;
                }
            case 'list':
                $list_member = $shape->get_member();
                $type = $list_member->get_type();
                // Only boolean lists require special handling
                // other types can be returned as-is
                if ($type !== 'boolean') {
                    break;
                }
                $items = array_map(trim(...), explode(',', $value));
                $value = array_map(static fn($item): bool => filter_var($item, FILTER_VALIDATE_BOOLEAN), $items);
                break;
        }
        $result[$name] = $value;
    }
    /**
     * Extract a map of headers with an optional prefix from the response.
     */
    private function extract_headers($name, Shape $shape, Response_Interface $response, array &$result): void
    {
        // Check if the headers are prefixed by a location name
        $result[$name] = [];
        $prefix = $shape['locationName'];
        $prefix_len = $prefix !== null ? strlen($prefix) : 0;
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
    private function extract_status($name, Response_Interface $response, array &$result): void
    {
        $result[$name] = (int) $response->get_status_code();
    }
}