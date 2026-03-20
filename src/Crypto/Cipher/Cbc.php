<?php

declare (strict_types=1);
namespace Aws\Crypto\Cipher;

use InvalidArgumentException;
use LogicException;
/**
 * An implementation of the CBC cipher for use with an AesEncryptingStream or
 * AesDecrypting stream.
 *
 * This cipher method is deprecated and in maintenance mode - no new updates will be
 * released. Please see https://docs.aws.amazon.com/general/latest/gr/aws_sdk_cryptography.html
 * for more information.
 *
 * @deprecated
 */
class Cbc implements Cipher_Method
{
    public const BLOCK_SIZE = 16;
    /**
     * @var string
     */
    private $base_iv;
    /**
     * @var string
     */
    private $iv;
    /**
     * @param string $iv Base Initialization Vector for the cipher.
     * @param int $keySize Size of the encryption key, in bits, that will be
     *                     used.
     *
     * @throws InvalidArgumentException Thrown if the passed iv does not match
     *                                  the iv length required by the cipher.
     */
    public function __construct($iv, private $key_size = 256)
    {
        $this->base_iv = $this->iv = $iv;
        if (strlen($iv) !== openssl_cipher_iv_length($this->get_open_ssl_name())) {
            throw new InvalidArgumentException('Invalid initialization vector');
        }
    }
    public function get_open_ssl_name(): string
    {
        return "aes-{$this->key_size}-cbc";
    }
    public function get_aes_name(): string
    {
        return 'AES/CBC/PKCS5Padding';
    }
    public function get_current_iv()
    {
        return $this->iv;
    }
    public function requires_padding(): bool
    {
        return true;
    }
    public function seek($offset, $whence = SEEK_SET): void
    {
        if ($offset === 0 && $whence === SEEK_SET) {
            $this->iv = $this->base_iv;
        } else {
            throw new LogicException('CBC initialization only support being' . ' rewound, not arbitrary seeking.');
        }
    }
    public function update($cipher_text_block): void
    {
        $this->iv = substr($cipher_text_block, self::BLOCK_SIZE * -1);
    }
}