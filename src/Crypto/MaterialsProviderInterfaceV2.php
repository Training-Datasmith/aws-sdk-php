<?php

declare (strict_types=1);
namespace Aws\Crypto;

interface Materials_Provider_Interface_V2
{
    /**
     * Returns if the requested size is supported by AES.
     *
     * @param int $keySize Size of the requested key in bits.
     *
     * @return bool
     */
    public static function is_supported_key_size($key_size);
    /**
     * Returns the wrap algorithm name for this Provider.
     *
     * @return string
     */
    public function get_wrap_algorithm_name();
    /**
     * Takes an encrypted content encryption key (CEK) and material description
     * for use decrypting the key according to the Provider's specifications.
     *
     * @param string $encryptedCek Encrypted key to be decrypted by the Provider
     *                             for use decrypting other data.
     * @param string $materialDescription Material Description for use in
     *                                    decrypting the CEK.
     * @param array $options Options for use in decrypting the CEK.
     *
     * @return string
     */
    public function decrypt_cek($encrypted_cek, $material_description, $options);
    /**
     * @param string $keySize Length of a cipher key in bits for generating a
     *                        random content encryption key (CEK).
     * @param array $context Context map needed for key encryption
     * @param array $options Additional options to be used in CEK generation
     *
     * @return array
     */
    public function generate_cek($key_size, $context, $options);
    /**
     * @param string $openSslName Cipher OpenSSL name to use for generating
     *                            an initialization vector.
     *
     * @return string
     */
    public function generate_iv($open_ssl_name);
}