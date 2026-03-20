<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Crypto\Cipher\Cipher_Method;
use Aws\Exception\Crypto_Exception;
use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Append_Stream;
use Guzzle_Http\Psr7\Stream;
trait Encryption_Trait_V3
{
    private static array $allowed_options = ['Cipher' => true, 'KeySize' => true, 'Aad' => true];
    private static array $encrypt_classes = ['gcm' => Aes_Gcm_Encrypting_Stream::class];
    /**
     * Dependency to generate a CipherMethod from a set of inputs for loading
     * in to an AesEncryptingStream.
     *
     * @param string $cipherName Name of the cipher to generate for encrypting.
     * @param string $iv Base Initialization Vector for the cipher.
     * @param int $keySize Size of the encryption key, in bits, that will be
     *                     used.
     *
     * @return Cipher\CipherMethod
     *
     * @internal
     */
    abstract protected function build_cipher_method($cipher_name, $iv, $key_size);
    /**
     * Builds an AesStreamInterface and populates encryption metadata into the
     * supplied envelope.
     *
     * @param Stream $plaintext Plain-text data to be encrypted using the
     *                          materials, algorithm, and data provided.
     * @param AlgorithmSuite $algorithmSuite Algorithm Suite for use in encryption
     * @param array $options    Options for use in encryption, including cipher
     *                          options, and encryption context.
     * @param MaterialsProviderV3 $provider A provider to supply and encrypt
     *                                      materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be added to.
     *
     *
     * @throws \InvalidArgumentException Thrown when a value in $options['@CipherOptions']
     *                                   is not valid.
     *s
     * @internal
     */
    public function encrypt(Stream $plaintext, Algorithm_Suite $algorithm_suite, array $options, Materials_Provider_V3 $provider, Metadata_Envelope $envelope): Append_Stream
    {
        $options = array_change_key_case($options);
        $cipher_options = array_intersect_key($options['@cipheroptions'], self::$allowed_options);
        //= ../specification/s3-encryption/encryption.md#content-encryption
        //= type=implication
        //# The client MUST validate that the length of the plaintext bytes does not exceed
        //# the algorithm suite's cipher's maximum content length in bytes.
        if (strlen($plaintext) > $algorithm_suite->get_cipher_max_content_length_bytes()) {
            throw new \InvalidArgumentException('The contentLength of the object you are attempting' . ' to encrypt exceeds the maximum length allowed for GCM encryption.');
        }
        $cipher_options['Cipher'] = strtolower((string) $cipher_options['Cipher']);
        if (!self::is_supported_cipher($cipher_options['Cipher'])) {
            throw new \InvalidArgumentException('The cipher requested is not' . ' supported by the SDK.');
        }
        if (empty($cipher_options['KeySize'])) {
            $cipher_options['KeySize'] = 256;
        }
        if (!is_int($cipher_options['KeySize'])) {
            throw new \InvalidArgumentException('The cipher "KeySize" must be' . ' an integer.');
        }
        if (!Materials_Provider_V3::is_supported_key_size($cipher_options['KeySize'])) {
            throw new \InvalidArgumentException('The cipher "KeySize" requested' . ' is not supported by AES (256).');
        }
        $encrypt_class = self::$encrypt_classes[$algorithm_suite->get_cipher_name()];
        $aes_name = $encrypt_class::get_static_aes_name();
        $materials_description = $algorithm_suite->is_key_committing() ? ['aws:x-amz-cek-alg' => '115'] : ['aws:x-amz-cek-alg' => $aes_name];
        $keys = $provider->generate_cek($algorithm_suite->get_data_key_length_bits(), $materials_description, $options);
        // Some providers modify materials description based on options
        if (isset($keys['UpdatedContext'])) {
            $materials_description = $keys['UpdatedContext'];
        }
        if ($algorithm_suite->is_key_committing()) {
            return $this->encrypt_commiting_stream($plaintext, $algorithm_suite, $cipher_options, $keys, $materials_description, $provider, $envelope);
        }
        return $this->encrypt_non_commiting_stream($plaintext, $cipher_options, $keys, $materials_description, $aes_name, $provider, $envelope);
    }
    private function encrypt_non_commiting_stream(Stream $plaintext, array &$cipher_options, array $keys, array $materials_description, string $aes_name, Materials_Provider_V3 $provider, Metadata_Envelope $envelope): Append_Stream
    {
        //= ../specification/s3-encryption/encryption.md#content-encryption
        //# The generated IV or Message ID MUST be set or returned from the
        //# encryption process such that it can be included in the content metadata.
        //= ../specification/s3-encryption/encryption.md#content-encryption
        //# The client MUST generate an IV or Message ID using the length of the IV or Message ID defined in the algorithm suite.
        $cipher_options['Iv'] = $provider->generate_iv($this->get_cipher_open_ssl_name($cipher_options['Cipher'], $cipher_options['KeySize']));
        // Some providers modify materials description based on options
        if (isset($keys['UpdatedContext'])) {
            $materials_description = $keys['UpdatedContext'];
        }
        $encrypting_stream = $this->get_non_committing_encrypting_stream($plaintext, $keys['Plaintext'], $cipher_options);
        // Populate envelope data
        //= ../specification/s3-encryption/data-format/content-metadata.md#determining-s3ec-object-status
        //# - If the metadata contains "x-amz-iv" and "x-amz-metadata-x-amz-key-v2" then the object MUST be considered as an S3EC-encrypted object using the V2 format.
        $envelope[Metadata_Envelope::CONTENT_KEY_V2_HEADER] = $keys['Ciphertext'];
        unset($keys);
        $envelope[Metadata_Envelope::IV_HEADER] = base64_encode($cipher_options['Iv']);
        $envelope[Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER] = $provider->get_wrap_algorithm_name();
        $envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER] = $aes_name;
        $envelope[Metadata_Envelope::UNENCRYPTED_CONTENT_LENGTH_HEADER] = (string) strlen($plaintext);
        $envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER] = json_encode($materials_description);
        if (!empty($cipher_options['Tag'])) {
            $envelope[Metadata_Envelope::CRYPTO_TAG_LENGTH_HEADER] = (string) (strlen((string) $cipher_options['Tag']) * 8);
        }
        if (!Metadata_Envelope::is_v2envelope($envelope)) {
            throw new Crypto_Exception('Error while writing metadata envelope.' . ' Not all required fields were set.');
        }
        return $encrypting_stream;
    }
    private function encrypt_commiting_stream(Stream $plaintext, Algorithm_Suite $algorithm_suite, array &$options, array $keys, array $materials_description, Materials_Provider_V3 $provider, Metadata_Envelope $envelope): Append_Stream
    {
        //= ../specification/s3-encryption/encryption.md#content-encryption
        //# The generated IV or Message ID MUST be set or returned from the
        //# encryption process such that it can be included in the content metadata.
        //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
        //= type=implication
        //# When encrypting or decrypting with ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY,
        //# the IV used in the AES-GCM content encryption/decryption
        //# MUST consist entirely of bytes with the value 0x01.
        //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
        //= type=implication
        //# The IV's total length MUST match the IV length defined by the algorithm suite.
        $options['Iv'] = str_repeat("\x01", $algorithm_suite->get_iv_length_bytes());
        $message_id = $provider->generate_iv($this->get_cipher_open_ssl_name(
            $algorithm_suite->get_cipher_name(),
            //= ../specification/s3-encryption/encryption.md#content-encryption
            //# The client MUST generate an IV or Message ID using the length of
            //# the IV or Message ID defined in the algorithm suite.
            $algorithm_suite->get_key_commitment_salt_length_bits()
        ));
        // Some providers modify materials description based on options
        if (isset($keys['UpdatedContext'])) {
            $materials_description['aws:x-amz-cek-alg'] = (string) $algorithm_suite->get_id();
        }
        $commiting_encrypting_array = $this->get_commiting_encryption_stream($plaintext, $keys['Plaintext'], $options, $message_id, $algorithm_suite);
        //= ../specification/s3-encryption/encryption.md#alg-aes-256-gcm-hkdf-sha512-commit-key
        //= type=implication
        //# The derived key commitment value MUST be set or returned from the encryption process
        //# such that it can be included in the content metadata.
        $commitment_key = $commiting_encrypting_array[0];
        $encryption_stream = $commiting_encrypting_array[1];
        // Populate envelope data
        $envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_V3] = $keys['Ciphertext'];
        unset($keys);
        $envelope[Metadata_Envelope::CONTENT_CIPHER_V3] = $algorithm_suite->get_id();
        // we are able to always set the encryption context in the envelope because PHP
        // only supports a KMS Material Provider.
        $envelope[Metadata_Envelope::ENCRYPTION_CONTEXT_V3] = json_encode($materials_description);
        //= ../specification/s3-encryption/data-format/content-metadata.md#v3-only
        //# The Encryption Context value MUST be used for wrapping algorithm `kms+context` or `12`.
        $envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3] = '12';
        $envelope[Metadata_Envelope::KEY_COMMITMENT_V3] = $commitment_key;
        $envelope[Metadata_Envelope::MESSAGE_ID_V3] = base64_encode($message_id);
        if (!Metadata_Envelope::is_v3envelope($envelope)) {
            throw new Crypto_Exception('Error while writing metadata envelope.' . ' Not all required fields were set.');
        }
        return $encryption_stream;
    }
    /**
     * Generates a stream that wraps the plaintext with the proper cipher and
     * uses the content encryption key (CEK) to encrypt the data when read.
     *
     * @param Stream $plaintext Plain-text data to be encrypted using the
     *                          materials, algorithm, and data provided.
     * @param string $cek A content encryption key for use by the stream for
     *                    encrypting the plaintext data.
     * @param array $cipherOptions Options for use in determining the cipher to
     *                             be used for encrypting data.
     *
     * @return AppendStream returns an AppendStream
     *
     * @internal
     */
    protected function get_non_committing_encrypting_stream(Stream $plaintext, string $cek, array &$cipher_options): Append_Stream
    {
        // Only 'gcm' is supported for encryption currently
        switch ($cipher_options['Cipher']) {
            //= ../specification/s3-encryption/encryption.md#content-encryption
            //= type=implication
            //# The S3EC MUST use the encryption algorithm configured during [client](./client.md) initialization.
            case 'gcm':
                $cipher_options['TagLength'] = 16;
                $encrypt_class = self::$encrypt_classes['gcm'];
                //= ../specification/s3-encryption/encryption.md#alg-aes-256-gcm-iv12-tag16-no-kdf
                //= type=implication
                //# The client MUST initialize the cipher, or call an AES-GCM encryption API,
                //# with the plaintext data key, the generated IV, and the tag length defined
                //# in the Algorithm Suite when encrypting with ALG_AES_256_GCM_IV12_TAG16_NO_KDF.
                $cipher_text_stream = new $encrypt_class($plaintext, $cek, $cipher_options['Iv'], $cipher_options['Aad'] ??= '', $cipher_options['TagLength'], $cipher_options['KeySize']);
                if (!empty($cipher_options['Aad'])) {
                    trigger_error("'Aad' has been supplied for content encryption" . ' with ' . $cipher_text_stream->get_aes_name() . '. The' . ' PHP SDK encryption client can decrypt an object' . ' encrypted in this way, but other AWS SDKs may not be' . ' able to.', E_USER_WARNING);
                }
                $append_stream = new Append_Stream([$cipher_text_stream->create_stream()]);
                //= ../specification/s3-encryption/encryption.md#alg-aes-256-gcm-iv12-tag16-no-kdf
                //= type=implication
                //# The client MUST append the GCM auth tag to the ciphertext if the underlying
                //# crypto provider does not do so automatically.
                $cipher_options['Tag'] = $cipher_text_stream->get_tag();
                $append_stream->add_stream(Psr7\Utils::stream_for($cipher_options['Tag']));
                return $append_stream;
            default:
                throw new Crypto_Exception('Unsupported Cipher used for key commitment messages.' . " Found {$cipher_options['Cipher']}. Only 'gcm' is supported.");
        }
    }
    /**
     * Generates a stream that wraps the plaintext with the proper cipher and
     * uses the content encryption key (CEK) to encrypt the data when read.
     *
     * @param Stream $plaintext Plain-text data to be encrypted using the
     *                          materials, algorithm, and data provided.
     * @param string $cek A content encryption key for use by the stream for
     *                    encrypting the plaintext data.
     * @param array $cipherOptions Options for use in determining the cipher to
     *                             be used for encrypting data.
     * @param string $messageId salt value used for key extraction step in the key
     *                          derivation process.
     * @param AlgorithmSuite $algorithmSuite options used for key commitment
     *
     * @return array returns an array with two elements as follows: [commitmentKey, AppendStream]
     *
     * @internal
     */
    protected function get_commiting_encryption_stream(Stream $plaintext, string $dek, array &$cipher_options, string $message_id, Algorithm_Suite $algorithm_suite): array
    {
        $algorithm_suite_id_as_bytes = pack('n', $algorithm_suite->get_id());
        //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
        //= type=implication
        //# - The input info MUST be a concatenation of the algorithm suite ID as bytes followed by the string DERIVEKEY as UTF8 encoded bytes.
        $derived_encryption_key_info = $algorithm_suite_id_as_bytes . 'DERIVEKEY';
        //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
        //= type=implication
        //# - The input info MUST be a concatenation of the algorithm suite ID as bytes followed by the string COMMITKEY as UTF8 encoded bytes.
        $commitment_key_info = $algorithm_suite_id_as_bytes . 'COMMITKEY';
        //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
        //= type=implication
        //# - The length of the input keying material MUST equal the key derivation
        //# input length specified by the algorithm suite commit key derivation setting.
        if (strlen($dek) !== $algorithm_suite->get_derivation_input_key_length_bytes()) {
            throw new Crypto_Exception('Input Key Material length exceeds ' . 'key derivation input length specified by the algorithm suite.');
        }
        //= ../specification/s3-encryption/encryption.md#alg-aes-256-gcm-hkdf-sha512-commit-key
        //= type=implication
        //# The client MUST use HKDF to derive the key commitment value and
        //# the derived encrypting key as described in [Key Derivation](key-derivation.md).
        $cek = hash_hkdf(
            //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
            //= type=implication
            //# - The hash function MUST be specified by the algorithm suite commitment settings.
            $algorithm_suite->get_hashing_algorithm(),
            //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
            //= type=implication
            //# - The input keying material MUST be the plaintext data key (PDK) generated by the key provider.
            //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
            //= type=implication
            //# - The DEK input pseudorandom key MUST be the output from the extract step.
            $dek,
            //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
            //= type=implication
            //# - The length of the output keying material MUST equal the encryption key length specified by the algorithm suite encryption settings.
            $algorithm_suite->get_derivation_output_key_length_bytes(),
            $derived_encryption_key_info,
            //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
            //= type=implication
            //# - The salt MUST be the Message ID with the length defined in the algorithm suite.
            $message_id
        );
        $commitment_key = hash_hkdf(
            $algorithm_suite->get_hashing_algorithm(),
            //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
            //= type=implication
            //# - The CK input pseudorandom key MUST be the output from the extract step.
            $dek,
            //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
            //= type=implication
            //# - The length of the output keying material MUST equal the commit key length specified by the supported algorithm suites.
            $algorithm_suite->get_commitment_output_key_length_bytes(),
            $commitment_key_info,
            $message_id
        );
        switch ($cipher_options['Cipher']) {
            // Only 'gcm' is supported for encryption currently
            case 'gcm':
                $cipher_options['TagLength'] = $algorithm_suite->get_cipher_tag_length_in_bytes();
                $encrypt_class = self::$encrypt_classes[$algorithm_suite->get_cipher_name()];
                if (!empty($cipher_options['Aad'])) {
                    trigger_error("'Aad' has been supplied for content encryption" . ' with ' . $encrypt_class->get_aes_name() . '. The' . ' PHP SDK encryption client can decrypt an object' . ' encrypted in this way, but other AWS SDKs may not be' . ' able to.', E_USER_NOTICE);
                }
                $cipher_options['Aad'] = isset($cipher_options['Aad']) ? $cipher_options['Aad'] + $algorithm_suite_id_as_bytes : $algorithm_suite_id_as_bytes;
                //= ../specification/s3-encryption/key-derivation.md#hkdf-operation
                //= type=implication
                //# The client MUST initialize the cipher, or call an AES-GCM encryption API,
                //# with the derived encryption key, an IV containing only bytes with the value 0x01,
                //# and the tag length defined in the Algorithm Suite when encrypting or
                //# decrypting with ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY.
                $cipher_text_stream = new $encrypt_class($plaintext, $cek, $cipher_options['Iv'], $cipher_options['Aad'], $cipher_options['TagLength'], $cipher_options['KeySize']);
                $append_stream = new Append_Stream([$cipher_text_stream->create_stream()]);
                //= ../specification/s3-encryption/encryption.md#alg-aes-256-gcm-hkdf-sha512-commit-key
                //= type=implication
                //# The client MUST append the GCM auth tag to the ciphertext if the underlying
                //# crypto provider does not do so automatically.
                $cipher_options['Tag'] = $cipher_text_stream->get_tag();
                $append_stream->add_stream(Psr7\Utils::stream_for($cipher_options['Tag']));
                return [base64_encode($commitment_key), $append_stream];
            default:
                throw new Crypto_Exception('Unsupported Cipher used for content encryption');
        }
    }
}