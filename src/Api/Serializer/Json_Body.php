<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\Timestamp_Shape;
use Aws\Exception\Invalid_Json_Exception;
/**
 * Formats the JSON body of a JSON-REST or JSON-RPC operation.
 * @internal
 */
class Json_Body
{
    /**
     * Gets the JSON Content-Type header for a service API
     *
     *
     */
    public static function get_content_type(Service $service): string
    {
        if ($service->get_metadata('protocol') === 'rest-json') {
            return 'application/json';
        }
        $json_version = $service->get_metadata('jsonVersion');
        if (empty($json_version)) {
            throw new \InvalidArgumentException('invalid json');
        }
        return 'application/x-amz-json-' . @number_format($service->get_metadata('jsonVersion'), 1);
    }
    /**
     * Builds the JSON body based on an array of arguments.
     *
     * @param Shape $shape Operation being constructed
     * @param array|string $args  Associative array of arguments, or a string.
     *
     * @return string
     */
    public function build(Shape $shape, array|string $args)
    {
        try {
            $result = json_encode($this->format($shape, $args), JSON_THROW_ON_ERROR);
        } catch (\Json_Exception $e) {
            throw new Invalid_Json_Exception('Unable to encode JSON document ' . $shape->get_name() . ': ' . $e->get_message() . PHP_EOL);
        }
        return $result === '[]' ? '{}' : $result;
    }
    private function format(Shape $shape, $value)
    {
        switch ($shape['type']) {
            case 'structure':
                $data = [];
                if ($shape['document'] ?? false) {
                    return $value;
                }
                foreach ($value as $k => $v) {
                    if ($v !== null && $shape->has_member($k)) {
                        $value_shape = $shape->get_member($k);
                        $data[$value_shape['locationName'] ?: $k] = $this->format($value_shape, $v);
                    }
                }
                if (empty($data)) {
                    return new \stdClass();
                }
                return $data;
            case 'list':
                $items = $shape->get_member();
                foreach ($value as $k => $v) {
                    $value[$k] = $this->format($items, $v);
                }
                return $value;
            case 'map':
                if (empty($value)) {
                    return new \stdClass();
                }
                $values = $shape->get_value();
                foreach ($value as $k => $v) {
                    $value[$k] = $this->format($values, $v);
                }
                return $value;
            case 'blob':
                return base64_encode((string) $value);
            case 'timestamp':
                $timestamp_format = !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : 'unixTimestamp';
                return Timestamp_Shape::format($value, $timestamp_format);
            default:
                return $value;
        }
    }
}