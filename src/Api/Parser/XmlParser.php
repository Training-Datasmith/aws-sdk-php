<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Date_Time_Result;
use Aws\Api\List_Shape;
use Aws\Api\Map_Shape;
use Aws\Api\Parser\Exception\Parser_Exception;
use Aws\Api\Shape;
use Aws\Api\Structure_Shape;
/**
 * @internal Implements standard XML parsing for REST-XML and Query protocols.
 */
class Xml_Parser
{
    public function parse(Structure_Shape $shape, \Simple_Xml_Element $value)
    {
        return $this->dispatch($shape, $value);
    }
    private function dispatch(array $shape, \Simple_Xml_Element $value)
    {
        static $methods = ['structure' => 'parse_structure', 'list' => 'parse_list', 'map' => 'parse_map', 'blob' => 'parse_blob', 'boolean' => 'parse_boolean', 'integer' => 'parse_integer', 'float' => 'parse_float', 'double' => 'parse_float', 'timestamp' => 'parse_timestamp'];
        $type = $shape['type'];
        if (isset($methods[$type])) {
            return $this->{$methods[$type]}($shape, $value);
        }
        return (string) $value;
    }
    /**
     * @return mixed[]
     */
    private function parse_structure(Structure_Shape $shape, \Simple_Xml_Element $value): array
    {
        $target = [];
        foreach ($shape->get_members() as $name => $member) {
            // Extract the name of the XML node
            $node = $this->member_key($member, $name);
            if (isset($value->{$node})) {
                $target[$name] = $this->dispatch($member, $value->{$node});
            } else {
                $member_shape = $shape->get_member($name);
                if (!empty($member_shape['xmlAttribute'])) {
                    $target[$name] = $this->parse_xml_attribute($shape, $member_shape, $value);
                }
            }
        }
        if (isset($shape['union']) && $shape['union'] && empty($target)) {
            foreach ($value as $val) {
                $name = $val->children()->get_name();
                $target['Unknown'][$name] = $val->{$name};
            }
        }
        return $target;
    }
    private function member_key(Shape $shape, $name)
    {
        // Check if locationName came from shape definition
        if ($shape instanceof Structure_Shape && isset($shape['locationName'])) {
            $original_def = $shape->get_original_definition($shape->get_name());
            if ($original_def && isset($original_def['locationName']) && $original_def['locationName'] === $shape['locationName']) {
                return $name;
            }
        }
        return $shape['locationName'] ?? $name;
    }
    /**
     * @return mixed[]
     */
    private function parse_list(List_Shape $shape, \Simple_Xml_Element $value): array
    {
        $target = [];
        $member = $shape->get_member();
        if (!$shape['flattened']) {
            $value = $value->{$member['locationName'] ?: 'member'};
        }
        foreach ($value as $v) {
            $target[] = $this->dispatch($member, $v);
        }
        return $target;
    }
    /**
     * @return mixed[]
     */
    private function parse_map(Map_Shape $shape, \Simple_Xml_Element $value): array
    {
        $target = [];
        if (!$shape['flattened']) {
            $value = $value->entry;
        }
        $map_key = $shape->get_key();
        $map_value = $shape->get_value();
        $key_name = $shape->get_key()['locationName'] ?: 'key';
        $value_name = $shape->get_value()['locationName'] ?: 'value';
        foreach ($value as $node) {
            $key = $this->dispatch($map_key, $node->{$key_name});
            $value = $this->dispatch($map_value, $node->{$value_name});
            $target[$key] = $value;
        }
        return $target;
    }
    private function parse_blob($value): string
    {
        return base64_decode((string) $value);
    }
    private function parse_float($value): float|string
    {
        $value = (string) $value;
        return match ($value) {
            'NaN', 'Infinity', '-Infinity' => $value,
            default => (float) $value,
        };
    }
    private function parse_integer($value): int
    {
        return (int) (string) $value;
    }
    private function parse_boolean($value): bool
    {
        return $value == 'true';
    }
    private function parse_timestamp(Shape $shape, $value)
    {
        if (is_string($value) || is_int($value) || is_object($value) && method_exists($value, '__toString')) {
            return Date_Time_Result::from_timestamp((string) $value, !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : null);
        }
        throw new Parser_Exception('Invalid timestamp value passed to XmlParser::parse_timestamp');
    }
    private function parse_xml_attribute(Shape $shape, Shape $member_shape, \Simple_Xml_Element $value): ?string
    {
        $namespace = $shape['xmlNamespace']['uri'] ?? '';
        $prefix = $shape['xmlNamespace']['prefix'] ?? '';
        if (!empty($prefix)) {
            $prefix .= ':';
        }
        $key = str_replace($prefix, '', $member_shape['locationName']);
        $attributes = $value->attributes($namespace);
        return isset($attributes[$key]) ? (string) $attributes[$key] : null;
    }
}