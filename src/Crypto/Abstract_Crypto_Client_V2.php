<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Crypto\Cipher\Cipher_Method;
use Guzzle_Http\Psr7\Stream;
/**
 * @internal
 */
abstract class Abstract_Crypto_Client_V2
{
    public const KEY_COMMITMENT_POLICIES = ['FORBID_ENCRYPT_ALLOW_DECRYPT'];
    public static $supported_ciphers = ['gcm'];
    public static $supported_key_wraps = [Kms_Materials_Provider_V2::WRAP_ALGORITHM_NAME];
    public static $supported_security_profiles = ['V2', 'V2_AND_LEGACY'];
    public static $legacy_security_profiles = ['V2_AND_LEGACY'];
    /**
     * Returns if the passed policy name is supported for encryption by the SDK.
     *
     * @param string $policy The name of a key commitment policy to verify is registered.
     *
     * @return bool If the key commitment policy passed is in our supported list.
     */
    public static function is_supported_key_commitment_policy(string $policy): bool
    {
        return in_array($policy, self::KEY_COMMITMENT_POLICIES, strict: true);
    }
    /**
     * Returns if the passed cipher name is supported for encryption by the SDK.
     *
     * @param string $cipherName The name of a cipher to verify is registered.
     *
     * @return bool If the cipher passed is in our supported list.
     */
    public static function is_supported_cipher($cipher_name)
    {
        return in_array($cipher_name, self::$supported_ciphers, true);
    }
    /**
     * Returns an identifier recognizable by `openssl_*` functions, such as
     * `aes-256-gcm`
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
     * @param array $options Options for use in encryption.
     * @param MaterialsProviderV2 $provider A provider to supply and encrypt
     *                                      materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be added to.
     *
     * @return AesStreamInterface
     *
     * @internal
     */
    abstract public function encrypt(Stream $plaintext, array $options, Materials_Provider_V2 $provider, Metadata_Envelope $envelope);
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
     * @param array $options Options used for decryption.
     *
     * @return AesStreamInterface
     *
     * @internal
     */
    abstract public function decrypt($cipher_text, Materials_Provider_Interface_V2 $provider, Metadata_Envelope $envelope, array $options = []);
}