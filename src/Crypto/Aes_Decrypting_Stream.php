<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Crypto\Cipher\Cipher_Method;
use Guzzle_Http\Psr7\Stream_Decorator_Trait;
use LogicException;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Represents a stream of data to be decrypted with passed cipher.
 */
class Aes_Decrypting_Stream implements Aes_Stream_Interface
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
    public function __construct(Stream_Interface $cipher_text, private $key, Cipher_Method $cipher_method)
    {
        $this->stream = $cipher_text;
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
        if ($this->cipher_method->requires_padding()) {
            // PKCS7 padding requires that between 1 and self::BLOCK_SIZE be
            // added to the plaintext to make it an even number of blocks. The
            // plaintext is between strlen($cipherText) - self::BLOCK_SIZE and
            // strlen($cipherText) - 1
            return null;
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
            $this->buffer .= $this->decrypt_block((int) (self::BLOCK_SIZE * ceil(($length - strlen($this->buffer)) / self::BLOCK_SIZE)));
        }
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $data ?: '';
    }
    public function seek($offset, $whence = SEEK_SET): void
    {
        if ($offset === 0 && $whence === SEEK_SET) {
            $this->buffer = '';
            $this->cipher_method->seek(0, SEEK_SET);
            $this->stream->seek(0, SEEK_SET);
        } else {
            throw new LogicException('AES encryption streams only support being' . ' rewound, not arbitrary seeking.');
        }
    }
    private function decrypt_block(int $length): string|false
    {
        if ($this->stream->eof()) {
            return '';
        }
        $cipher_text = '';
        do {
            $cipher_text .= $this->stream->read($length - strlen($cipher_text));
        } while (strlen($cipher_text) < $length && !$this->stream->eof());
        $options = OPENSSL_RAW_DATA;
        if (!$this->stream->eof() && $this->stream->get_size() !== $this->stream->tell()) {
            $options |= OPENSSL_ZERO_PADDING;
        }
        $plaintext = openssl_decrypt($cipher_text, $this->cipher_method->get_open_ssl_name(), $this->key, $options, $this->cipher_method->get_current_iv());
        $this->cipher_method->update($cipher_text);
        return $plaintext;
    }
}