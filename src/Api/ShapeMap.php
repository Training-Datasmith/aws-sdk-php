<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Builds shape based on shape references.
 */
class Shape_Map implements \ArrayAccess
{
    /** @var Shape[] */
    private $simple;
    /**
     * @param array $definitions Associative array of shape definitions.
     */
    public function __construct(private array $definitions)
    {
    }
    /**
     * Get an array of shape names.
     */
    public function get_shape_names(): array
    {
        return array_keys($this->definitions);
    }
    /**
     * Resolve a shape reference
     *
     * @param array $shapeRef Shape reference shape
     *
     * @return Shape
     * @throws \InvalidArgumentException
     */
    public function resolve(array $shape_ref)
    {
        $shape = $shape_ref['shape'];
        if (!isset($this->definitions[$shape])) {
            throw new \InvalidArgumentException('Shape not found: ' . $shape);
        }
        $is_simple = count($shape_ref) == 1;
        if ($is_simple && isset($this->simple[$shape])) {
            return $this->simple[$shape];
        }
        $shape_definition = $this->definitions[$shape];
        $definition = $shape_ref + $shape_definition;
        // Property to know whether the locationName was set at member level
        // or the structure level.
        if (isset($shape_definition['locationName'])) {
            $definition['locationNameAtStructureLevel'] = true;
        }
        $definition['name'] = $definition['shape'];
        if (isset($definition['shape'])) {
            unset($definition['shape']);
        }
        $result = Shape::create($definition, $this);
        if ($is_simple) {
            $this->simple[$shape] = $result;
        }
        return $result;
    }
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->definitions[$offset]);
    }
    public function offsetGet(mixed $offset): mixed
    {
        return $this->definitions[$offset] ?? null;
    }
    /**
     * @throws \BadMethodCallException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('ShapeMap is read-only and cannot be modified.');
    }
    /**
     * @throws \BadMethodCallException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('ShapeMap is read-only and cannot be modified.');
    }
}