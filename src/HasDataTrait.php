<?php
namespace Aws;

/**
 * Trait implementing ToArrayInterface, \ArrayAccess, \Countable, and
 * \IteratorAggregate
 */
trait HasDataTrait
{
    /** @var array */
    private $data = [];

    /**
     * @return \Traversable
     */
    #[\ReturnTypeWillChange]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * This method returns a reference to the variable to allow for indirect
     * array modification (e.g., $foo['bar']['baz'] = 'qux').
     *
     * @param $offset
     *
     * @return mixed|null
     */
    #[\ReturnTypeWillChange]
    public function & offsetGet($offset)
    {
        if (isset($this->data[$offset])) {
            return $this->data[$offset];
        }

        $value = null;
        return $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        $this->data[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    public function toArray()
    {
        return $this->data;
    }

    #[\ReturnTypeWillChange]
    public function count(): int
    {
        return count($this->data);
    }
}
