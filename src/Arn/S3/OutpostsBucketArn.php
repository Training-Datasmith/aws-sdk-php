<?php

declare (strict_types=1);
namespace Aws\Arn\S3;

use Aws\Arn\Arn;
use Aws\Arn\Exception\Invalid_Arn_Exception;
use Aws\Arn\Resource_Type_And_Id_Trait;
/**
 * This class represents an S3 Outposts bucket ARN, which is in the
 * following format:
 *
 * @internal
 */
class Outposts_Bucket_Arn extends Arn implements Bucket_Arn_Interface, Outposts_Arn_Interface
{
    use Resource_Type_And_Id_Trait;
    /**
     * Parses a string into an associative array of components that represent
     * a OutpostsBucketArn
     *
     * @param $string
     * @return array
     */
    public static function parse($string)
    {
        $data = parent::parse($string);
        $data = self::parse_resource_type_and_id($data);
        return self::parse_outpost_data($data);
    }
    public function get_bucket_name()
    {
        return $this->data['bucket_name'];
    }
    public function get_outpost_id()
    {
        return $this->data['outpost_id'];
    }
    private static function parse_outpost_data(array $data): array
    {
        $resource_data = preg_split("/[\\/:]/", (string) $data['resource_id'], 3);
        $data['outpost_id'] = $resource_data[0] ?? null;
        $data['bucket_label'] = $resource_data[1] ?? null;
        $data['bucket_name'] = $resource_data[2] ?? null;
        return $data;
    }
    public static function validate(array $data): void
    {
        Arn::validate($data);
        if ($data['service'] !== 's3-outposts') {
            throw new Invalid_Arn_Exception('The 3rd component of an S3 Outposts' . " bucket ARN represents the service and must be 's3-outposts'.");
        }
        self::validate_region($data, 'S3 Outposts bucket ARN');
        self::validate_account_id($data, 'S3 Outposts bucket ARN');
        if ($data['resource_type'] !== 'outpost') {
            throw new Invalid_Arn_Exception('The 6th component of an S3 Outposts' . ' bucket ARN represents the resource type and must be' . " 'outpost'.");
        }
        if (!self::is_valid_host_label($data['outpost_id'])) {
            throw new Invalid_Arn_Exception('The 7th component of an S3 Outposts' . ' bucket ARN is required, represents the outpost ID, and' . ' must be a valid host label.');
        }
        if ($data['bucket_label'] !== 'bucket') {
            throw new Invalid_Arn_Exception('The 8th component of an S3 Outposts' . " bucket ARN must be 'bucket'");
        }
        if (empty($data['bucket_name'])) {
            throw new Invalid_Arn_Exception('The 9th component of an S3 Outposts' . ' bucket ARN represents the bucket name and must not be empty.');
        }
    }
}