<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Crypto\Cipher\Cipher_Method;
use Guzzle_Http\Psr7\Stream_Decorator_Trait;
use LogicException;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Represents a stream of data to be encrypted with a passed cipher.
 */
class Aes_Encrypting_Stream implements Aes_Stream_Interface
{
    use Stream_Decorator_Trait;
    public const BLOCK_SIZE = 16;
    // 128 bits
    private string $buffer = '';
    private \Aws\Crypto\Cipher\Cipher_Method $cipher_method;
    /**
     * @var StreamInterface
     */
    private $stream;
    /**
     * @param string $key
     */
    public function __construct(Stream_Interface $plain_text, private $key, Cipher_Method $cipher_method)
    {
        $this->stream = $plain_text;
        $this->cipher_method = clone $cipher_method;
    }
    public function get_open_ssl_name()
    {
        return $this->cipher_method->get_open_ssl_name();
    }
    public function get_aes_name()
    {
        return $this->cipher_method->get_aes_name();
    }
    public function get_current_iv()
    {
        return $this->cipher_method->get_current_iv();
    }
    public function get_size(): ?int
    {
        $plain_text_size = $this->stream->get_size();
        if ($this->cipher_method->requires_padding() && $plain_text_size !== null) {
            // PKCS7 padding requires that between 1 and self::BLOCK_SIZE be
            // added to the plaintext to make it an even number of blocks.
            $padding = self::BLOCK_SIZE - $plain_text_size % self::BLOCK_SIZE;
            return $plain_text_size + $padding;
        }
        return $plain_text_size;
    }
    public function is_writable(): bool
    {
        return false;
    }
    public function read($length): string
    {
        if ($length > strlen($this->buffer)) {
            $this->buffer .= $this->encrypt_block(self::BLOCK_SIZE * ceil(($length - strlen($this->buffer)) / self::BLOCK_SIZE));
        }
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $data ?: '';
    }
    public function seek($offset, $whence = SEEK_SET): void
    {
        if ($whence === SEEK_CUR) {
            $offset = $this->tell() + $offset;
            $whence = SEEK_SET;
        }
        if ($whence === SEEK_SET) {
            $this->buffer = '';
            $whole_block_offset = (int) ($offset / self::BLOCK_SIZE) * self::BLOCK_SIZE;
            $this->stream->seek($whole_block_offset);
            $this->cipher_method->seek($whole_block_offset);
            $this->read($offset - $whole_block_offset);
        } else {
            throw new LogicException('Unrecognized whence.');
        }
    }
    private function encrypt_block(float $length): string|false
    {
        if ($this->stream->eof()) {
            return '';
        }
        $plain_text = '';
        do {
            $plain_text .= $this->stream->read((int) ($length - strlen($plain_text)));
        } while (strlen($plain_text) < $length && !$this->stream->eof());
        $options = OPENSSL_RAW_DATA;
        if (!$this->stream->eof() || $this->stream->get_size() !== $this->stream->tell()) {
            $options |= OPENSSL_ZERO_PADDING;
        }
        $cipher_text = openssl_encrypt($plain_text, $this->cipher_method->get_open_ssl_name(), $this->key, $options, $this->cipher_method->get_current_iv());
        $this->cipher_method->update($cipher_text);
        return $cipher_text;
    }
}