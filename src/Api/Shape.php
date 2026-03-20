<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Base class representing a modeled shape.
 */
class Shape extends Abstract_Model
{
    /**
     * Get a concrete shape for the given definition.
     *
     *
     * @return mixed
     * @throws \RuntimeException if the type is invalid
     */
    public static function create(array $definition, Shape_Map $shape_map)
    {
        static $map = ['structure' => Structure_Shape::class, 'map' => Map_Shape::class, 'list' => List_Shape::class, 'timestamp' => Timestamp_Shape::class, 'integer' => Shape::class, 'double' => Shape::class, 'float' => Shape::class, 'long' => Shape::class, 'string' => Shape::class, 'byte' => Shape::class, 'character' => Shape::class, 'blob' => Shape::class, 'boolean' => Shape::class];
        if (isset($definition['shape'])) {
            return $shape_map->resolve($definition);
        }
        if (!isset($map[$definition['type']])) {
            throw new \RuntimeException('Invalid type: ' . print_r($definition, true));
        }
        $type = $map[$definition['type']];
        return new $type($definition, $shape_map);
    }
    /**
     * Get the type of the shape
     *
     * @return string
     */
    public function get_type()
    {
        return $this->definition['type'];
    }
    /**
     * Get the name of the shape
     *
     * @return string
     */
    public function get_name()
    {
        return $this->definition['name'];
    }
    /**
     * Get a context param definition.
     */
    public function get_context_param()
    {
        return $this->context_param;
    }
}