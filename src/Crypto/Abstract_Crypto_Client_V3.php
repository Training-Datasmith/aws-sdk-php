<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Crypto\Cipher\Cipher_Method;
use Guzzle_Http\Psr7\Append_Stream;
use Guzzle_Http\Psr7\Stream;
/**
 * @internal
 */
abstract class Abstract_Crypto_Client_V3
{
    public const SUPPORTED_SECURITY_PROFILES = ['V3', 'V3_AND_LEGACY'];
    public const LEGACY_SECURITY_PROFILES = ['V3_AND_LEGACY'];
    public const KEY_COMMITMENT_POLICIES = ['FORBID_ENCRYPT_ALLOW_DECRYPT', 'REQUIRE_ENCRYPT_ALLOW_DECRYPT', 'REQUIRE_ENCRYPT_REQUIRE_DECRYPT'];
    public static array $supported_ciphers = ['gcm'];
    public static array $supported_key_wraps = [Kms_Materials_Provider_V3::WRAP_ALGORITHM_NAME];
    /**
     * Returns if the passed policy name is supported for encryption by the SDK.
     *
     * @param string $policy The name of a key commitment policy to verify is registered.
     *
     * @return bool If the key commitment policy passed is in our supported list.
     */
    public static function is_supported_key_commitment_policy(string $policy): bool
    {
        return in_array($policy, Abstract_Crypto_Client_V3::KEY_COMMITMENT_POLICIES, strict: true);
    }
    /**
     * Returns if the passed cipher name is supported for encryption by the SDK.
     *
     * @param string $cipherName The name of a cipher to verify is registered.
     *
     * @return bool If the cipher passed is in our supported list.
     */
    public static function is_supported_cipher(string $cipher_name): bool
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
     * @param string $aesName
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
     * @param AlgorithmSuite $algorithmSuite AlgorithmSuite for use in encryption.
     * @param array $options    Options for use in encryption, including cipher
     *                          options, and encryption context.
     * @param MaterialsProviderV3 $provider A provider to supply and encrypt
     *                                      materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be added to.
     *
     *
     * @internal
     */
    abstract public function encrypt(Stream $plaintext, Algorithm_Suite $algorithm_suite, array $options, Materials_Provider_V3 $provider, Metadata_Envelope $envelope): Append_Stream;
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
     * @param string $commitmentPolicy Commitment Policy to use for decrypting objects.
     * @param array $options Options used for decryption.
     *
     *
     * @internal
     */
    abstract public function decrypt(string $cipher_text, Materials_Provider_Interface_V3 $provider, Metadata_Envelope $envelope, string $commitment_policy, array $options = []): Aes_Stream_Interface;
}