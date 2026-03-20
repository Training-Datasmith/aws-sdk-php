<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\Exception\Crypto_Exception;
use Aws\Kms\Kms_Client;
/**
 * Uses KMS to supply materials for encrypting and decrypting data. This
 * V2 implementation should be used with the V2 encryption clients (i.e.
 * S3EncryptionClientV2).
 */
class Kms_Materials_Provider_V2 extends Materials_Provider_V2 implements Materials_Provider_Interface_V2
{
    public const WRAP_ALGORITHM_NAME = 'kms+context';
    /**
     * @param KmsClient $kmsClient A KMS Client for use encrypting and
     *                             decrypting keys.
     * @param string $kmsKeyId The private KMS key id to be used for encrypting
     *                         and decrypting keys.
     */
    public function __construct(private readonly Kms_Client $kms_client, private $kms_key_id = null)
    {
    }
    /**
     * @inheritDoc
     */
    public function get_wrap_algorithm_name(): string
    {
        return self::WRAP_ALGORITHM_NAME;
    }
    /**
     * @inheritDoc
     */
    public function decrypt_cek($encrypted_cek, $material_description, $options)
    {
        $params = ['CiphertextBlob' => $encrypted_cek, 'EncryptionContext' => $material_description];
        if (empty($options['@KmsAllowDecryptWithAnyCmk'])) {
            if (empty($this->kms_key_id)) {
                throw new Crypto_Exception('KMS CMK ID was not specified and the' . ' operation is not opted-in to attempting to use any valid' . ' CMK it discovers. Please specify a CMK ID, or explicitly' . ' enable attempts to use any valid KMS CMK with the' . ' @KmsAllowDecryptWithAnyCmk option.');
            }
            $params['KeyId'] = $this->kms_key_id;
        }
        $result = $this->kms_client->decrypt($params);
        return $result['Plaintext'];
    }
    /**
     * @inheritDoc
     */
    public function generate_cek($key_size, $context, $options): array
    {
        if (empty($this->kms_key_id)) {
            throw new Crypto_Exception('A KMS key id is required for encryption' . ' with KMS keywrap. Use a KmsMaterialsProviderV2 that has been' . ' instantiated with a KMS key id.');
        }
        $options = array_change_key_case($options);
        if (!isset($options['@kmsencryptioncontext']) || !is_array($options['@kmsencryptioncontext'])) {
            throw new Crypto_Exception("'@KmsEncryptionContext' is a" . ' required argument when using KmsMaterialsProviderV2, and' . ' must be an associative array (or empty array).');
        }
        if (isset($options['@kmsencryptioncontext']['aws:x-amz-cek-alg'])) {
            throw new Crypto_Exception('Conflict in reserved @KmsEncryptionContext' . ' key aws:x-amz-cek-alg. This value is reserved for the S3' . ' Encryption Client and cannot be set by the user.');
        }
        $context = array_merge($options['@kmsencryptioncontext'], $context);
        $result = $this->kms_client->generate_data_key(['KeyId' => $this->kms_key_id, 'KeySpec' => "AES_{$key_size}", 'EncryptionContext' => $context]);
        return ['Plaintext' => $result['Plaintext'], 'Ciphertext' => base64_encode((string) $result['CiphertextBlob']), 'UpdatedContext' => $context];
    }
}