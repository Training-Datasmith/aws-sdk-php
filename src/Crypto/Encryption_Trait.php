<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Append_Stream;
use Guzzle_Http\Psr7\Stream;
trait Encryption_Trait
{
    private static $allowed_options = ['Cipher' => true, 'KeySize' => true, 'Aad' => true];
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
     * @param array $cipherOptions Options for use in determining the cipher to
     *                             be used for encrypting data.
     * @param MaterialsProvider $provider A provider to supply and encrypt
     *                                    materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be added to.
     *
     * @return AesStreamInterface
     *
     * @throws \InvalidArgumentException Thrown when a value in $cipherOptions
     *                                   is not valid.
     *
     * @internal
     */
    public function encrypt(Stream $plaintext, array $cipher_options, Materials_Provider $provider, Metadata_Envelope $envelope)
    {
        $materials_description = $provider->get_materials_description();
        $cipher_options = array_intersect_key($cipher_options, self::$allowed_options);
        if (empty($cipher_options['Cipher'])) {
            throw new \InvalidArgumentException('An encryption cipher must be' . ' specified in the "cipher_options".');
        }
        if (!self::is_supported_cipher($cipher_options['Cipher'])) {
            throw new \InvalidArgumentException('The cipher requested is not' . ' supported by the SDK.');
        }
        if (empty($cipher_options['KeySize'])) {
            $cipher_options['KeySize'] = 256;
        }
        if (!is_int($cipher_options['KeySize'])) {
            throw new \InvalidArgumentException('The cipher "KeySize" must be' . ' an integer.');
        }
        if (!Materials_Provider::is_supported_key_size($cipher_options['KeySize'])) {
            throw new \InvalidArgumentException('The cipher "KeySize" requested' . ' is not supported by AES (128, 192, or 256).');
        }
        $cipher_options['Iv'] = $provider->generate_iv($this->get_cipher_open_ssl_name($cipher_options['Cipher'], $cipher_options['KeySize']));
        $cek = $provider->generate_cek($cipher_options['KeySize']);
        [$encrypting_stream, $aes_name] = $this->get_encrypting_stream($plaintext, $cek, $cipher_options);
        // Populate envelope data
        $envelope[Metadata_Envelope::CONTENT_KEY_V2_HEADER] = $provider->encrypt_cek($cek, $materials_description);
        unset($cek);
        $envelope[Metadata_Envelope::IV_HEADER] = base64_encode($cipher_options['Iv']);
        $envelope[Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER] = $provider->get_wrap_algorithm_name();
        $envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER] = $aes_name;
        $envelope[Metadata_Envelope::UNENCRYPTED_CONTENT_LENGTH_HEADER] = strlen($plaintext);
        $envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER] = json_encode($materials_description);
        if (!empty($cipher_options['Tag'])) {
            $envelope[Metadata_Envelope::CRYPTO_TAG_LENGTH_HEADER] = strlen((string) $cipher_options['Tag']) * 8;
        }
        return $encrypting_stream;
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
     * @return array returns an array with two elements as follows: [string, AesStreamInterface]
     *
     * @internal
     */
    protected function get_encrypting_stream(Stream $plaintext, $cek, array &$cipher_options)
    {
        switch ($cipher_options['Cipher']) {
            case 'gcm':
                $cipher_options['TagLength'] = 16;
                $cipher_text_stream = new Aes_Gcm_Encrypting_Stream($plaintext, $cek, $cipher_options['Iv'], $cipher_options['Aad'] ??= '', $cipher_options['TagLength'], $cipher_options['KeySize']);
                if (!empty($cipher_options['Aad'])) {
                    trigger_error("'Aad' has been supplied for content encryption" . ' with ' . $cipher_text_stream->get_aes_name() . '. The' . ' PHP SDK encryption client can decrypt an object' . ' encrypted in this way, but other AWS SDKs may not be' . ' able to.', E_USER_WARNING);
                }
                $append_stream = new Append_Stream([$cipher_text_stream->create_stream()]);
                $cipher_options['Tag'] = $cipher_text_stream->get_tag();
                $append_stream->add_stream(Psr7\Utils::stream_for($cipher_options['Tag']));
                return [$append_stream, $cipher_text_stream->get_aes_name()];
            default:
                $cipher_method = $this->build_cipher_method($cipher_options['Cipher'], $cipher_options['Iv'], $cipher_options['KeySize']);
                $cipher_text_stream = new Aes_Encrypting_Stream($plaintext, $cek, $cipher_method);
                return [$cipher_text_stream, $cipher_text_stream->get_aes_name()];
        }
    }
}