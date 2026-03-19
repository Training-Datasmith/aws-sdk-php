<?php

declare(strict_types=1);

namespace Aws\Crypto;

use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * @internal Represents a stream of data to be gcm encrypted.
 */
class AesGcmEncryptingStream implements AesStreamInterface, AesStreamInterfaceV2
{
    use StreamDecoratorTrait;

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
    public static function getStaticAesName(): string
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
    public function __construct(
        StreamInterface $plaintext,
        private $key,
        private $initializationVector,
        private $aad = '',
        private $tagLength = 16,
        private $keySize = 256
    ) {

        $this->plaintext = $plaintext;
        // unsetting the property forces the first access to go through
        // __get().
        unset($this->stream);
    }

    public function getOpenSslName(): string
    {
        return "aes-{$this->keySize}-gcm";
    }

    /**
     * Same as static method and retained for backwards compatibility
     *
     * @return string
     */
    public function getAesName()
    {
        return self::getStaticAesName();
    }

    public function getCurrentIv()
    {
        return $this->initializationVector;
    }

    public function createStream()
    {
        return Psr7\Utils::streamFor(\openssl_encrypt(
            (string)$this->plaintext,
            $this->getOpenSslName(),
            $this->key,
            OPENSSL_RAW_DATA,
            $this->initializationVector,
            $this->tag,
            $this->aad,
            $this->tagLength
        ));
    }

    /**
     * @return string
     */
    public function getTag()
    {
        return $this->tag;
    }

    public function isWritable(): bool
    {
        return false;
    }
}
