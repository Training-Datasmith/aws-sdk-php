<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Represents a map shape.
 */
class Map_Shape extends Shape
{
    /** @var Shape */
    private $value;
    /** @var Shape */
    private $key;
    public function __construct(array $definition, Shape_Map $shape_map)
    {
        $definition['type'] = 'map';
        parent::__construct($definition, $shape_map);
    }
    /**
     * @return Shape
     * @throws \RuntimeException if no value is specified
     */
    public function get_value()
    {
        if (!$this->value) {
            if (!isset($this->definition['value'])) {
                throw new \RuntimeException('No value specified');
            }
            $this->value = Shape::create($this->definition['value'], $this->shape_map);
        }
        return $this->value;
    }
    /**
     * @return Shape
     */
    public function get_key()
    {
        if (!$this->key) {
            $this->key = isset($this->definition['key']) ? Shape::create($this->definition['key'], $this->shape_map) : new Shape(['type' => 'string'], $this->shape_map);
        }
        return $this->key;
    }
}