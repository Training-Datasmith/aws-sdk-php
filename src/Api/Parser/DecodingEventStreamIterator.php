<?php

namespace Aws\Api\Parser;

use \Iterator;
use Aws\Api\DateTimeResult;
use GuzzleHttp\Psr7;
use Psr\Http\Message\StreamInterface;
use Aws\Api\Parser\Exception\ParserException;

/**
 * @internal Implements a decoder for a binary encoded event stream that will
 * decode, validate, and provide individual events from the stream.
 */
class DecodingEventStreamIterator implements Iterator
{
    const HEADERS = 'headers';
    const PAYLOAD = 'payload';

    const LENGTH_TOTAL = 'total_length';
    const LENGTH_HEADERS = 'headers_length';

    const CRC_PRELUDE = 'prelude_crc';

    const BYTES_PRELUDE = 12;
    const BYTES_TRAILING = 4;

    private static array $preludeFormat = [
        self::LENGTH_TOTAL => 'decodeUint32',
        self::LENGTH_HEADERS => 'decodeUint32',
        self::CRC_PRELUDE => 'decodeUint32',
    ];

    private static array $lengthFormatMap = [
        1 => 'decodeUint8',
        2 => 'decodeUint16',
        4 => 'decodeUint32',
        8 => 'decodeUint64',
    ];

    private static array $headerTypeMap = [
        0 => 'decodeBooleanTrue',
        1 => 'decodeBooleanFalse',
        2 => 'decodeInt8',
        3 => 'decodeInt16',
        4 => 'decodeInt32',
        5 => 'decodeInt64',
        6 => 'decodeBytes',
        7 => 'decodeString',
        8 => 'decodeTimestamp',
        9 => 'decodeUuid',
    ];

    /** @var StreamInterface Stream of eventstream shape to parse. */
    protected $stream;

    /** @var array Currently parsed event. */
    protected $currentEvent;

    /** @var int Current in-order event key. */
    protected $key;

    /** @var resource|\HashContext CRC32 hash context for event validation */
    protected $hashContext;

    /** @var int $currentPosition */
    protected $currentPosition;

