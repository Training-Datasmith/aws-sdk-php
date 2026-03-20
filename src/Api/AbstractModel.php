<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Base class that is used by most API shapes
 */
abstract class Abstract_Model implements \ArrayAccess
{
    /** @var array */
    protected $context_param;
    /**
     * @param array    $definition Service description
     * @param ShapeMap $shapeMap   Shapemap used for creating shapes
     */
    public function __construct(protected array $definition, protected \Aws\Api\Shape_Map $shape_map)
    {
        if (isset($this->definition['contextParam'])) {
            $this->context_param = $this->definition['contextParam'];
        }
    }
    public function to_array()
    {
        return $this->definition;
    }
    /**
     * @return mixed|null
     */
    #[\Return_Type_Will_Change]
    public function offsetGet($offset)
    {
        return $this->definition[$offset] ?? null;
    }
    #[\Return_Type_Will_Change]
    public function offsetSet($offset, $value): void
    {
        $this->definition[$offset] = $value;
    }
    /**
     * @return bool
     */
    #[\Return_Type_Will_Change]
    public function offsetExists($offset)
    {
        return isset($this->definition[$offset]);
    }
    #[\Return_Type_Will_Change]
    public function offsetUnset($offset): void
    {
        unset($this->definition[$offset]);
    }
    protected function shape_at(string $key)
    {
        if (!isset($this->definition[$key])) {
            throw new \InvalidArgumentException('Expected shape definition at ' . $key);
        }
        return $this->shape_for($this->definition[$key]);
    }
    protected function shape_for(array $definition)
    {
        return isset($definition['shape']) ? $this->shape_map->resolve($definition) : Shape::create($definition, $this->shape_map);
    }
}