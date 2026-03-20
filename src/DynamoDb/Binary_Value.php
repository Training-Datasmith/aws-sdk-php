<?php
namespace Aws\DynamoDb;

use GuzzleHttp\Psr7;

/**
 * Special object to represent a DynamoDB binary (B) value.
 */
class BinaryValue implements \JsonSerializable, \Stringable
{
    /** @var string Binary value. */
    private readonly string $value;

    /**
     * @param mixed $value A binary value compatible with Guzzle streams.
     *
     * @see GuzzleHttp\Stream\Stream::factory
     */
    public function __construct($value)
    {
        if (!is_string($value)) {
            $value = Psr7\Utils::streamFor($value);
        }
        $this->value = (string) $value;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
