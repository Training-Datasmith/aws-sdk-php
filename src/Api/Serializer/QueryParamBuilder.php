<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\List_Shape;
use Aws\Api\Map_Shape;
use Aws\Api\Shape;
use Aws\Api\Structure_Shape;
use Aws\Api\Timestamp_Shape;
/**
 * @internal
 */
class Query_Param_Builder
{
    private ?array $methods = null;
    protected function query_name(Shape $shape, $default = null)
    {
        if (null !== $shape['queryName']) {
            return $shape['queryName'];
        }
        if (null !== $shape['locationName']) {
            return $shape['locationName'];
        }
        if ($this->is_flat($shape) && !empty($shape['member']['locationName'])) {
            return $shape['member']['locationName'];
        }
        return $default;
    }
    protected function is_flat(Shape $shape): bool
    {
        return $shape['flattened'] === true;
    }
    public function __invoke(Structure_Shape $shape, array $params)
    {
        if (!$this->methods) {
            $this->methods = array_fill_keys(get_class_methods($this), true);
        }
        $query = [];
        $this->format_structure($shape, $params, '', $query);
        return $query;
    }
    protected function format(Shape $shape, $value, $prefix, array &$query)
    {
        $type = 'format_' . $shape['type'];
        if (isset($this->methods[$type])) {
            $this->{$type}($shape, $value, $prefix, $query);
        } else {
            $query[$prefix] = (string) $value;
        }
    }
    protected function format_structure(Structure_Shape $shape, array $value, $prefix, array &$query)
    {
        if ($prefix) {
            $prefix .= '.';
        }
        foreach ($value as $k => $v) {
            if ($shape->has_member($k)) {
                $member = $shape->get_member($k);
                $this->format($member, $v, $prefix . $this->query_name($member, $k), $query);
            }
        }
    }
    protected function format_list(List_Shape $shape, array $value, $prefix, array &$query)
    {
        // Handle empty list serialization
        if (!$value) {
            $query[$prefix] = '';
            return;
        }
        $items = $shape->get_member();
        if (!$this->is_flat($shape)) {
            $location_name = $shape->get_member()['locationName'] ?: 'member';
            $prefix .= ".{$location_name}";
            // flattened lists can also model a `locationName`
        } elseif ($name = $shape['locationName'] ?? $this->query_name($items)) {
            $parts = explode('.', (string) $prefix);
            $parts[count($parts) - 1] = $name;
            $prefix = implode('.', $parts);
        }
        foreach ($value as $k => $v) {
            $this->format($items, $v, $prefix . '.' . ($k + 1), $query);
        }
    }
    protected function format_map(Map_Shape $shape, array $value, string $prefix, array &$query)
    {
        $vals = $shape->get_value();
        $keys = $shape->get_key();
        if (!$this->is_flat($shape)) {
            $prefix .= '.entry';
        }
        $i = 0;
        $key_name = '%s.%d.' . $this->query_name($keys, 'key');
        $value_name = '%s.%s.' . $this->query_name($vals, 'value');
        foreach ($value as $k => $v) {
            $i++;
            $this->format($keys, $k, sprintf($key_name, $prefix, $i), $query);
            $this->format($vals, $v, sprintf($value_name, $prefix, $i), $query);
        }
    }
    protected function format_blob(Shape $shape, $value, $prefix, array &$query)
    {
        $query[$prefix] = base64_encode((string) $value);
    }
    protected function format_timestamp(Timestamp_Shape $shape, $value, $prefix, array &$query)
    {
        $timestamp_format = !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : 'iso8601';
        $query[$prefix] = Timestamp_Shape::format($value, $timestamp_format);
    }
    protected function format_boolean(Shape $shape, $value, $prefix, array &$query)
    {
        $query[$prefix] = $value ? 'true' : 'false';
    }
}