<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Exception\Crypto_Exception;
use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Stream_Decorator_Trait;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Represents a stream of data to be gcm decrypted.
 */
class Aes_Gcm_Decrypting_Stream implements Aes_Stream_Interface
{
    use Stream_Decorator_Trait;
    private $cipher_text;
    /**
     * @var StreamInterface
     */
    private $stream;
    /**
     * @param string $key
     * @param string $initializationVector
     * @param string $tag
     * @param string $aad
     * @param int $tagLength
     * @param int $keySize
     */
    public function __construct(Stream_Interface $cipher_text, private $key, private $initialization_vector, private $tag, private $aad = '', private $tag_length = 128, private $key_size = 256)
    {
        $this->cipher_text = $cipher_text;
        // unsetting the property forces the first access to go through
        // __get().
        unset($this->stream);
    }
    public function get_open_ssl_name(): string
    {
        return "aes-{$this->key_size}-gcm";
    }
    public function get_aes_name(): string
    {
        return 'AES/GCM/NoPadding';
    }
    public function get_current_iv()
    {
        return $this->initialization_vector;
    }
    public function create_stream()
    {
        $result = \openssl_decrypt((string) $this->cipher_text, $this->get_open_ssl_name(), $this->key, OPENSSL_RAW_DATA, $this->initialization_vector, $this->tag, $this->aad);
        if ($result === false) {
            throw new Crypto_Exception('The requested object could not be ' . 'decrypted due to an invalid authentication tag.');
        }
        return Psr7\Utils::stream_for($result);
    }
    public function is_writable(): bool
    {
        return false;
    }
}