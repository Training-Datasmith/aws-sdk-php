<?php

declare (strict_types=1);
namespace Aws\Crypto;

use ArrayAccess;
use Aws\Has_Data_Trait;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
/**
 * Stores encryption metadata for reading and writing.
 *
 * @internal
 */
class Metadata_Envelope implements ArrayAccess, IteratorAggregate, JsonSerializable
{
    use Has_Data_Trait;
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# The "x-amz-" prefix denotes that the metadata is owned by an Amazon product
    //# and MUST be prepended to all S3EC metadata mapkeys.
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-key-v2" MUST be present for V2 format objects.
    public const CONTENT_KEY_V2_HEADER = 'x-amz-key-v2';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //= type=implication
    //# - This mapkey ("x-amz-3") SHOULD be represented by a constant named "ENCRYPTED_DATA_KEY_V3" or similar in the implementation code.
    public const ENCRYPTED_DATA_KEY_V3 = 'x-amz-3';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-iv" MUST be present for V1 format objects.
    public const IV_HEADER = 'x-amz-iv';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-matdesc" MUST be present for V1 format objects.
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-matdesc" MUST be present for V2 format objects.
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-iv" MUST be present for V2 format objects.
    public const MATERIALS_DESCRIPTION_HEADER = 'x-amz-matdesc';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //= type=implication
    //# - This mapkey ("x-amz-m") SHOULD be represented by a constant named "MAT_DESC_V3" or similar in the implementation code.
    public const MAT_DESC_V3 = 'x-amz-m';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-wrap-alg" MUST be present for V2 format objects.
    public const KEY_WRAP_ALGORITHM_HEADER = 'x-amz-wrap-alg';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //= type=implication
    //# - This mapkey ("x-amz-w") SHOULD be represented by a constant named "ENCRYPTED_DATA_KEY_ALGORITHM_V3" or similar in the implementation code.
    public const ENCRYPTED_DATA_KEY_ALGORITHM_V3 = 'x-amz-w';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-cek-alg" MUST be present for V2 format objects.
    public const CONTENT_CRYPTO_SCHEME_HEADER = 'x-amz-cek-alg';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //= type=implication
    //# - This mapkey ("x-amz-c") SHOULD be represented by a constant named "CONTENT_CIPHER_V3" or similar in the implementation code.
    public const CONTENT_CIPHER_V3 = 'x-amz-c';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-tag-len" MUST be present for V2 format objects.
    public const CRYPTO_TAG_LENGTH_HEADER = 'x-amz-tag-len';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //# - The mapkey "x-amz-unencrypted-content-length" SHOULD be present for V1 format objects.
    public const UNENCRYPTED_CONTENT_LENGTH_HEADER = 'x-amz-unencrypted-content-length';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //= type=implication
    //# - This mapkey ("x-amz-t") SHOULD be represented by a constant named "ENCRYPTION_CONTEXT_V3" or similar in the implementation code.
    public const ENCRYPTION_CONTEXT_V3 = 'x-amz-t';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //= type=implication
    //# - This mapkey ("x-amz-d") SHOULD be represented by a constant named "KEY_COMMITMENT_V3" or similar in the implementation code.
    public const KEY_COMMITMENT_V3 = 'x-amz-d';
    //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
    //= type=implication
    //# - This mapkey ("x-amz-i") SHOULD be represented by a constant named "MESSAGE_ID_V3" or similar in the implementation code.
    public const MESSAGE_ID_V3 = 'x-amz-i';
    private static array $constants = [];
    public static function get_constant_values(): array
    {
        if (empty(self::$constants)) {
            $reflection = new \ReflectionClass(static::class);
            //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
            //# The "x-amz-meta-" prefix is automatically added by the S3 server and MUST NOT be included in implementation code.
            foreach (array_values($reflection->get_constants()) as $constant) {
                self::$constants[$constant] = true;
            }
        }
        return array_keys(self::$constants);
    }
    #[\Return_Type_Will_Change]
    public function offsetSet($name, $value): void
    {
        $constants = self::get_constant_values();
        //= ../specification/s3-encryption/data-format/content-metadata.md#determining-s3ec-object-status
        //# In general, if there is any deviation from the above format, with the exception of additional unrelated mapkeys, then the S3EC SHOULD throw an exception.
        if (is_null($name) || !in_array($name, $constants)) {
            throw new InvalidArgumentException('MetadataEnvelope fields must' . ' must match a predefined offset; use the header constants.');
        }
        $this->data[$name] = $value;
    }
    #[\Return_Type_Will_Change]
    public function jsonSerialize()
    {
        return $this->data;
    }
    public static function is_v2envelope(Metadata_Envelope $envelope): bool
    {
        if (!isset($envelope[Metadata_Envelope::CONTENT_KEY_V2_HEADER]) || !isset($envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER]) || !isset($envelope[Metadata_Envelope::IV_HEADER]) || !isset($envelope[Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER]) || !isset($envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER]) || !isset($envelope[Metadata_Envelope::CRYPTO_TAG_LENGTH_HEADER])) {
            return false;
        }
        return true;
    }
    public static function is_v1envelope(Metadata_Envelope $envelope): bool
    {
        if (!isset($envelope[Metadata_Envelope::CONTENT_KEY_V2_HEADER]) || !isset($envelope[Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER]) || !isset($envelope[Metadata_Envelope::IV_HEADER]) || !isset($envelope[Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER]) || !isset($envelope[Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER]) || !isset($envelope[Metadata_Envelope::UNENCRYPTED_CONTENT_LENGTH_HEADER])) {
            return false;
        }
        return true;
    }
    public static function is_v3envelope(Metadata_Envelope $envelope): bool
    {
        //= ../specification/s3-encryption/data-format/content-metadata.md#content-metadata-mapkeys
        //= type=implication
        //# - The mapkey "x-amz-3" MUST be present for V3 format objects.
        if (!isset($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_V3]) || !isset($envelope[Metadata_Envelope::CONTENT_CIPHER_V3]) || !isset($envelope[Metadata_Envelope::KEY_COMMITMENT_V3]) || !isset($envelope[Metadata_Envelope::MESSAGE_ID_V3]) || !isset($envelope[Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3]) || !isset($envelope[Metadata_Envelope::ENCRYPTION_CONTEXT_V3])) {
            return false;
        }
        return true;
    }
    public static function get_v2fields(): array
    {
        return [Metadata_Envelope::CONTENT_KEY_V2_HEADER, Metadata_Envelope::MATERIALS_DESCRIPTION_HEADER, Metadata_Envelope::IV_HEADER, Metadata_Envelope::KEY_WRAP_ALGORITHM_HEADER, Metadata_Envelope::CONTENT_CRYPTO_SCHEME_HEADER, Metadata_Envelope::CRYPTO_TAG_LENGTH_HEADER];
    }
    public static function get_v3fields(): array
    {
        return [Metadata_Envelope::ENCRYPTED_DATA_KEY_V3, Metadata_Envelope::CONTENT_CIPHER_V3, Metadata_Envelope::KEY_COMMITMENT_V3, Metadata_Envelope::MESSAGE_ID_V3, Metadata_Envelope::ENCRYPTED_DATA_KEY_ALGORITHM_V3, Metadata_Envelope::ENCRYPTION_CONTEXT_V3];
    }
}