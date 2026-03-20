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
class Kms_Materials_Provider_V3 extends Materials_Provider_V3 implements Materials_Provider_Interface_V3
{
    public const WRAP_ALGORITHM_NAME = 'kms+context';
    /**
     * @param KmsClient $kmsClient A KMS Client for use encrypting and
     *                             decrypting keys.
     * @param string $kmsKeyId The private KMS key id to be used for encrypting
     *                         and decrypting keys.
     */
    public function __construct(private readonly Kms_Client $kms_client, private readonly ?string $kms_key_id = null)
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
    public function decrypt_cek(string $encrypted_cek, array $material_description, array $options): string
    {
        $options = array_change_key_case($options);
        $encryption_context = null;
        // no encryption context was provided we will use the one in the metadata
        if (!isset($options['@kmsencryptioncontext'])) {
            $encryption_context = $material_description;
        } else {
            // encryption context was passed in, we have to make sure it is an array
            // and that the reserved keywords were not used
            if (!is_array($options['@kmsencryptioncontext'])) {
                throw new Crypto_Exception("'When using @KmsMaterialsProviderV3, it" . ' must be an associative array (or empty array).');
            }
            if (isset($options['@kmsencryptioncontext']['aws:x-amz-cek-alg'])) {
                throw new Crypto_Exception('Conflict in reserved @KmsEncryptionContext' . ' key aws:x-amz-cek-alg. This value is reserved for the S3' . ' Encryption Client and cannot be set by the user.');
            }
            if (isset($options['@kmsencryptioncontext']['kms_cmk_id'])) {
                throw new Crypto_Exception('Conflict in reserved @KmsEncryptionContext' . ' key kms_cmk_id. This value is reserved for the S3' . ' Encryption Client and cannot be set by the user.');
            }
            //= specification/s3-encryption/materials/s3-kms-keyring.md#kms-context
            //= type=implication
            //# When decrypting using Kms+Context mode, the KmsKeyring MUST validate the provided (request) encryption context with the stored (materials) encryption context.
            // We are validating the encryption context to match S3EC V2 behavior
            // Refer to KMSMaterialsHandler in the V2 client for details
            $materials_description_context_copy = $material_description;
            unset($materials_description_context_copy['aws:x-amz-cek-alg']);
            unset($materials_description_context_copy['kms_cmk_id']);
            $request_encryption_context = $options['@kmsencryptioncontext'];
            //= specification/s3-encryption/materials/s3-kms-keyring.md#kms-context
            //= type=implication
            //# The stored encryption context with the two reserved keys removed MUST match the provided encryption context.
            if ($materials_description_context_copy !== $request_encryption_context) {
                //= specification/s3-encryption/materials/s3-kms-keyring.md#kms-context
                //= type=implication
                //# If the stored encryption context with the two reserved keys removed does not match the provided encryption context, the KmsKeyring MUST throw an exception.
                throw new Crypto_Exception('Provided encryption context does not match information retrieved from S3');
            }
            $encryption_context = $material_description;
        }
        $params = ['CiphertextBlob' => $encrypted_cek, 'EncryptionContext' => $encryption_context];
        if (empty($options['@kmsallowdecryptwithanycmk'])) {
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
            throw new Crypto_Exception("'@KmsEncryptionContext' is a" . ' required argument when using KmsMaterialsProviderV3, and' . ' must be an associative array (or empty array).');
        }
        if (isset($options['@kmsencryptioncontext']['aws:x-amz-cek-alg'])) {
            throw new Crypto_Exception('Conflict in reserved @KmsEncryptionContext' . ' key aws:x-amz-cek-alg. This value is reserved for the S3' . ' Encryption Client and cannot be set by the user.');
        }
        if (isset($options['@kmsencryptioncontext']['kms_cmk_id'])) {
            throw new Crypto_Exception('Conflict in reserved @KmsEncryptionContext' . ' key kms_cmk_id. This value is reserved for the S3' . ' Encryption Client and cannot be set by the user.');
        }
        $context = array_merge($options['@kmsencryptioncontext'], $context);
        $result = $this->kms_client->generate_data_key(['KeyId' => $this->kms_key_id, 'KeySpec' => "AES_{$key_size}", 'EncryptionContext' => $context]);
        return ['Plaintext' => $result['Plaintext'], 'Ciphertext' => base64_encode((string) $result['CiphertextBlob']), 'UpdatedContext' => $context];
    }
}