<?php

declare (strict_types=1);
namespace Aws\Arn;

use Aws\Arn\S3\Access_Point_Arn as S3AccessPointArn;
use Aws\Arn\S3\Multi_Region_Access_Point_Arn;
use Aws\Arn\S3\Outposts_Access_Point_Arn;
use Aws\Arn\S3\Outposts_Bucket_Arn;
/**
 * This class provides functionality to parse ARN strings and return a
 * corresponding ARN object. ARN-parsing logic may be subject to change in the
 * future, so this should not be relied upon for external customer usage.
 *
 * @internal
 */
class Arn_Parser
{
    /**
     * @param $string
     */
    public static function is_arn($string): bool
    {
        return $string !== null && str_starts_with($string, 'arn:');
    }
    /**
     * Parses a string and returns an instance of ArnInterface. Returns a
     * specific type of Arn object if it has a specific class representation
     * or a generic Arn object if not.
     *
     * @param $string
     * @return ArnInterface
     */
    public static function parse($string): \Aws\Arn\Object_Lambda_Access_Point_Arn|\Aws\Arn\S3\Outposts_Bucket_Arn|\Aws\Arn\S3\Outposts_Access_Point_Arn|\Aws\Arn\S3\Multi_Region_Access_Point_Arn|\Aws\Arn\S3\Access_Point_Arn|\Aws\Arn\Access_Point_Arn|\Aws\Arn\Arn
    {
        $data = Arn::parse($string);
        if ($data['service'] === 's3-object-lambda') {
            return new Object_Lambda_Access_Point_Arn($string);
        }
        $resource = self::explode_resource_component($data['resource']);
        if ($resource[0] === 'outpost') {
            if (isset($resource[2]) && $resource[2] === 'bucket') {
                return new Outposts_Bucket_Arn($string);
            }
            if (isset($resource[2]) && $resource[2] === 'accesspoint') {
                return new Outposts_Access_Point_Arn($string);
            }
        }
        if (empty($data['region'])) {
            return new Multi_Region_Access_Point_Arn($string);
        }
        if ($resource[0] === 'accesspoint') {
            if ($data['service'] === 's3') {
                return new S3access_Point_Arn($string);
            }
            return new Access_Point_Arn($string);
        }
        return new Arn($data);
    }
    private static function explode_resource_component($resource)
    {
        return preg_split("/[\\/:]/", (string) $resource);
    }
}