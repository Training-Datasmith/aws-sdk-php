<?php

declare (strict_types=1);
namespace Aws\Crypto;

abstract class Materials_Provider implements Materials_Provider_Interface
{
    private static array $supported_key_sizes = [128 => true, 192 => true, 256 => true];
    /**
     * Returns if the requested size is supported by AES.
     *
     * @param int $keySize Size of the requested key in bits.
     *
     * @return bool
     */
    public static function is_supported_key_size($key_size)
    {
        return isset(self::$supported_key_sizes[$key_size]);
    }
    /**
     * Performs further initialization of the MaterialsProvider based on the
     * data inside the MetadataEnvelope.
     *
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be read from.
     *
     * @return MaterialsProvider
     *
     * @throws \RuntimeException Thrown when there is an empty or improperly
     *                           formed materials description in the envelope.
     *
     * @internal
     */
    abstract public function from_decryption_envelope(Metadata_Envelope $envelope);
    /**
     * Returns the material description for this Provider so it can be verified
     * by encryption mechanisms.
     *
     * @return string
     */
    abstract public function get_materials_description();
    /**
     * Returns the wrap algorithm name for this Provider.
     *
     * @return string
     */
    abstract public function get_wrap_algorithm_name();
    /**
     * Takes a content encryption key (CEK) and description to return an
     * encrypted key according to the Provider's specifications.
     *
     * @param string $unencryptedCek Key for use in encrypting other data
     *                               that itself needs to be encrypted by the
     *                               Provider.
     * @param string $materialDescription Material Description for use in
     *                                    encrypting the $cek.
     *
     * @return string
     */
    abstract public function encrypt_cek($unencrypted_cek, $material_description);
    /**
     * Takes an encrypted content encryption key (CEK) and material description
     * for use decrypting the key according to the Provider's specifications.
     *
     * @param string $encryptedCek Encrypted key to be decrypted by the Provider
     *                             for use decrypting other data.
     * @param string $materialDescription Material Description for use in
     *                                    encrypting the $cek.
     *
     * @return string
     */
    abstract public function decrypt_cek($encrypted_cek, $material_description);
    /**
     * @param string $keySize Length of a cipher key in bits for generating a
     *                        random content encryption key (CEK).
     *
     * @return string
     */
    public function generate_cek($key_size)
    {
        return openssl_random_pseudo_bytes($key_size / 8);
    }
    /**
     * @param string $openSslName Cipher OpenSSL name to use for generating
     *                            an initialization vector.
     *
     * @return string
     */
    public function generate_iv($open_ssl_name)
    {
        return openssl_random_pseudo_bytes(openssl_cipher_iv_length($open_ssl_name));
    }
}