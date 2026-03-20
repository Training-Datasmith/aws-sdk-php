<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Represents a timestamp shape.
 */
class Timestamp_Shape extends Shape
{
    public function __construct(array $definition, Shape_Map $shape_map)
    {
        $definition['type'] = 'timestamp';
        parent::__construct($definition, $shape_map);
    }
    /**
     * Formats a timestamp value for a service.
     *
     * @param mixed  $value  Value to format
     * @param string $format Format used to serialize the value
     *
     * @return int|string
     * @throws \UnexpectedValueException if the format is unknown.
     * @throws \InvalidArgumentException if the value is an unsupported type.
     */
    public static function format($value, $format)
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->get_timestamp();
        } elseif (is_string($value)) {
            $value = strtotime($value);
        } elseif (!is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException('Unable to handle the provided' . ' timestamp type: ' . gettype($value));
        }
        return match ($format) {
            'iso8601' => gmdate('Y-m-d\TH:i:s\Z', (int) $value),
            'rfc822' => gmdate('D, d M Y H:i:s \G\M\T', (int) $value),
            'unixTimestamp' => $value,
            default => throw new \UnexpectedValueException('Unknown timestamp format: ' . $format),
        };
    }
}