<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\List_Shape;
use Aws\Api\Map_Shape;
use Aws\Api\Operation;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\Structure_Shape;
use Aws\Api\Timestamp_Shape;
use Aws\Command_Interface;
use Aws\Endpoint_V2\Endpoint_V2serializer_Trait;
use Aws\Endpoint_V2\Ruleset\Ruleset_Endpoint;
use DateTimeInterface;
use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Request;
use Guzzle_Http\Psr7\Uri;
use Guzzle_Http\Psr7\Uri_Resolver;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Serializes HTTP locations like header, uri, payload, etc...
 * @internal
 */
abstract class Rest_Serializer
{
    use Endpoint_V2serializer_Trait;
    private const TEMPLATE_STRING_REGEX = '/\{([^\}]+)\}/';
    private static array $exclude_content_type = ['s3' => true, 'glacier' => true];
    /** @var Uri */
    private $endpoint;
    private ?bool $is_use_endpoint_v2 = null;
    /**
     * @param Service $api Service API description
     * @param string $endpoint Endpoint to connect to
     */
    public function __construct(private Service $api, $endpoint)
    {
        $this->endpoint = Psr7\Utils::uri_for($endpoint);
    }
    /**
     * @param CommandInterface $command Command to serialize into a request.
     * @param mixed|null $endpoint
     * @return RequestInterface
     */
    public function __invoke(Command_Interface $command, mixed $endpoint = null)
    {
        $operation = $this->api->get_operation($command->get_name());
        $command_args = $command->to_array();
        $opts = $this->serialize($operation, $command_args);
        $headers = $opts['headers'] ?? [];
        if ($endpoint instanceof Ruleset_Endpoint) {
            $this->is_use_endpoint_v2 = true;
            $this->set_endpoint_v2request_options($endpoint, $headers);
        }
        $uri = $this->build_endpoint($operation, $command_args, $opts);
        return new Request($operation['http']['method'], $uri, $headers, $opts['body'] ?? null);
    }
    /**
     * Modifies a hash of request options for a payload body.
     *
     * @param StructureShape $member Member to serialize
     * @param array $value Value to serialize
     * @param array $opts Request options to modify.
     */
    abstract protected function payload(Structure_Shape $member, array $value, array &$opts);
    /**
     * @return mixed[]
     */
    private function serialize(Operation $operation, array $args): array
    {
        $opts = [];
        $input = $operation->get_input();
        // Apply the payload trait if present
        if ($payload = $input['payload']) {
            $this->apply_payload($input, $payload, $args, $opts);
        }
        foreach ($args as $name => $value) {
            if ($input->has_member($name)) {
                $member = $input->get_member($name);
                $location = $member['location'];
                if (!$payload && !$location) {
                    $body_members[$name] = $value;
                } elseif ($location === 'header') {
                    $this->apply_header($name, $member, $value, $opts);
                } elseif ($location === 'querystring') {
                    $this->apply_query($name, $member, $value, $opts);
                } elseif ($location === 'headers') {
                    $this->apply_header_map($member, $value, $opts);
                }
            }
        }
        if (isset($body_members)) {
            $this->payload($input, $body_members, $opts);
        } elseif (!isset($opts['body']) && $this->has_payload_param($input, $payload)) {
            $this->payload($input, [], $opts);
        }
        return $opts;
    }
    private function apply_payload(Structure_Shape $input, $name, array $args, array &$opts): void
    {
        if (!isset($args[$name])) {
            return;
        }
        $m = $input->get_member($name);
        $type = $m->get_type();
        if ($m['streaming'] || ($type === 'string' || $type === 'blob')) {
            // This path skips setting the content-type header usually done in
            // RestJsonSerializer and RestXmlSerializer.certain S3 and glacier
            // operations determine content type in Middleware::ContentType()
            if (!isset(self::$exclude_content_type[$this->api->get_service_name() ?? ''])) {
                switch ($type) {
                    case 'string':
                        $opts['headers']['Content-Type'] = 'text/plain';
                        break;
                    case 'blob':
                        $opts['headers']['Content-Type'] = 'application/octet-stream';
                        break;
                }
            }
            $body = $args[$name];
            if (!$m['streaming'] && is_string($body)) {
                $opts['headers']['Content-Length'] = strlen($body);
            }
            // Streaming bodies or payloads that are strings are
            // always just a stream of data.
            $opts['body'] = Psr7\Utils::stream_for($body);
            return;
        }
        $this->payload($m, $args[$name], $opts);
    }
    private function apply_header(int|string $name, Shape $member, $value, array &$opts): void
    {
        // Handle lists by recursively applying header logic to each element
        if ($member instanceof List_Shape) {
            $list_member = $member->get_member();
            $header_values = [];
            foreach ($value as $list_value) {
                $temp_opts = ['headers' => []];
                $this->apply_header('temp', $list_member, $list_value, $temp_opts);
                $converted_value = $temp_opts['headers']['temp'];
                $header_values[] = $converted_value;
            }
            $value = $header_values;
        } elseif (!is_null($value)) {
            switch ($member->get_type()) {
                case 'timestamp':
                    $timestamp_format = $member['timestampFormat'] ?? 'rfc822';
                    $value = $this->format_timestamp($value, $timestamp_format);
                    break;
                case 'boolean':
                    $value = $this->format_boolean($value);
                    break;
            }
        }
        if ($member['jsonvalue']) {
            $value = json_encode($value);
            if (empty($value) && JSON_ERROR_NONE !== json_last_error()) {
                throw new \InvalidArgumentException('Unable to encode the provided value' . ' with \'json_encode\'. ' . json_last_error_msg());
            }
            $value = base64_encode($value);
        }
        $opts['headers'][$member['locationName'] ?: $name] = $value;
    }
    /**
     * Note: This is currently only present in the Amazon S3 model.
     */
    private function apply_header_map(Shape $member, array $value, array &$opts): void
    {
        $prefix = $member['locationName'];
        foreach ($value as $k => $v) {
            $opts['headers'][$prefix . $k] = $v;
        }
    }
    private function apply_query(int|string $name, Shape $member, $value, array &$opts): void
    {
        if ($member instanceof Map_Shape) {
            $opts['query'] = isset($opts['query']) && is_array($opts['query']) ? $opts['query'] + $value : $value;
        } elseif ($member instanceof List_Shape) {
            $list_member = $member->get_member();
            $param_name = $member['locationName'] ?: $name;
            foreach ($value as $list_value) {
                // Recursively call applyQuery for each list element
                $temp_opts = ['query' => []];
                $this->apply_query('temp', $list_member, $list_value, $temp_opts);
                $opts['query'][$param_name][] = $temp_opts['query']['temp'];
            }
        } elseif (!is_null($value)) {
            switch ($member->get_type()) {
                case 'timestamp':
                    $timestamp_format = $member['timestampFormat'] ?? 'iso8601';
                    $value = $this->format_timestamp($value, $timestamp_format);
                    break;
                case 'boolean':
                    $value = $this->format_boolean($value);
                    break;
            }
            $opts['query'][$member['locationName'] ?: $name] = $value;
        }
    }
    private function build_endpoint(Operation $operation, array $args, array $opts): Uri_Interface
    {
        // Expand `requestUri` field members
        $relative_uri = $this->expand_uri_template($operation, $args);
        // Add query members to relativeUri
        if (!empty($opts['query'])) {
            $relative_uri = $this->append_query($opts['query'], $relative_uri);
        }
        // Special case - S3 keys that need path preservation
        if ($this->api->get_service_name() === 's3' && isset($args['Key']) && $this->should_preserve_path($args['Key'])) {
            return new Uri($this->endpoint . $relative_uri);
        }
        return $this->resolve_uri($relative_uri, $opts);
    }
    /**
     * Expands `requestUri` members
     *
     *
     */
    private function expand_uri_template(Operation $operation, array $args): string
    {
        $var_definitions = $this->get_var_definitions($operation, $args);
        return preg_replace_callback(self::TEMPLATE_STRING_REGEX, static function (array $matches) use ($var_definitions): string {
            $is_greedy = str_ends_with((string) $matches[1], '+');
            $var_name = $is_greedy ? substr((string) $matches[1], 0, -1) : $matches[1];
            if (!isset($var_definitions[$var_name])) {
                return '';
            }
            $value = $var_definitions[$var_name];
            if ($is_greedy) {
                return str_replace('%2F', '/', rawurlencode($value));
            }
            return rawurlencode($value);
        }, (string) $operation['http']['requestUri']);
    }
    /**
     * Checks for path-like key names. If detected, traditional
     * URI resolution is bypassed.
     */
    private function should_preserve_path(string $key): bool
    {
        // Keys with dot segments
        if (str_contains($key, '.')) {
            $segments = explode('/', $key);
            foreach ($segments as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return true;
                }
            }
        }
        // Keys starting with slash
        if (str_starts_with($key, '/')) {
            return true;
        }
        return false;
    }
    private function resolve_uri(string $relative_uri, array $opts): Uri_Interface
    {
        $base_path = $this->endpoint->get_path();
        // Only process if we have a non-empty base path
        if (!empty($base_path) && $base_path !== '/') {
            // if relative is just '/', we want just the base path without trailing slash
            if ($relative_uri === '/' || empty($relative_uri)) {
                // Remove trailing slash if present
                return $this->endpoint->with_path(rtrim((string) $base_path, '/'));
            }
            // if relative is '/?query', we want base path without trailing slash + query
            // for now, this is only seen with S3 GetBucketLocation after processing the model
            if (empty($opts['query']) && str_starts_with($relative_uri, '/?')) {
                $query = substr($relative_uri, 2);
                // Remove '/?'
                return $this->endpoint->with_query($query);
            }
            // Ensure base path has trailing slash
            if (!str_ends_with((string) $base_path, '/')) {
                $this->endpoint = $this->endpoint->with_path($base_path . '/');
            }
            // Remove leading slash from relative path to make it relative
            if (str_starts_with($relative_uri, '/')) {
                $relative_uri = substr($relative_uri, 1);
            }
        }
        return Uri_Resolver::resolve($this->endpoint, new Uri($relative_uri));
    }
    /**
     * @param $payload
     *
     */
    private function has_payload_param(Structure_Shape $input, $payload): bool
    {
        if ($payload) {
            $potentially_empty_types = ['blob', 'string'];
            if ($this->api->get_protocol() === 'rest-xml') {
                $potentially_empty_types[] = 'structure';
            }
            $payload_member = $input->get_member($payload);
            //unions may also be empty/unset
            if (!empty($payload_member['union']) || in_array($payload_member['type'], $potentially_empty_types)) {
                return false;
            }
        }
        foreach ($input->get_members() as $member) {
            if (!isset($member['location'])) {
                return true;
            }
        }
        return false;
    }
    /**
     * @param $query
     * @param $relativeUri
     */
    private function append_query($query, string $relative_uri): string
    {
        $append = Psr7\Query::build($query);
        return $relative_uri . (str_contains($relative_uri, '?') ? "&{$append}" : "?{$append}");
    }
    /**
     * @param CommandInterface $command
     *
     */
    private function get_var_definitions(Operation $operation, array $args): array
    {
        $var_definitions = [];
        foreach ($operation->get_input()->get_members() as $name => $member) {
            if ($member['location'] === 'uri') {
                $value = $args[$name] ?? null;
                if (!is_null($value)) {
                    switch ($member->get_type()) {
                        case 'timestamp':
                            $timestamp_format = $member['timestampFormat'] ?? 'iso8601';
                            $value = $this->format_timestamp($value, $timestamp_format);
                            break;
                        case 'boolean':
                            $value = $this->format_boolean($value);
                            break;
                    }
                }
                $var_definitions[$member['locationName'] ?: $name] = $value;
            }
        }
        return $var_definitions;
    }
    private function format_timestamp(DateTimeInterface|string|int $value, string $timestamp_format): string
    {
        return Timestamp_Shape::format($value, $timestamp_format);
    }
    /**
     * @param $value
     */
    private function format_boolean($value): string
    {
        return $value ? 'true' : 'false';
    }
}