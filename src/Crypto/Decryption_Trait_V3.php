<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Crypto\Cipher\Cipher_Method;
use Aws\Exception\Crypto_Exception;
use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Limit_Stream;
use Psr\Http\Message\Stream_Interface;
trait Decryption_Trait_V3
{
    /**
     * Dependency to reverse lookup the openssl_* cipher name from the AESName
     * in the MetadataEnvelope.
     *
     * @param string $aesName
     *
     * @return string
     *
     * @internal
     */
    abstract protected function get_cipher_from_aes_name($aes_name);
    /**
     * Dependency to generate a CipherMethod from a set of inputs for loading
     * in to an AesDecryptingStream.
     *
     * @param string $cipherName Name of the cipher to generate for decrypting.
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
     * Builds an AesStreamInterface using cipher options loaded from the
     * MetadataEnvelope and MaterialsProvider. Can decrypt data from both the
     * legacy and V3 encryption client workflows.
     *
     * @param string $cipherText Plain-text data to be encrypted using the
     *                           materials, algorithm, and data provided.
     * @param MaterialsProviderInterfaceV3 $provider A provider to supply and encrypt
     *                                             materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be read from.
     * @param string $commitmentPolicy Commitment Policy to use for decrypting objects.
     * @param array $options Options used for decryption.
     *
     *
     * @throws \InvalidArgumentException Thrown when a value in $cipherOptions
     *                                   is not valid.
     * @internal
     */
    public function decrypt(string $cipher_text, Materials_Provider_Interface_V3 $provider, Metadata_Envelope $envelope, string $commitment_policy, array $options = []): Aes_Stream_Interface
    {
        if (isset($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_V3])) {
            $this->check_envelope_for_exclusive_map_keys($envelope, Metadata_Envelope::get_v2fields(), 'Expected V3 only fields but found V2 fields in header metadata.');
            // PHP only supports one commiting algorithm suite
            $algorithm_suite = Algorithm_Suite::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY;
            $options['@CipherOptions'] ??= [];
            $options['@CipherOptions']['Iv'] = str_repeat("\x01", 12);
            $options['@CipherOptions']['TagLength'] = $algorithm_suite->get_cipher_tag_length_in_bytes();
            //= ../specification/s3-encryption/data-format/content-metadata.md#v3-only
            //= type=implication
            //# - The wrapping algorithm value "12" MUST be translated to kms+context upon retrieval, and vice versa on write.
            $material_description = $this->build_material_description($envelope);
            $cek = $provider->decrypt_cek(base64_decode((string) $envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_V3]), $material_description, $options);
            $options['@CipherOptions']['KeySize'] = strlen($cek) * 8;
            $options['@CipherOptions']['Cipher'] = $this->get_cipher_from_aes_name($this->numerical_conten_cipher_to_aes_name($envelope));
            $this->validate_options_and_envelope($options, $envelope, $commitment_policy);
            $message_id = base64_decode((string) $envelope[Metadata_Envelope::MESSAGE_ID_V3]);
            $commitment_key = base64_decode((string) $envelope[Metadata_Envelope::KEY_COMMITMENT_V3]);
            if (strlen($message_id) !== $algorithm_suite->get_key_commitment_salt_length_bits() / 8) {
                throw new Crypto_Exception('Invalid MessageId length found in object envelope.');
            }
            if (strlen($commitment_key) !== $algorithm_suite->get_commitment_output_key_length_bytes()) {
                throw new Crypto_Exception('Invalid Commitment Key length found in object envelope.');
            }
            $decryption_stream = $this->get_commiting_decrypting_stream($cipher_text, $cek, $options['@CipherOptions'], $message_id, $commitment_key, $algorithm_suite);
            unset($cek);
            return $decryption_stream;
        }
        //= ../specification/s3-encryption/key-commitment.md#commitment-policy
        //# When the commitment policy is REQUIRE_ENCRYPT_REQUIRE_DECRYPT,
        //# the S3EC MUST NOT allow decryption using algorithm suites which do not support key commitment.
        if ($commitment_policy == 'REQUIRE_ENCRYPT_REQUIRE_DECRYPT') {
            //= ../specification/s3-encryption/client.md#key-commitment
            //# If the configured Encryption Algorithm is incompatible with
            //# the key commitment policy, then it MUST throw an exception.
            throw new Crypto_Exception('Message is encrypted with a ' . 'non commiting algorithm but commitment policy is set ' . "to {$commitment_policy}. Select a valid commitment " . 'policy to decrypt this object. ');
        }
        $this->check_envelope_for_exclusive_map_keys($envelope, Metadata_Envelope::get_v3fields(), 'Expected V2 only fields but found V3 fields in header metadata.');
        $options['@CipherOptions'] ??= [];
        $options['@CipherOptions']['Iv'] = base64_decode((string) $envelope[Metadata_Envelope::IV_HEADER]);
        $options['@CipherOptions']['TagLength'] = $envelope[Metadata_Envelope::CRYPTO_TAG_LENGTH_HEADER] / 8;
        $cek = $provider->decrypt_cek(base64_decode((string) $envelope[Metadata_Envelope::CONTENT_KEY_V2_HEADER]), json_decode((string) $envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER], true), $options);
        $options['@CipherOptions']['KeySize'] = strlen($cek) * 8;
        $options['@CipherOptions']['Cipher'] = $this->get_cipher_from_aes_name($envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER]);
        //= ../specification/s3-encryption/decryption.md#key-commitment
        //= type=implication
        //# The S3EC MUST validate the algorithm suite used for decryption
        //# against the key commitment policy before attempting to decrypt
        //# the content ciphertext.
        $this->validate_options_and_envelope($options, $envelope, $commitment_policy);
        $decryption_stream = $this->get_non_commiting_decrypting_stream($cipher_text, $cek, $options['@CipherOptions']);
        unset($cek);
        return $decryption_stream;
    }
    private function check_envelope_for_exclusive_map_keys(Metadata_Envelope $envelope, array $exclusive_keys, string $error_message): void
    {
        foreach ($exclusive_keys as $exclusive_key) {
            //= ../specification/s3-encryption/data-format/content-metadata.md#determining-s3ec-object-status
            //# If there are multiple mapkeys which are meant to be exclusive, such as "x-amz-key", "x-amz-key-v2", and "x-amz-3" then the S3EC SHOULD throw an exception.
            if (isset($envelope[$exclusive_key])) {
                throw new Crypto_Exception($error_message);
            }
        }
    }
    private function numerical_conten_cipher_to_aes_name(Metadata_Envelope $envelope): string
    {
        return match ($envelope[Metadata_Envelope::CONTENT_CIPHER_V3]) {
            115 => 'AES/GCM/NoPadding',
            default => throw new Crypto_Exception('Unknown Encrypted Data Key ' . 'wrapping algorithm found: ' . "{$envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]}"),
        };
    }
    private function build_material_description(Metadata_Envelope $envelope): array
    {
        return match ($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]) {
            12 => json_decode((string) $envelope[Metadata_Envelope::ENCRYPTION_CONTEXT_V3], true),
            default => throw new Crypto_Exception('Unknown Encrypted Data Key ' . 'wrapping algorithm found: ' . "{$envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]}"),
        };
    }
    private function get_tag_from_ciphertext_stream(Stream_Interface $cipher_text, $tag_length): string
    {
        $cipher_text_size = $cipher_text->get_size();
        if ($cipher_text_size == null || $cipher_text_size <= 0) {
            throw new \RuntimeException('Cannot decrypt a stream of unknown size');
        }
        return (string) new Limit_Stream($cipher_text, $tag_length, $cipher_text_size - $tag_length);
    }
    private function get_stripped_ciphertext_stream(Stream_Interface $cipher_text, $tag_length): Limit_Stream
    {
        $cipher_text_size = $cipher_text->get_size();
        if ($cipher_text_size == null || $cipher_text_size <= 0) {
            throw new \RuntimeException('Cannot decrypt a stream of unknown size');
        }
        return new Limit_Stream($cipher_text, $cipher_text_size - $tag_length, 0);
    }
    private function validate_options_and_envelope(array $options, Metadata_Envelope $envelope, string $commitment_policy): void
    {
        //= ../specification/s3-encryption/key-commitment.md#commitment-policy
        //# When the commitment policy is REQUIRE_ENCRYPT_ALLOW_DECRYPT,
        //# the S3EC MUST allow decryption using algorithm suites which do not support key commitment.
        //= ../specification/s3-encryption/key-commitment.md#commitment-policy
        //# When the commitment policy is FORBID_ENCRYPT_ALLOW_DECRYPT,
        //# the S3EC MUST allow decryption using algorithm suites which do not support key commitment.
        $allowed_ciphers = Abstract_Crypto_Client_V3::$supported_ciphers;
        $allowed_keywraps = Abstract_Crypto_Client_V3::$supported_key_wraps;
        //= ../specification/s3-encryption/client.md#enable-legacy-wrapping-algorithms
        //# When enabled, the S3EC MUST be able to decrypt objects encrypted with all
        //# supported wrapping algorithms (both legacy and fully supported).
        if ($options['@SecurityProfile'] == 'V3_AND_LEGACY') {
            $allowed_ciphers = array_unique(array_merge($allowed_ciphers, Abstract_Crypto_Client::$supported_ciphers));
            $allowed_keywraps = array_unique(array_merge($allowed_keywraps, Abstract_Crypto_Client::$supported_key_wraps));
        }
        $v1schema_exception = new Crypto_Exception('The requested object is encrypted' . ' with V1 encryption schemas that have been disabled by' . ' client configuration @SecurityProfile=V3. Retry with' . ' V3_AND_LEGACY enabled or reencrypt the object.');
        if (!in_array($options['@CipherOptions']['Cipher'], $allowed_ciphers)) {
            //= ../specification/s3-encryption/client.md#enable-legacy-wrapping-algorithms
            //= type=implication
            //# When disabled, the S3EC MUST NOT decrypt objects encrypted using legacy wrapping algorithms;
            //# it MUST throw an exception when attempting to decrypt an object encrypted with a legacy wrapping algorithm.
            //= ../specification/s3-encryption/decryption.md#legacy-decryption
            //# The S3EC MUST NOT decrypt objects encrypted using legacy unauthenticated algorithm suites unless specifically configured to do so.
            if (in_array($options['@CipherOptions']['Cipher'], Abstract_Crypto_Client::$supported_ciphers)) {
                //= ../specification/s3-encryption/decryption.md#legacy-decryption
                //# If the S3EC is not configured to enable legacy unauthenticated content decryption, the client MUST throw an exception when attempting to decrypt an object encrypted with a legacy unauthenticated algorithm suite.
                throw $v1schema_exception;
            }
            throw new Crypto_Exception('The requested object is encrypted with' . " the cipher '{$options['@CipherOptions']['Cipher']}', which is not" . ' supported for decryption with the selected security profile.' . ' This profile allows decryption with: ' . implode(', ', $allowed_ciphers));
        }
        if (isset($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_V3])) {
            if ($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3] !== '12') {
                throw new Crypto_Exception('The requested object is encrypted with' . " the keywrap schema '{$envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]}'," . ' which is not supported for decryption with the current security' . ' profile.');
            }
        } else {
            if (!in_array($envelope[Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER], $allowed_keywraps)) {
                if (in_array($envelope[Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER], Abstract_Crypto_Client::$supported_key_wraps)) {
                    throw $v1schema_exception;
                }
                throw new Crypto_Exception('The requested object is encrypted with' . " the keywrap schema '{$envelope[Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER]}'," . ' which is not supported for decryption with the current security' . ' profile.');
            }
            $matdesc = json_decode((string) $envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER], true);
            if (isset($matdesc['aws:x-amz-cek-alg']) && $envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER] !== $matdesc['aws:x-amz-cek-alg']) {
                throw new Crypto_Exception('There is a mismatch in specified content' . ' encryption algrithm between the materials description value' . " and the metadata envelope value: {$matdesc['aws:x-amz-cek-alg']}" . " vs. {$envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER]}.");
            }
        }
        //= ../specification/s3-encryption/data-format/content-metadata.md#determining-s3ec-object-status
        //= type=implication
        //# - If the metadata contains "x-amz-3" and "x-amz-d" and "x-amz-i" then the object MUST be considered an S3EC-encrypted object using the V3 format.
        if (!Metadata_Envelope::is_v3envelope($envelope) && $commitment_policy == 'REQUIRE_ENCRYPT_REQUIRE_DECRYPT') {
            //= ../specification/s3-encryption/decryption.md#key-commitment
            //# If the commitment policy requires decryption using a committing algorithm suite
            //# and the algorithm suite associated with the object does not support key commitment,
            //# then the S3EC MUST throw an exception.
            throw new Crypto_Exception('There is a mismatch in specified' . "commitment policy value {$commitment_policy} and" . 'Metadata Envelope found in object.');
        }
    }
    /**
     * Generates a stream that wraps the cipher text with the proper cipher and
     * uses the content encryption key (CEK) to decrypt the data when read.
     *
     * @param string $cipherText Plain-text data to be encrypted using the
     *                           materials, algorithm, and data provided.
     * @param string $cek A content encryption key for use by the stream for
     *                    encrypting the plaintext data.
     * @param array $cipherOptions Options for use in determining the cipher to
     *                             be used for encrypting data.
     *
     *
     * @internal
     */
    protected function get_non_commiting_decrypting_stream(string $cipher_text, string $cek, array $cipher_options): Aes_Stream_Interface
    {
        $cipher_text_stream = Psr7\Utils::stream_for($cipher_text);
        switch ($cipher_options['Cipher']) {
            case 'gcm':
                $cipher_options['Tag'] = $this->get_tag_from_ciphertext_stream($cipher_text_stream, $cipher_options['TagLength']);
                return new Aes_Gcm_Decrypting_Stream($this->get_stripped_ciphertext_stream($cipher_text_stream, $cipher_options['TagLength']), $cek, $cipher_options['Iv'], $cipher_options['Tag'], $cipher_options['Aad'] ??= '', $cipher_options['TagLength'] ?: null, $cipher_options['KeySize']);
            default:
                $cipher_method = $this->build_cipher_method($cipher_options['Cipher'], $cipher_options['Iv'], $cipher_options['KeySize']);
                return new Aes_Decrypting_Stream($cipher_text_stream, $cek, $cipher_method);
        }
    }
    /**
     * Generates a stream that wraps the cipher text with the proper cipher and
     * uses the content encryption key (CEK) to derive both a derived content encryption key
     * and a commitment key to decrypt the data when read.
     *
     * @param string $cipherText Plain-text data to be encrypted using the
     *                           materials, algorithm, and data provided.
     * @param string $cek A content encryption key for use by the stream for
     *                    encrypting the plaintext data.
     * @param array $cipherOptions Options for use in determining the cipher to
     *                             be used for encrypting data.
     * @param string $messageId a string value used to calculate both a commitment
     *                          key and derived content encryption key
     * @param string $commitmentKey a string value to compare with the calculated commitment
     *                              key value, if the values don't match an exception is raised.
     *
     *
     * @internal
     */
    protected function get_commiting_decrypting_stream(string $cipher_text, string $cek, array $cipher_options, string $message_id, string $commitment_key, Algorithm_Suite $algorithm_suite): Aes_Stream_Interface|Crypto_Exception
    {
        $algorithm_suite_id_as_bytes = pack('n', $algorithm_suite->get_id());
        $derived_encryption_key_info = $algorithm_suite_id_as_bytes . 'DERIVEKEY';
        $commitment_key_info = $algorithm_suite_id_as_bytes . 'COMMITKEY';
        $calculated_commitment_key = hash_hkdf($algorithm_suite->get_hashing_algorithm(), $cek, $algorithm_suite->get_commitment_output_key_length_bytes(), $commitment_key_info, $message_id);
        //= ../specification/s3-encryption/decryption.md#decrypting-with-commitment
        //= type=implication
        //# When using an algorithm suite which supports key commitment, the client MUST verify that the [derived key commitment](./key-derivation.md#hkdf-operation) contains the same bytes as the stored key commitment retrieved from the stored object's metadata.
        if (!hash_equals($commitment_key, $calculated_commitment_key)) {
            //= ../specification/s3-encryption/decryption.md#decrypting-with-commitment
            //= type=implication
            //# When using an algorithm suite which supports key commitment, the client MUST
            //# throw an exception when the derived key commitment value
            //# and stored key commitment value do not match.
            throw new Crypto_Exception('Calculated commitment key does ' . 'not match expected commitment key value ');
        }
        //= ../specification/s3-encryption/decryption.md#decrypting-with-commitment
        //= type=implication
        //# When using an algorithm suite which supports key commitment,
        //# the client MUST verify the key commitment values match before
        //# deriving the [derived encryption key](./key-derivation.md#hkdf-operation).
        $derived_encryption_key = hash_hkdf($algorithm_suite->get_hashing_algorithm(), $cek, $algorithm_suite->get_derivation_output_key_length_bytes(), $derived_encryption_key_info, $message_id);
        $cipher_text_stream = Psr7\Utils::stream_for($cipher_text);
        switch ($cipher_options['Cipher']) {
            case 'gcm':
                $cipher_options['Tag'] = $this->get_tag_from_ciphertext_stream($cipher_text_stream, $cipher_options['TagLength']);
                $cipher_options['Aad'] = isset($cipher_options['Aad']) ? $cipher_options['Aad'] + $algorithm_suite_id_as_bytes : $algorithm_suite_id_as_bytes;
                return new Aes_Gcm_Decrypting_Stream($this->get_stripped_ciphertext_stream($cipher_text_stream, $cipher_options['TagLength']), $derived_encryption_key, $cipher_options['Iv'], $cipher_options['Tag'], $cipher_options['Aad'], $cipher_options['TagLength'] ?: null, $cipher_options['KeySize']);
            default:
                throw new Crypto_Exception('Unsupported Cipher used for key commitment messages.' . " Found {$cipher_options['Cipher']}. Only 'gcm' is supported.");
        }
    }
}