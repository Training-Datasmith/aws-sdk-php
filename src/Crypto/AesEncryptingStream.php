<?php

declare(strict_types=1);

namespace Aws\Crypto;

use Aws\Crypto\Cipher\CipherMethod;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use LogicException;
use Psr\Http\Message\StreamInterface;

/**
 * @internal Represents a stream of data to be encrypted with a passed cipher.
 */
class AesEncryptingStream implements AesStreamInterface
{
    use StreamDecoratorTrait;
    public const BLOCK_SIZE = 16; // 128 bits

    private string $buffer = '';

    private \Aws\Crypto\Cipher\CipherMethod $cipherMethod;

    /**
     * @var StreamInterface
     */
    private $stream;

    /**
     * @param string $key
     */
    public function __construct(
        StreamInterface $plainText,
        private $key,
        CipherMethod $cipherMethod
    ) {
        $this->stream = $plainText;
        $this->cipherMethod = clone $cipherMethod;
    }

    public function getOpenSslName()
    {
        return $this->cipherMethod->getOpenSslName();
    }

    public function getAesName()
    {
        return $this->cipherMethod->getAesName();
    }

    public function getCurrentIv()
    {
        return $this->cipherMethod->getCurrentIv();
    }

    public function getSize(): ?int
    {
        $plainTextSize = $this->stream->getSize();

        if ($this->cipherMethod->requiresPadding() && $plainTextSize !== null) {
            // PKCS7 padding requires that between 1 and self::BLOCK_SIZE be
            // added to the plaintext to make it an even number of blocks.
            $padding = self::BLOCK_SIZE - $plainTextSize % self::BLOCK_SIZE;
            return $plainTextSize + $padding;
        }

        return $plainTextSize;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function read($length): string
    {
        if ($length > strlen($this->buffer)) {
            $this->buffer .= $this->encryptBlock(
                self::BLOCK_SIZE * ceil(($length - strlen($this->buffer)) / self::BLOCK_SIZE)
            );
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
            $wholeBlockOffset
                = (int) ($offset / self::BLOCK_SIZE) * self::BLOCK_SIZE;
            $this->stream->seek($wholeBlockOffset);
            $this->cipherMethod->seek($wholeBlockOffset);
            $this->read($offset - $wholeBlockOffset);
        } else {
            throw new LogicException('Unrecognized whence.');
        }
    }

    private function encryptBlock(float $length): string|false
    {
        if ($this->stream->eof()) {
            return '';
        }

        $plainText = '';
        do {
            $plainText .= $this->stream->read((int) ($length - strlen($plainText)));
        } while (strlen($plainText) < $length && !$this->stream->eof());

        $options = OPENSSL_RAW_DATA;
        if (!$this->stream->eof()
            || $this->stream->getSize() !== $this->stream->tell()
        ) {
            $options |= OPENSSL_ZERO_PADDING;
        }

        $cipherText = openssl_encrypt(
            $plainText,
            $this->cipherMethod->getOpenSslName(),
            $this->key,
            $options,
            $this->cipherMethod->getCurrentIv()
        );

        $this->cipherMethod->update($cipherText);

        return $cipherText;
    }
}
