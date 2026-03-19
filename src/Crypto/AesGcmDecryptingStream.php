<?php

declare(strict_types=1);

namespace Aws\Crypto;

use Aws\Exception\CryptoException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * @internal Represents a stream of data to be gcm decrypted.
 */
class AesGcmDecryptingStream implements AesStreamInterface
{
    use StreamDecoratorTrait;

    private $cipherText;

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
    public function __construct(
        StreamInterface $cipherText,
        private $key,
        private $initializationVector,
        private $tag,
        private $aad = '',
        private $tagLength = 128,
        private $keySize = 256
    ) {
        $this->cipherText = $cipherText;
        // unsetting the property forces the first access to go through
        // __get().
        unset($this->stream);
    }

    public function getOpenSslName(): string
    {
        return "aes-{$this->keySize}-gcm";
    }

    public function getAesName(): string
    {
        return 'AES/GCM/NoPadding';
    }

    public function getCurrentIv()
    {
        return $this->initializationVector;
    }

    public function createStream()
    {

        $result = \openssl_decrypt(
            (string)$this->cipherText,
            $this->getOpenSslName(),
            $this->key,
            OPENSSL_RAW_DATA,
            $this->initializationVector,
            $this->tag,
            $this->aad
        );
        if ($result === false) {
            throw new CryptoException('The requested object could not be '
            . 'decrypted due to an invalid authentication tag.');
        }
        return Psr7\Utils::streamFor($result);

    }

    public function isWritable(): bool
    {
        return false;
    }
}
