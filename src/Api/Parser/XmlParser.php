<?php
namespace Aws\Api\Parser;

use Aws\Api\DateTimeResult;
use Aws\Api\ListShape;
use Aws\Api\MapShape;
use Aws\Api\Parser\Exception\ParserException;
use Aws\Api\Shape;
use Aws\Api\StructureShape;

/**
 * @internal Implements standard XML parsing for REST-XML and Query protocols.
 */
class XmlParser
{
    public function parse(StructureShape $shape, \SimpleXMLElement $value)
    {
        return $this->dispatch($shape, $value);
    }

    private function dispatch(array $shape, \SimpleXMLElement $value)
    {
        static $methods = [
            'structure' => 'parse_structure',
            'list'      => 'parse_list',
            'map'       => 'parse_map',
            'blob'      => 'parse_blob',
            'boolean'   => 'parse_boolean',
            'integer'   => 'parse_integer',
            'float'     => 'parse_float',
            'double'    => 'parse_float',
            'timestamp' => 'parse_timestamp',
        ];

        $type = $shape['type'];
        if (isset($methods[$type])) {
            return $this->{$methods[$type]}($shape, $value);
        }

        return (string) $value;
    }

    /**
     * @return mixed[]
     */
    private function parse_structure(
        StructureShape $shape,
        \SimpleXMLElement $value
    ): array {
        $target = [];

        foreach ($shape->getMembers() as $name => $member) {
            // Extract the name of the XML node
            $node = $this->memberKey($member, $name);
            if (isset($value->{$node})) {
                $target[$name] = $this->dispatch($member, $value->{$node});
            } else {
                $memberShape = $shape->getMember($name);
                if (!empty($memberShape['xmlAttribute'])) {
                    $target[$name] = $this->parse_xml_attribute(
                        $shape,
                        $memberShape,
                        $value
                    );
                }
            }
        }
        if (isset($shape['union'])
            && $shape['union']
            && empty($target)
        ) {
            foreach ($value as $val) {
                $name = $val->children()->getName();
                $target['Unknown'][$name] = $val->$name;
            }
        }
        return $target;
    }

    private function memberKey(Shape $shape, $name)
    {
        // Check if locationName came from shape definition
        if ($shape instanceof StructureShape && isset($shape['locationName'])) {
            $originalDef = $shape->getOriginalDefinition($shape->getName());

            if ($originalDef && isset($originalDef['locationName'])
                && $originalDef['locationName'] === $shape['locationName']
            ) {
                return $name;
            }
        }

        return $shape['locationName'] ?? $name;
    }

    /**
     * @return mixed[]
     */
    private function parse_list(ListShape $shape, \SimpleXMLElement  $value): array
    {
        $target = [];
        $member = $shape->getMember();

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
    private function parse_map(MapShape $shape, \SimpleXMLElement $value): array
    {
        $target = [];

        if (!$shape['flattened']) {
            $value = $value->entry;
        }

        $mapKey = $shape->getKey();
        $mapValue = $shape->getValue();
        $keyName = $shape->getKey()['locationName'] ?: 'key';
        $valueName = $shape->getValue()['locationName'] ?: 'value';

        foreach ($value as $node) {
            $key = $this->dispatch($mapKey, $node->{$keyName});
            $value = $this->dispatch($mapValue, $node->{$valueName});
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
            default => (float) $value
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
        if (is_string($value)
            || is_int($value)
            || (is_object($value)
                && method_exists($value, '__toString'))
        ) {
            return DateTimeResult::fromTimestamp(
                (string) $value,
                !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : null
            );
        }
        throw new ParserException('Invalid timestamp value passed to XmlParser::parse_timestamp');
    }

    private function parse_xml_attribute(Shape $shape, Shape $memberShape, \SimpleXMLElement $value): ?string
    {
        $namespace = $shape['xmlNamespace']['uri'] ?? '';
        $prefix = $shape['xmlNamespace']['prefix'] ?? '';
        if (!empty($prefix)) {
            $prefix .= ':';
        }
        $key = str_replace($prefix, '', $memberShape['locationName']);

        $attributes = $value->attributes($namespace);
        return isset($attributes[$key]) ? (string) $attributes[$key] : null;
    }
}
