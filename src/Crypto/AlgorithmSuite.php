<?php

declare (strict_types=1);
namespace Aws\Crypto;

use Aws\S3\Crypto\S3encryption_Client_V3;
enum Algorithm_Suite : int
{
    case ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY = 0x73;
    case ALG_AES_256_GCM_IV12_TAG16_NO_KDF = 0x72;
    case ALG_AES_256_CBC_IV16_NO_KDF = 0x70;
    public function get_id(): int
    {
        return $this->value;
    }
    public function is_legacy(): bool
    {
        return match ($this) {
            self::ALG_AES_256_CBC_IV16_NO_KDF => true,
            default => false,
        };
    }
    public function is_key_committing(): bool
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY => true,
            default => false,
        };
    }
    public function get_data_key_algorithm(): string
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY, self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF, self::ALG_AES_256_CBC_IV16_NO_KDF => 'AES',
        };
    }
    public function get_data_key_length_bits(): string
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY, self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF, self::ALG_AES_256_CBC_IV16_NO_KDF => '256',
        };
    }
    public function get_cipher_name(): string
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY, self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF => 'gcm',
            self::ALG_AES_256_CBC_IV16_NO_KDF => 'cbc',
        };
    }
    public function get_cipher_block_size_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY, self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF, self::ALG_AES_256_CBC_IV16_NO_KDF => 128,
        };
    }
    public function get_cipher_block_size_bytes(): int
    {
        return $this->get_cipher_block_size_bits() / 8;
    }
    public function get_iv_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY, self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF => 96,
            self::ALG_AES_256_CBC_IV16_NO_KDF => 128,
        };
    }
    public function get_iv_length_bytes(): int
    {
        return $this->get_iv_length_bits() / 8;
    }
    public function get_cipher_tag_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY, self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF => 128,
            self::ALG_AES_256_CBC_IV16_NO_KDF => 0,
        };
    }
    public function get_cipher_tag_length_in_bytes(): int
    {
        return $this->get_cipher_tag_length_bits() / 8;
    }
    public function get_cipher_max_content_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY, self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF => Algorithm_Constants::GCM_MAX_CONTENT_LENGTH_BITS,
            self::ALG_AES_256_CBC_IV16_NO_KDF => Algorithm_Constants::CBC_MAX_CONTENT_LENGTH_BYTES,
        };
    }
    public function get_cipher_max_content_length_bytes(): int
    {
        return $this->get_cipher_max_content_length_bits() / 8;
    }
    public function get_hashing_algorithm(): string
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY => 'sha512',
            default => '',
        };
    }
    public function get_derivation_input_key_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY => 256,
            default => 0,
        };
    }
    public function get_derivation_input_key_length_bytes(): int
    {
        return $this->get_derivation_input_key_length_bits() / 8;
    }
    public function get_derivation_output_key_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY => 256,
            default => 0,
        };
    }
    public function get_derivation_output_key_length_bytes(): int
    {
        return $this->get_derivation_output_key_length_bits() / 8;
    }
    public function get_commitment_input_key_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY => 256,
            default => 0,
        };
    }
    public function get_commitment_input_key_length_bytes(): int
    {
        return $this->get_commitment_input_key_length_bits() / 8;
    }
    public function get_commitment_output_key_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY => 224,
            default => 0,
        };
    }
    public function get_commitment_output_key_length_bytes(): int
    {
        return $this->get_commitment_output_key_length_bits() / 8;
    }
    public function get_key_commitment_salt_length_bits(): int
    {
        return match ($this) {
            self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY => 224,
            default => 0,
        };
    }
    //= ../specification/s3-encryption/client.md#key-commitment
    //# The S3EC MUST validate the configured Encryption Algorithm against the provided key commitment policy.
    public static function validate_commitment_policy_on_encrypt(array $cipher_options, string $key_commitment_policy): self
    {
        $cipher_options['Cipher'] = strtolower((string) $cipher_options['Cipher']);
        //= ../specification/s3-encryption/client.md#encryption-algorithm
        //# The S3EC MUST validate that the configured encryption algorithm is not legacy.
        if (!S3encryption_Client_V3::is_supported_cipher($cipher_options['Cipher'])) {
            //= ../specification/s3-encryption/client.md#encryption-algorithm
            //# If the configured encryption algorithm is legacy, then the S3EC MUST throw an exception.
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
        //= ../specification/s3-encryption/key-commitment.md#commitment-policy
        //# When the commitment policy is FORBID_ENCRYPT_ALLOW_DECRYPT, the S3EC MUST NOT encrypt using an algorithm suite which supports key commitment.
        if ($key_commitment_policy === 'FORBID_ENCRYPT_ALLOW_DECRYPT') {
            return self::ALG_AES_256_GCM_IV12_TAG16_NO_KDF;
        }
        //= ../specification/s3-encryption/key-commitment.md#commitment-policy
        //# When the commitment policy is REQUIRE_ENCRYPT_ALLOW_DECRYPT, the S3EC MUST only encrypt using an algorithm suite which supports key commitment.
        //= ../specification/s3-encryption/key-commitment.md#commitment-policy
        //# When the commitment policy is REQUIRE_ENCRYPT_REQUIRE_DECRYPT, the S3EC MUST only encrypt using an algorithm suite which supports key commitment.
        return self::ALG_AES_256_GCM_HKDF_SHA512_COMMIT_KEY;
    }
}