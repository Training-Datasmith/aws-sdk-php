<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Exception\Crypto_Exception;
use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Limit_Stream;
use Psr\Http\Message\Stream_Interface;
trait Decryption_Trait_V2
{
    /**
     * Dependency to reverse lookup the openssl_* cipher name from the AESName
     * in the MetadataEnvelope.
     *
     * @param $aesName
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
     * legacy and V2 encryption client workflows.
     *
     * @param string $cipherText Plain-text data to be encrypted using the
     *                           materials, algorithm, and data provided.
     * @param MaterialsProviderInterfaceV2 $provider A provider to supply and encrypt
     *                                             materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be read from.
     * @param array $options Options used for decryption.
     *
     * @return AesStreamInterface
     *
     * @throws \InvalidArgumentException Thrown when a value in $cipherOptions
     *                                   is not valid.
     *
     * @internal
     */
    public function decrypt($cipher_text, Materials_Provider_Interface_V2 $provider, Metadata_Envelope $envelope, array $options = [])
    {
        $commitment_policy = $this->get_key_commitment_policy($options);
        unset($options['@CommitmentPolicy']);
        if (isset($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_V3])) {
            if ($commitment_policy !== 'FORBID_ENCRYPT_ALLOW_DECRYPT') {
                throw new Crypto_Exception('The requested item is encrypted' . " with Key Commitment. The current policy {$commitment_policy}" . ' conflicts with the object metadata. Select an appropriate' . " '@CommitmentPolicy' to decrypt this item.");
            }
            $algorithm_suite = Algorithm_Suite::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY;
            $options['@CipherOptions'] ??= [];
            $options['@CipherOptions']['Iv'] = str_repeat("\x01", 12);
            $options['@CipherOptions']['TagLength'] = $algorithm_suite->get_cipher_tag_length_in_bytes();
            $material_description = json_decode((string) $envelope[Metadata_Envelope::ENCRYPTION_CONTEXT_V3], true);
            $cek = $provider->decrypt_cek(base64_decode((string) $envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_V3]), $material_description, $options);
            $options['@CipherOptions']['KeySize'] = strlen($cek) * 8;
            $options['@CipherOptions']['Cipher'] = $this->get_cipher_from_aes_name($this->numerical_conten_cipher_to_aes_name($envelope));
            $this->validate_options_and_envelope($options, $envelope);
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
        $options['@CipherOptions'] ??= [];
        $options['@CipherOptions']['Iv'] = base64_decode((string) $envelope[Metadata_Envelope::IV_HEADER]);
        $options['@CipherOptions']['TagLength'] = $envelope[Metadata_Envelope::CRYPTO_TAG_LENGTH_HEADER] / 8;
        $cek = $provider->decrypt_cek(base64_decode((string) $envelope[Metadata_Envelope::CONTENT_KEY_V2_HEADER]), json_decode((string) $envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER], true), $options);
        $options['@CipherOptions']['KeySize'] = strlen($cek) * 8;
        $options['@CipherOptions']['Cipher'] = $this->get_cipher_from_aes_name($envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER]);
        $this->validate_options_and_envelope($options, $envelope);
        $decryption_stream = $this->get_decrypting_stream($cipher_text, $cek, $options['@CipherOptions']);
        unset($cek);
        return $decryption_stream;
    }
    private function build_material_description(Metadata_Envelope $envelope): array
    {
        return match ($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]) {
            12 => ['aws:x-amz-cek-alg' => '115'],
            default => throw new Crypto_Exception('Unknown Encrypted Data Key ' . 'wrapping algorithm found: ' . "{$envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]}"),
        };
    }
    private function numerical_conten_cipher_to_aes_name(Metadata_Envelope $envelope): string
    {
        return match ($envelope[Metadata_Envelope::CONTENT_CIPHER_V3]) {
            115 => 'AES/GCM/NoPadding',
            default => throw new Crypto_Exception('Unknown Encrypted Data Key ' . 'wrapping algorithm found: ' . "{$envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]}"),
        };
    }
    private function get_tag_from_ciphertext_stream(Stream_Interface $cipher_text, $tag_length)
    {
        $cipher_text_size = $cipher_text->get_size();
        if ($cipher_text_size == null || $cipher_text_size <= 0) {
            throw new \RuntimeException('Cannot decrypt a stream of unknown' . ' size.');
        }
        return (string) new Limit_Stream($cipher_text, $tag_length, $cipher_text_size - $tag_length);
    }
    private function get_stripped_ciphertext_stream(Stream_Interface $cipher_text, $tag_length)
    {
        $cipher_text_size = $cipher_text->get_size();
        if ($cipher_text_size == null || $cipher_text_size <= 0) {
            throw new \RuntimeException('Cannot decrypt a stream of unknown' . ' size.');
        }
        return new Limit_Stream($cipher_text, $cipher_text_size - $tag_length, 0);
    }
    private function validate_options_and_envelope(array $options, array $envelope): void
    {
        $allowed_ciphers = Abstract_Crypto_Client_V2::$supported_ciphers;
        $allowed_keywraps = Abstract_Crypto_Client_V2::$supported_key_wraps;
        if ($options['@SecurityProfile'] == 'V2_AND_LEGACY') {
            $allowed_ciphers = array_unique(array_merge($allowed_ciphers, Abstract_Crypto_Client::$supported_ciphers));
            $allowed_keywraps = array_unique(array_merge($allowed_keywraps, Abstract_Crypto_Client::$supported_key_wraps));
        }
        $v1schema_exception = new Crypto_Exception('The requested object is encrypted' . ' with V1 encryption schemas that have been disabled by' . ' client configuration @SecurityProfile=V2. Retry with' . ' V2_AND_LEGACY enabled or reencrypt the object.');
        if (!in_array($options['@CipherOptions']['Cipher'], $allowed_ciphers)) {
            if (in_array($options['@CipherOptions']['Cipher'], Abstract_Crypto_Client::$supported_ciphers)) {
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
     * @return AesStreamInterface
     *
     * @internal
     */
    protected function get_decrypting_stream($cipher_text, $cek, array $cipher_options): \Aws\Crypto\Aes_Gcm_Decrypting_Stream|\Aws\Crypto\Aes_Decrypting_Stream
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
        $derived_encryption_key = hash_hkdf($algorithm_suite->get_hashing_algorithm(), $cek, $algorithm_suite->get_derivation_output_key_length_bytes(), $derived_encryption_key_info, $message_id);
        $calculated_commitment_key = hash_hkdf($algorithm_suite->get_hashing_algorithm(), $cek, $algorithm_suite->get_commitment_output_key_length_bytes(), $commitment_key_info, $message_id);
        if ($commitment_key != $calculated_commitment_key) {
            throw new Crypto_Exception('Calculated commitment key does ' . 'not match expected commitment key value ');
        }
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