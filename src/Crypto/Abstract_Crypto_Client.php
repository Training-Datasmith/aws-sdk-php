<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Crypto\Cipher\Cbc;
use Aws\Crypto\Cipher\Cipher_Method;
use Guzzle_Http\Psr7\Stream;
/**
 * Legacy abstract encryption client. New workflows should use
 * AbstractCryptoClientV2.
 *
 * @deprecated
 * @internal
 */
abstract class Abstract_Crypto_Client
{
    public static $supported_ciphers = ['cbc', 'gcm'];
    public static $supported_key_wraps = [Kms_Materials_Provider::WRAP_ALGORITHM_NAME];
    /**
     * Returns if the passed cipher name is supported for encryption by the SDK.
     *
     * @param string $cipherName The name of a cipher to verify is registered.
     *
     * @return bool If the cipher passed is in our supported list.
     */
    public static function is_supported_cipher($cipher_name)
    {
        return in_array($cipher_name, self::$supported_ciphers);
    }
    /**
     * Returns an identifier recognizable by `openssl_*` functions, such as
     * `aes-256-cbc` or `aes-128-ctr`.
     *
     * @param string $cipherName Name of the cipher being used for encrypting
     *                           or decrypting.
     * @param int $keySize Size of the encryption key, in bits, that will be
     *                     used.
     *
     * @return string
     */
    abstract protected function get_cipher_open_ssl_name($cipher_name, $key_size);
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
    abstract protected function build_cipher_method($cipher_name, $iv, $key_size);
    /**
     * Performs a reverse lookup to get the openssl_* cipher name from the
     * AESName passed in from the MetadataEnvelope.
     *
     * @param $aesName
     *
     * @return string
     *
     * @internal
     */
    abstract protected function get_cipher_from_aes_name($aes_name);
    /**
     * Dependency to provide an interface for building an encryption stream for
     * data given cipher details, metadata, and materials to do so.
     *
     * @param Stream $plaintext Plain-text data to be encrypted using the
     *                          materials, algorithm, and data provided.
     * @param array $cipherOptions Options for use in determining the cipher to
     *                             be used for encrypting data.
     * @param MaterialsProvider $provider A provider to supply and encrypt
     *                                    materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be added to.
     *
     * @return AesStreamInterface
     *
     * @internal
     */
    abstract public function encrypt(Stream $plaintext, array $cipher_options, Materials_Provider $provider, Metadata_Envelope $envelope);
    /**
     * Dependency to provide an interface for building a decryption stream for
     * cipher text given metadata and materials to do so.
     *
     * @param string $cipherText Plain-text data to be decrypted using the
     *                           materials, algorithm, and data provided.
     * @param MaterialsProviderInterface $provider A provider to supply and encrypt
     *                                             materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be read from.
     * @param array $cipherOptions Additional verification options.
     *
     * @return AesStreamInterface
     *
     * @internal
     */
    abstract public function decrypt($cipher_text, Materials_Provider_Interface $provider, Metadata_Envelope $envelope, array $cipher_options = []);
}