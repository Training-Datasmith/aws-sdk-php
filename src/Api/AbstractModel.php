<?php
namespace Aws\Api;

/**
 * Base class that is used by most API shapes
 */
abstract class AbstractModel implements \ArrayAccess
{
    /** @var array */
    protected $contextParam;

    /**
     * @param array    $definition Service description
     * @param ShapeMap $shapeMap   Shapemap used for creating shapes
     */
    public function __construct(protected array $definition, protected \Aws\Api\ShapeMap $shapeMap)
    {
        if (isset($this->definition['contextParam'])) {
            $this->contextParam = $this->definition['contextParam'];
        }
    }

    public function toArray()
    {
        return $this->definition;
    }

    /**
     * @return mixed|null
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->definition[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        $this->definition[$offset] = $value;
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->definition[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->definition[$offset]);
    }

    protected function shapeAt(string $key)
    {
        if (!isset($this->definition[$key])) {
            throw new \InvalidArgumentException('Expected shape definition at '
                . $key);
        }

        return $this->shapeFor($this->definition[$key]);
    }

    protected function shapeFor(array $definition)
    {
        return isset($definition['shape'])
            ? $this->shapeMap->resolve($definition)
            : Shape::create($definition, $this->shapeMap);
    }
}