    /**
     * DecodingEventStreamIterator constructor.
     */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
        $this->rewind();
    }

    protected function parseHeaders($headerBytes): array
    {
        $headers = [];
        $bytesRead = 0;

        while ($bytesRead < $headerBytes) {
            [$key, $numBytes] = $this->decodeString(1);
            $bytesRead += $numBytes;

            [$type, $numBytes] = $this->decodeUint8();
            $bytesRead += $numBytes;

            $f = self::$headerTypeMap[$type];
            [$value, $numBytes] = $this->{$f}();
            $bytesRead += $numBytes;

            if (isset($headers[$key])) {
                throw new ParserException('Duplicate key in event headers.');
            }
            $headers[$key] = $value;
        }

        return [$headers, $bytesRead];
    }

    protected function parsePrelude(): array
    {
        $prelude = [];
        $bytesRead = 0;

        $calculatedCrc = null;
        foreach (self::$preludeFormat as $key => $decodeFunction) {
            if ($key === self::CRC_PRELUDE) {
                $hashCopy = hash_copy($this->hashContext);
                $calculatedCrc = hash_final($this->hashContext, true);
                $this->hashContext = $hashCopy;
            }
            [$value, $numBytes] = $this->{$decodeFunction}();
            $bytesRead += $numBytes;

            $prelude[$key] = $value;
        }

        if (unpack('N', (string) $calculatedCrc)[1] !== $prelude[self::CRC_PRELUDE]) {
            throw new ParserException('Prelude checksum mismatch.');
        }

        return [$prelude, $bytesRead];
    }

    /**
     * This method decodes an event from the stream.
     */
    protected function parseEvent(): array
    {
        $event = [];

        if ($this->stream->tell() < $this->stream->getSize()) {
            $this->hashContext = hash_init('crc32b');

            $bytesLeft = $this->stream->getSize() - $this->stream->tell();
            [$prelude, $numBytes] = $this->parsePrelude();
            if ($prelude[self::LENGTH_TOTAL] > $bytesLeft) {
                throw new ParserException('Message length too long.');
            }
            $bytesLeft -= $numBytes;

            if ($prelude[self::LENGTH_HEADERS] > $bytesLeft) {
                throw new ParserException('Headers length too long.');
            }

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
        }

        return $event;
    }

    // Iterator Functionality

    /**
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->currentEvent;
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->key;
    }

    #[\ReturnTypeWillChange]
    public function next(): void
    {
        $this->currentPosition = $this->stream->tell();
        if ($this->valid()) {
            $this->key++;
            $this->currentEvent = $this->parseEvent();
        }
    }

    #[\ReturnTypeWillChange]
    public function rewind(): void
    {
        $this->stream->rewind();
        $this->key = 0;
        $this->currentPosition = 0;
        $this->currentEvent = $this->parseEvent();
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        return $this->currentPosition < $this->stream->getSize();
    }

    // Decoding Utilities

    protected function readAndHashBytes($num)
    {
        $bytes = $this->stream->read($num);
        hash_update($this->hashContext, $bytes);
        return $bytes;
    }

    private function decodeBooleanTrue(): array
    {
        return [true, 0];
    }

    private function decodeBooleanFalse(): array
    {
        return [false, 0];
    }

    private function uintToInt($val, int $size)
    {
        $signedCap = 2 ** ($size - 1);
        if ($val > $signedCap) {
            $val -= (2 * $signedCap);
        }
        return $val;
    }

    private function decodeInt8(): array
    {
        $val = (int)unpack('C', (string) $this->readAndHashBytes(1))[1];
        return [$this->uintToInt($val, 8), 1];
    }

    private function decodeUint8(): array
    {
        return [unpack('C', (string) $this->readAndHashBytes(1))[1], 1];
    }

    private function decodeInt16(): array
    {
        $val = (int)unpack('n', (string) $this->readAndHashBytes(2))[1];
        return [$this->uintToInt($val, 16), 2];
    }

    private function decodeUint16(): array
    {
        return [unpack('n', (string) $this->readAndHashBytes(2))[1], 2];
    }

    private function decodeInt32(): array
    {
        $val = (int)unpack('N', (string) $this->readAndHashBytes(4))[1];
        return [$this->uintToInt($val, 32), 4];
    }

    private function decodeUint32(): array
    {
        return [unpack('N', (string) $this->readAndHashBytes(4))[1], 4];
    }

    private function decodeInt64(): array
    {
        $val = $this->unpackInt64($this->readAndHashBytes(8))[1];
        return [$this->uintToInt($val, 64), 8];
    }

    private function decodeUint64(): array
    {
        return [$this->unpackInt64($this->readAndHashBytes(8))[1], 8];
    }

    private function unpackInt64($bytes): array|false
    {
        return unpack('J', $bytes);
    }

    private function decodeBytes($lengthBytes=2): array
    {
        if (!isset(self::$lengthFormatMap[$lengthBytes])) {
            throw new ParserException('Undefined variable length format.');
        }
        $f = self::$lengthFormatMap[$lengthBytes];
        [$len, $bytes] = $this->{$f}();
        return [$this->readAndHashBytes($len), $len + $bytes];
    }

    private function decodeString(int $lengthBytes=2): array
    {
        if (!isset(self::$lengthFormatMap[$lengthBytes])) {
            throw new ParserException('Undefined variable length format.');
        }
        $f = self::$lengthFormatMap[$lengthBytes];
        [$len, $bytes] = $this->{$f}();
        return [$this->readAndHashBytes($len), $len + $bytes];
    }

    private function decodeTimestamp(): array
    {
        [$val, $bytes] = $this->decodeInt64();
        return [
            DateTimeResult::createFromFormat('U.u', $val / 1000),
            $bytes
        ];
    }

    private function decodeUuid(): array
    {
        $val = unpack('H32', (string) $this->readAndHashBytes(16))[1];
        return [
            substr((string) $val, 0, 8) . '-'
            . substr((string) $val, 8, 4) . '-'
            . substr((string) $val, 12, 4) . '-'
            . substr((string) $val, 16, 4) . '-'
            . substr((string) $val, 20, 12),
            16
        ];
    }
}
