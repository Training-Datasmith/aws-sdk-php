<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\List_Shape;
use Aws\Api\Map_Shape;
use Aws\Api\Shape;
use Aws\Api\Structure_Shape;
use Aws\Api\Timestamp_Shape;
use Xml_Writer;
/**
 * @internal Formats the XML body of a REST-XML services.
 */
class Xml_Body
{
    public function __construct()
    {
    }
    /**
     * Builds the XML body based on an array of arguments.
     *
     * @param Shape $shape Operation being constructed
     * @param array $args  Associative array of arguments
     */
    public function build(Shape $shape, array $args): string
    {
        $xml = new Xml_Writer();
        $xml->open_memory();
        $xml->start_document('1.0', 'UTF-8');
        $root_element_name = $this->determine_root_element_name($shape);
        $this->format($shape, $root_element_name, $args, $xml);
        $xml->end_document();
        return $xml->output_memory();
    }
    private function start_element(Shape $shape, $name, Xml_Writer $xml): void
    {
        $xml->start_element($name);
        if ($ns = $shape['xmlNamespace']) {
            $xml->write_attribute(isset($ns['prefix']) ? "xmlns:{$ns['prefix']}" : 'xmlns', $ns['uri']);
        }
    }
    private function format(Shape $shape, $name, $value, Xml_Writer $xml): void
    {
        // Any method mentioned here has a custom serialization handler.
        static $methods = ['add_structure' => true, 'add_list' => true, 'add_blob' => true, 'add_timestamp' => true, 'add_boolean' => true, 'add_map' => true, 'add_string' => true];
        $type = 'add_' . $shape['type'];
        if (isset($methods[$type])) {
            $this->{$type}($shape, $name, $value, $xml);
        } else {
            $this->default_shape($shape, $name, $value, $xml);
        }
    }
    private function default_shape(Shape $shape, $name, $value, Xml_Writer $xml): void
    {
        $this->start_element($shape, $name, $xml);
        $xml->text($value);
        $xml->end_element();
    }
    private function add_structure(Structure_Shape $shape, $name, array $value, \Xml_Writer $xml): void
    {
        $this->start_element($shape, $name, $xml);
        foreach ($this->get_structure_members($shape, $value) as $k => $definition) {
            // Default to member name
            $element_name = $k;
            if ($definition['member']['locationName'] && !isset($definition['member']['locationNameAtStructureLevel'])) {
                $element_name = $definition['member']['locationName'];
            }
            $this->format($definition['member'], $element_name, $definition['value'], $xml);
        }
        $xml->end_element();
    }
    /**
     * @return array{member: mixed, value: mixed}[]
     */
    private function get_structure_members(Structure_Shape $shape, array $value): array
    {
        $members = [];
        foreach ($value as $k => $v) {
            if ($v !== null && $shape->has_member($k)) {
                $definition = ['member' => $shape->get_member($k), 'value' => $v];
                if ($definition['member']['xmlAttribute']) {
                    // array_unshift_associative
                    $members = [$k => $definition] + $members;
                } else {
                    $members[$k] = $definition;
                }
            }
        }
        return $members;
    }
    private function add_list(List_Shape $shape, $name, array $value, Xml_Writer $xml): void
    {
        $items = $shape->get_member();
        if ($shape['flattened']) {
            $element_name = $name;
        } else {
            $this->start_element($shape, $name, $xml);
            $element_name = $items['locationName'] ?: 'member';
        }
        foreach ($value as $v) {
            $this->format($items, $element_name, $v, $xml);
        }
        if (!$shape['flattened']) {
            $xml->end_element();
        }
    }
    private function add_map(Map_Shape $shape, $name, array $value, Xml_Writer $xml): void
    {
        $xml_entry = $shape['flattened'] ? $name : 'entry';
        $xml_key = $shape->get_key()['locationName'] ?: 'key';
        $xml_value = $shape->get_value()['locationName'] ?: 'value';
        if (!$shape['flattened']) {
            $this->start_element($shape, $name, $xml);
        }
        foreach ($value as $key => $v) {
            $this->start_element($shape, $xml_entry, $xml);
            $this->format($shape->get_key(), $xml_key, $key, $xml);
            $this->format($shape->get_value(), $xml_value, $v, $xml);
            $xml->end_element();
        }
        if (!$shape['flattened']) {
            $xml->end_element();
        }
    }
    private function add_blob(Shape $shape, $name, $value, Xml_Writer $xml): void
    {
        $this->start_element($shape, $name, $xml);
        $xml->write_raw(base64_encode((string) $value));
        $xml->end_element();
    }
    private function add_timestamp(Timestamp_Shape $shape, $name, $value, Xml_Writer $xml): void
    {
        $this->start_element($shape, $name, $xml);
        $timestamp_format = !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : 'iso8601';
        $xml->write_raw(Timestamp_Shape::format($value, $timestamp_format));
        $xml->end_element();
    }
    private function add_boolean(Shape $shape, $name, $value, Xml_Writer $xml): void
    {
        $this->start_element($shape, $name, $xml);
        $xml->write_raw($value ? 'true' : 'false');
        $xml->end_element();
    }
    private function add_string(Shape $shape, $name, $value, Xml_Writer $xml): void
    {
        if ($shape['xmlAttribute']) {
            $xml->write_attribute($shape['locationName'] ?: $name, $value);
        } else {
            $this->default_shape($shape, $name, $value, $xml);
        }
    }
    private function determine_root_element_name(Shape $shape): string
    {
        $shape_name = $shape->get_name();
        // Look up the shape definition first
        if ($shape_name && $shape_map = $shape->get_shape_map()) {
            if (isset($shape_map[$shape_name]['locationName'])) {
                return $shape_map[$shape_name]['locationName'];
            }
        }
        // Fall back to shape's current locationName
        if ($shape['locationName']) {
            return $shape['locationName'];
        }
        return $shape_name;
    }
}