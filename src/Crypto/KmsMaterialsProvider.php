<?php
namespace Aws\Crypto;

use Aws\Kms\KmsClient;

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
class KmsMaterialsProvider extends MaterialsProvider implements MaterialsProviderInterface
{
    const WRAP_ALGORITHM_NAME = 'kms';

    /**
     * @param KmsClient $kmsClient A KMS Client for use encrypting and
     *                             decrypting keys.
     * @param string $kmsKeyId The private KMS key id to be used for encrypting
     *                         and decrypting keys.
     */
    public function __construct(private readonly KmsClient $kmsClient, private $kmsKeyId = null)
    {
    }

    public function fromDecryptionEnvelope(MetadataEnvelope $envelope): self
    {
        if (empty($envelope[MetadataEnvelope::MATERIALS_DESCRIPTION_HEADER])) {
            throw new \RuntimeException('Not able to detect the materials description.');
        }

        $materialsDescription = json_decode(
            (string) $envelope[MetadataEnvelope::MATERIALS_DESCRIPTION_HEADER],
            true
        );

        if (empty($materialsDescription['kms_cmk_id'])
            && empty($materialsDescription['aws:x-amz-cek-alg'])) {
            throw new \RuntimeException('Not able to detect kms_cmk_id (legacy'
                . ' implementation) or aws:x-amz-cek-alg (current implementation)'
                . ' from kms materials description.');
        }

        return new self(
            $this->kmsClient,
            $materialsDescription['kms_cmk_id'] ?? null
        );
    }

    /**
     * The KMS key id for use in matching this Provider to its keys,
     * consistently with other SDKs as 'kms_cmk_id'.
     */
    public function getMaterialsDescription(): array
    {
        return ['kms_cmk_id' => $this->kmsKeyId];
    }

    public function getWrapAlgorithmName(): string
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
    public function encryptCek($unencryptedCek, $materialDescription): string
    {
        $encryptedDataKey = $this->kmsClient->encrypt([
            'Plaintext' => $unencryptedCek,
            'KeyId' => $this->kmsKeyId,
            'EncryptionContext' => $materialDescription
        ]);
        return base64_encode((string) $encryptedDataKey['CiphertextBlob']);
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
    public function decryptCek($encryptedCek, $materialDescription)
    {
        $result = $this->kmsClient->decrypt([
            'CiphertextBlob' => $encryptedCek,
            'EncryptionContext' => $materialDescription
        ]);

        return $result['Plaintext'];
    }
}
