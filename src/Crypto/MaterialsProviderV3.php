<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Exception\Crypto_Exception;
abstract class Materials_Provider_V3 implements Materials_Provider_Interface_V3
{
    private static array $supported_key_sizes = [256 => true];
    /**
     * Returns if the requested size is supported by AES.
     *
     * @param int $keySize Size of the requested key in bits.
     */
    public static function is_supported_key_size(int $key_size): bool
    {
        return isset(self::$supported_key_sizes[$key_size]);
    }
    /**
     * Returns the wrap algorithm name for this Provider.
     */
    abstract public function get_wrap_algorithm_name(): string;
    /**
     * Takes an encrypted content encryption key (CEK) and material description
     * for use decrypting the key according to the Provider's specifications.
     *
     * @param string $encryptedCek Encrypted key to be decrypted by the Provider
     *                             for use decrypting other data.
     * @param array $materialDescription Material Description for use in
     *                                    decrypting the CEK.
     * @param array $options Options for use in decrypting the CEK.
     */
    abstract public function decrypt_cek(string $encrypted_cek, array $material_description, array $options): string;
    /**
     * @param string $keySize Length of a cipher key in bits for generating a
     *                        random content encryption key (CEK).
     * @param array $context Context map needed for key encryption
     * @param array $options Additional options to be used in CEK generation
     */
    abstract public function generate_cek(string $key_size, array $context, array $options): array;
    /**
     * @param string $openSslName Cipher OpenSSL name to use for generating
     *                            an initialization vector.
     */
    public function generate_iv(string $open_ssl_name): string
    {
        $iv = null;
        $cstrong = null;
        if ($open_ssl_name === 'aes-96-gcm') {
            $iv = openssl_random_pseudo_bytes(12, $cstrong);
        } elseif ($open_ssl_name === 'aes-224-gcm') {
            $iv = openssl_random_pseudo_bytes(28, $cstrong);
        } else {
            $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($open_ssl_name), $cstrong);
        }
        if (!$cstrong) {
            throw new Crypto_Exception('No strong cryptographic source available to generate a random IV.');
        }
        return $iv;
    }
}