<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Date_Time_Result;
use Aws\Api\Parser\Exception\Parser_Exception;
use Guzzle_Http\Psr7;
use Iterator;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Implements a decoder for a binary encoded event stream that will
 * decode, validate, and provide individual events from the stream.
 */
class Decoding_Event_Stream_Iterator implements Iterator
{
    public const HEADERS = 'headers';
    public const PAYLOAD = 'payload';
    public const LENGTH_TOTAL = 'total_length';
    public const LENGTH_HEADERS = 'headers_length';
    public const CRC_PRELUDE = 'prelude_crc';
    public const BYTES_PRELUDE = 12;
    public const BYTES_TRAILING = 4;
    private static array $prelude_format = [self::LENGTH_TOTAL => 'decodeUint32', self::LENGTH_HEADERS => 'decodeUint32', self::CRC_PRELUDE => 'decodeUint32'];
    private static array $length_format_map = [1 => 'decodeUint8', 2 => 'decodeUint16', 4 => 'decodeUint32', 8 => 'decodeUint64'];
    private static array $header_type_map = [0 => 'decodeBooleanTrue', 1 => 'decodeBooleanFalse', 2 => 'decodeInt8', 3 => 'decodeInt16', 4 => 'decodeInt32', 5 => 'decodeInt64', 6 => 'decodeBytes', 7 => 'decodeString', 8 => 'decodeTimestamp', 9 => 'decodeUuid'];
    /** @var StreamInterface Stream of eventstream shape to parse. */
    protected $stream;
    /** @var array Currently parsed event. */
    protected $current_event;
    /** @var int Current in-order event key. */
    protected $key;
    /** @var resource|\HashContext CRC32 hash context for event validation */
    protected $hash_context;
    /** @var int $currentPosition */
    protected $current_position;
    /**
     * DecodingEventStreamIterator constructor.
     */
    public function __construct(Stream_Interface $stream)
    {
        $this->stream = $stream;
        $this->rewind();
    }
    protected function parse_headers($header_bytes): array
    {
        $headers = [];
        $bytes_read = 0;
        while ($bytes_read < $header_bytes) {
            [$key, $num_bytes] = $this->decode_string(1);
            $bytes_read += $num_bytes;
            [$type, $num_bytes] = $this->decode_uint8();
            $bytes_read += $num_bytes;
            $f = self::$header_type_map[$type];
            [$value, $num_bytes] = $this->{$f}();
            $bytes_read += $num_bytes;
            if (isset($headers[$key])) {
                throw new Parser_Exception('Duplicate key in event headers.');
            }
            $headers[$key] = $value;
        }
        return [$headers, $bytes_read];
    }
    protected function parse_prelude(): array
    {
        $prelude = [];
        $bytes_read = 0;
        $calculated_crc = null;
        foreach (self::$prelude_format as $key => $decode_function) {
            if ($key === self::CRC_PRELUDE) {
                $hash_copy = hash_copy($this->hash_context);
                $calculated_crc = hash_final($this->hash_context, true);
                $this->hash_context = $hash_copy;
            }
            [$value, $num_bytes] = $this->{$decode_function}();
            $bytes_read += $num_bytes;
            $prelude[$key] = $value;
        }
        if (unpack('N', (string) $calculated_crc)[1] !== $prelude[self::CRC_PRELUDE]) {
            throw new Parser_Exception('Prelude checksum mismatch.');
        }
        return [$prelude, $bytes_read];
    }
    /**
     * This method decodes an event from the stream.
     */
    protected function parse_event(): array
    {
        $event = [];
        if ($this->stream->tell() < $this->stream->get_size()) {
            $this->hash_context = hash_init('crc32b');
            $bytes_left = $this->stream->get_size() - $this->stream->tell();
            [$prelude, $num_bytes] = $this->parse_prelude();
            if ($prelude[self::LENGTH_TOTAL] > $bytes_left) {
                throw new Parser_Exception('Message length too long.');
            }
            $bytes_left -= $num_bytes;
            if ($prelude[self::LENGTH_HEADERS] > $bytes_left) {
                throw new Parser_Exception('Headers length too long.');
            }
            [$event[self::HEADERS], $num_bytes] = $this->parse_headers($prelude[self::LENGTH_HEADERS]);
            $event[self::PAYLOAD] = Psr7\Utils::stream_for($this->read_and_hash_bytes($prelude[self::LENGTH_TOTAL] - self::BYTES_PRELUDE - $num_bytes - self::BYTES_TRAILING));
            $calculated_crc = hash_final($this->hash_context, true);
            $message_crc = $this->stream->read(4);
            if ($calculated_crc !== $message_crc) {
                throw new Parser_Exception('Message checksum mismatch.');
            }
        }
        return $event;
    }
    // Iterator Functionality
    /**
     * @return array
     */
    #[\Return_Type_Will_Change]
    public function current()
    {
        return $this->current_event;
    }
    /**
     * @return int
     */
    #[\Return_Type_Will_Change]
    public function key()
    {
        return $this->key;
    }
    #[\Return_Type_Will_Change]
    public function next(): void
    {
        $this->current_position = $this->stream->tell();
        if ($this->valid()) {
            $this->key++;
            $this->current_event = $this->parse_event();
        }
    }
    #[\Return_Type_Will_Change]
    public function rewind(): void
    {
        $this->stream->rewind();
        $this->key = 0;
        $this->current_position = 0;
        $this->current_event = $this->parse_event();
    }
    /**
     * @return bool
     */
    #[\Return_Type_Will_Change]
    public function valid()
    {
        return $this->current_position < $this->stream->get_size();
    }
    // Decoding Utilities
    protected function read_and_hash_bytes($num)
    {
        $bytes = $this->stream->read($num);
        hash_update($this->hash_context, $bytes);
        return $bytes;
    }
    private function decode_boolean_true(): array
    {
        return [true, 0];
    }
    private function decode_boolean_false(): array
    {
        return [false, 0];
    }
    private function uint_to_int($val, int $size)
    {
        $signed_cap = 2 ** ($size - 1);
        if ($val > $signed_cap) {
            $val -= 2 * $signed_cap;
        }
        return $val;
    }
    private function decode_int8(): array
    {
        $val = (int) unpack('C', (string) $this->read_and_hash_bytes(1))[1];
        return [$this->uint_to_int($val, 8), 1];
    }
    private function decode_uint8(): array
    {
        return [unpack('C', (string) $this->read_and_hash_bytes(1))[1], 1];
    }
    private function decode_int16(): array
    {
        $val = (int) unpack('n', (string) $this->read_and_hash_bytes(2))[1];
        return [$this->uint_to_int($val, 16), 2];
    }
    private function decode_uint16(): array
    {
        return [unpack('n', (string) $this->read_and_hash_bytes(2))[1], 2];
    }
    private function decode_int32(): array
    {
        $val = (int) unpack('N', (string) $this->read_and_hash_bytes(4))[1];
        return [$this->uint_to_int($val, 32), 4];
    }
    private function decode_uint32(): array
    {
        return [unpack('N', (string) $this->read_and_hash_bytes(4))[1], 4];
    }
    private function decode_int64(): array
    {
        $val = $this->unpack_int64($this->read_and_hash_bytes(8))[1];
        return [$this->uint_to_int($val, 64), 8];
    }
    private function decode_uint64(): array
    {
        return [$this->unpack_int64($this->read_and_hash_bytes(8))[1], 8];
    }
    private function unpack_int64($bytes): array|false
    {
        return unpack('J', $bytes);
    }
    private function decode_bytes($length_bytes = 2): array
    {
        if (!isset(self::$length_format_map[$length_bytes])) {
            throw new Parser_Exception('Undefined variable length format.');
        }
        $f = self::$length_format_map[$length_bytes];
        [$len, $bytes] = $this->{$f}();
        return [$this->read_and_hash_bytes($len), $len + $bytes];
    }
    private function decode_string(int $length_bytes = 2): array
    {
        if (!isset(self::$length_format_map[$length_bytes])) {
            throw new Parser_Exception('Undefined variable length format.');
        }
        $f = self::$length_format_map[$length_bytes];
        [$len, $bytes] = $this->{$f}();
        return [$this->read_and_hash_bytes($len), $len + $bytes];
    }
    private function decode_timestamp(): array
    {
        [$val, $bytes] = $this->decode_int64();
        return [Date_Time_Result::create_from_format('U.u', $val / 1000), $bytes];
    }
    private function decode_uuid(): array
    {
        $val = unpack('H32', (string) $this->read_and_hash_bytes(16))[1];
        return [substr((string) $val, 0, 8) . '-' . substr((string) $val, 8, 4) . '-' . substr((string) $val, 12, 4) . '-' . substr((string) $val, 16, 4) . '-' . substr((string) $val, 20, 12), 16];
    }
}