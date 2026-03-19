<?php

declare(strict_types=1);

namespace Aws\Api\Parser;

use Aws\Api\Parser\Exception\ParserException;
use GuzzleHttp\Psr7;
use Psr\Http\Message\StreamInterface;

/**
 * @inheritDoc
 */
class NonSeekableStreamDecodingEventStreamIterator extends DecodingEventStreamIterator
{
    private array $tempBuffer;

    /**
     * NonSeekableStreamDecodingEventStreamIterator constructor.
     */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
        if ($this->stream->isSeekable()) {
            throw new \InvalidArgumentException('The stream provided must be not seekable.');
        }

        $this->tempBuffer = [];
    }

    /**
     * @inheritDoc
     */
    protected function parseEvent(): array
    {
        $event = [];
        $this->hashContext = hash_init('crc32b');
        $prelude = $this->parsePrelude()[0];
        [$event[self::HEADERS], $numBytes] = $this->parseHeaders($prelude[self::LENGTH_HEADERS]);
        $event[self::PAYLOAD] = Psr7\Utils::streamFor(
            $this->readAndHashBytes(
                $prelude[self::LENGTH_TOTAL] - self::BYTES_PRELUDE
                - $numBytes - self::BYTES_TRAILING
            )
        );
        $calculatedCrc = hash_final($this->hashContext, true);
        $messageCrc = $this->stream->read(4);
        if ($calculatedCrc !== $messageCrc) {
            throw new ParserException('Message checksum mismatch.');
        }

        return $event;
    }

    protected function readAndHashBytes($num): string
    {
        $bytes = '';
        while (!empty($this->tempBuffer) && $num > 0) {
            $byte = array_shift($this->tempBuffer);
            $bytes .= $byte;
            $num -= 1;
        }

        // Loop until we've read the expected number of bytes
        while ($num > 0 && !$this->stream->eof()) {
            $chunk = $this->stream->read($num);
            $chunkLen = strlen((string) $chunk);
            $bytes .= $chunk;
            $num -= $chunkLen;

            if ($chunkLen === 0) {
                break; // Prevent infinite loop on unexpected EOF
            }
        }

        hash_update($this->hashContext, $bytes);

        return $bytes;
    }

    // Iterator Functionality

    #[\ReturnTypeWillChange]
    public function rewind(): void
    {
        $this->currentEvent = $this->parseEvent();
    }

    public function next(): void
    {
        $this->tempBuffer[] = $this->stream->read(1);
        if ($this->valid()) {
            $this->key++;
            $this->currentEvent = $this->parseEvent();
        }
    }

    #[\ReturnTypeWillChange]
    public function valid(): bool
    {
        return !$this->stream->eof();
    }
}
