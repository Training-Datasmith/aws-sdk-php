<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Limit_Stream;
use Psr\Http\Message\Stream_Interface;
trait Decryption_Trait
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
     * @param MaterialsProviderInterface $provider A provider to supply and encrypt
     *                                             materials used in encryption.
     * @param MetadataEnvelope $envelope A storage envelope for encryption
     *                                   metadata to be read from.
     * @param array $cipherOptions Additional verification options.
     *
     * @return AesStreamInterface
     *
     * @throws \InvalidArgumentException Thrown when a value in $cipherOptions
     *                                   is not valid.
     *
     * @internal
     */
    public function decrypt($cipher_text, Materials_Provider_Interface $provider, Metadata_Envelope $envelope, array $cipher_options = [])
    {
        $cipher_options['Iv'] = base64_decode((string) $envelope[Metadata_Envelope::IV_HEADER]);
        $cipher_options['TagLength'] = $envelope[Metadata_Envelope::CRYPTO_TAG_LENGTH_HEADER] / 8;
        $cek = $provider->decrypt_cek(base64_decode((string) $envelope[Metadata_Envelope::CONTENT_KEY_V2_HEADER]), json_decode((string) $envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER], true));
        $cipher_options['KeySize'] = strlen($cek) * 8;
        $cipher_options['Cipher'] = $this->get_cipher_from_aes_name($envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER]);
        $decryption_stream = $this->get_decrypting_stream($cipher_text, $cek, $cipher_options);
        unset($cek);
        return $decryption_stream;
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
}