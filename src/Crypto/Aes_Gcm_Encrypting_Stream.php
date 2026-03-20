<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Stream_Decorator_Trait;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Represents a stream of data to be gcm encrypted.
 */
class Aes_Gcm_Encrypting_Stream implements Aes_Stream_Interface, Aes_Stream_Interface_V2
{
    use Stream_Decorator_Trait;
    private $plaintext;
    private string $tag = '';
    /**
     * @var StreamInterface
     */
    private $stream;
    /**
     * Same as non-static 'getAesName' method, allowing calls in a static
     * context.
     */
    public static function get_static_aes_name(): string
    {
        return 'AES/GCM/NoPadding';
    }
    /**
     * @param string $key
     * @param string $initializationVector
     * @param string $aad
     * @param int $tagLength
     * @param int $keySize
     */
    public function __construct(Stream_Interface $plaintext, private $key, private $initialization_vector, private $aad = '', private $tag_length = 16, private $key_size = 256)
    {
        $this->plaintext = $plaintext;
        // unsetting the property forces the first access to go through
        // __get().
        unset($this->stream);
    }
    public function get_open_ssl_name(): string
    {
        return "aes-{$this->key_size}-gcm";
    }
    /**
     * Same as static method and retained for backwards compatibility
     *
     * @return string
     */
    public function get_aes_name()
    {
        return self::get_static_aes_name();
    }
    public function get_current_iv()
    {
        return $this->initialization_vector;
    }
    public function create_stream()
    {
        return Psr7\Utils::stream_for(\openssl_encrypt((string) $this->plaintext, $this->get_open_ssl_name(), $this->key, OPENSSL_RAW_DATA, $this->initialization_vector, $this->tag, $this->aad, $this->tag_length));
    }
    /**
     * @return string
     */
    public function get_tag()
    {
        return $this->tag;
    }
    public function is_writable(): bool
    {
        return false;
    }
}