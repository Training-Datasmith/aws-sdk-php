<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Parser\Exception\Parser_Exception;
use Guzzle_Http\Psr7;
use Psr\Http\Message\Stream_Interface;
/**
 * @inheritDoc
 */
class Non_Seekable_Stream_Decoding_Event_Stream_Iterator extends Decoding_Event_Stream_Iterator
{
    private array $temp_buffer;
    /**
     * NonSeekableStreamDecodingEventStreamIterator constructor.
     */
    public function __construct(Stream_Interface $stream)
    {
        $this->stream = $stream;
        if ($this->stream->is_seekable()) {
            throw new \InvalidArgumentException('The stream provided must be not seekable.');
        }
        $this->temp_buffer = [];
    }
    /**
     * @inheritDoc
     */
    protected function parse_event(): array
    {
        $event = [];
        $this->hash_context = hash_init('crc32b');
        $prelude = $this->parse_prelude()[0];
        [$event[self::HEADERS], $num_bytes] = $this->parse_headers($prelude[self::LENGTH_HEADERS]);
        $event[self::PAYLOAD] = Psr7\Utils::stream_for($this->read_and_hash_bytes($prelude[self::LENGTH_TOTAL] - self::BYTES_PRELUDE - $num_bytes - self::BYTES_TRAILING));
        $calculated_crc = hash_final($this->hash_context, true);
        $message_crc = $this->stream->read(4);
        if ($calculated_crc !== $message_crc) {
            throw new Parser_Exception('Message checksum mismatch.');
        }
        return $event;
    }
    protected function read_and_hash_bytes($num): string
    {
        $bytes = '';
        while (!empty($this->temp_buffer) && $num > 0) {
            $byte = array_shift($this->temp_buffer);
            $bytes .= $byte;
            $num -= 1;
        }
        // Loop until we've read the expected number of bytes
        while ($num > 0 && !$this->stream->eof()) {
            $chunk = $this->stream->read($num);
            $chunk_len = strlen((string) $chunk);
            $bytes .= $chunk;
            $num -= $chunk_len;
            if ($chunk_len === 0) {
                break;
                // Prevent infinite loop on unexpected EOF
            }
        }
        hash_update($this->hash_context, $bytes);
        return $bytes;
    }
    // Iterator Functionality
    #[\Return_Type_Will_Change]
    public function rewind(): void
    {
        $this->current_event = $this->parse_event();
    }
    public function next(): void
    {
        $this->temp_buffer[] = $this->stream->read(1);
        if ($this->valid()) {
            $this->key++;
            $this->current_event = $this->parse_event();
        }
    }
    #[\Return_Type_Will_Change]
    public function valid(): bool
    {
        return !$this->stream->eof();
    }
}