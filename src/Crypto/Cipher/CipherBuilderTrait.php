<?php

declare (strict_types=1);
namespace Aws\Crypto\Cipher;

use Aws\Exception\Crypto_Exception;
trait Cipher_Builder_Trait
{
    /**
     * Returns an identifier recognizable by `openssl_*` functions, such as
     * `aes-256-cbc` or `aes-128-ctr`.
     *
     * @param string $cipherName Name of the cipher being used for encrypting
     *                           or decrypting.
     * @param int $keySize Size of the encryption key, in bits, that will be
     *                     used.
     */
    protected function get_cipher_open_ssl_name($cipher_name, $key_size): string
    {
        return "aes-{$key_size}-{$cipher_name}";
    }
    /**
     * Constructs a CipherMethod for the given name, initialized with the other
     * data passed for use in encrypting or decrypting.
     *
     * @param string $cipherName Name of the cipher to generate for encrypting.
     * @param string $iv Base Initialization Vector for the cipher.
     * @param int $keySize Size of the encryption key, in bits, that will be
     *                     used.
     *
     * @return CipherMethod
     *
     * @internal
     */
    protected function build_cipher_method($cipher_name, $iv, $key_size): ?\Aws\Crypto\Cipher\Cbc
    {
        return match ($cipher_name) {
            'cbc' => new Cbc($iv, $key_size),
            default => null,
        };
    }
    /**
     * Performs a reverse lookup to get the openssl_* cipher name from the
     * AESName passed in from the MetadataEnvelope.
     *
     * @param $aesName
     *
     *
     * @internal
     */
    protected function get_cipher_from_aes_name($aes_name): string
    {
        return match ($aes_name) {
            'AES/GCM/NoPadding' => 'gcm',
            'AES/CBC/PKCS5Padding' => 'cbc',
            default => throw new Crypto_Exception('Unrecognized or unsupported' . ' AESName for reverse lookup.'),
        };
    }
}