<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Kms\Kms_Client;
/**
 * Uses KMS to supply materials for encrypting and decrypting data.
 *
 * Legacy implementation that supports legacy S3EncryptionClient and
 * S3EncryptionMultipartUploader, which use an older encryption workflow. Use
 * KmsMaterialsProviderV2 with S3EncryptionClientV2 or
 * S3EncryptionMultipartUploaderV2 if possible.
 *
 * @deprecated
 */
class Kms_Materials_Provider extends Materials_Provider implements Materials_Provider_Interface
{
    public const WRAP_ALGORITHM_NAME = 'kms';
    /**
     * @param KmsClient $kmsClient A KMS Client for use encrypting and
     *                             decrypting keys.
     * @param string $kmsKeyId The private KMS key id to be used for encrypting
     *                         and decrypting keys.
     */
    public function __construct(private readonly Kms_Client $kms_client, private $kms_key_id = null)
    {
    }
    public function from_decryption_envelope(Metadata_Envelope $envelope): self
    {
        if (empty($envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER])) {
            throw new \RuntimeException('Not able to detect the materials description.');
        }
        $materials_description = json_decode((string) $envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER], true);
        if (empty($materials_description['kms_cmk_id']) && empty($materials_description['aws:x-amz-cek-alg'])) {
            throw new \RuntimeException('Not able to detect kms_cmk_id (legacy' . ' implementation) or aws:x-amz-cek-alg (current implementation)' . ' from kms materials description.');
        }
        return new self($this->kms_client, $materials_description['kms_cmk_id'] ?? null);
    }
    /**
     * The KMS key id for use in matching this Provider to its keys,
     * consistently with other SDKs as 'kms_cmk_id'.
     */
    public function get_materials_description(): array
    {
        return ['kms_cmk_id' => $this->kms_key_id];
    }
    public function get_wrap_algorithm_name(): string
    {
        return self::WRAP_ALGORITHM_NAME;
    }
    /**
     * Takes a content encryption key (CEK) and description to return an encrypted
     * key by using KMS' Encrypt API.
     *
     * @param string $unencryptedCek Key for use in encrypting other data
     *                               that itself needs to be encrypted by the
     *                               Provider.
     * @param string $materialDescription Material Description for use in
     *                                    encrypting the $cek.
     */
    public function encrypt_cek($unencrypted_cek, $material_description): string
    {
        $encrypted_data_key = $this->kms_client->encrypt(['Plaintext' => $unencrypted_cek, 'KeyId' => $this->kms_key_id, 'EncryptionContext' => $material_description]);
        return base64_encode((string) $encrypted_data_key['CiphertextBlob']);
    }
    /**
     * Takes an encrypted content encryption key (CEK) and material description
     * for use decrypting the key by using KMS' Decrypt API.
     *
     * @param string $encryptedCek Encrypted key to be decrypted by the Provider
     *                             for use decrypting other data.
     * @param string $materialDescription Material Description for use in
     *                                    encrypting the $cek.
     *
     * @return string
     */
    public function decrypt_cek($encrypted_cek, $material_description)
    {
        $result = $this->kms_client->decrypt(['CiphertextBlob' => $encrypted_cek, 'EncryptionContext' => $material_description]);
        return $result['Plaintext'];
    }
}