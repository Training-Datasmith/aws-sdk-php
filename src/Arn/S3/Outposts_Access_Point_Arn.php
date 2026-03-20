<?php

declare (strict_types=1);
namespace Aws\Arn\S3;

use Aws\Arn\Access_Point_Arn as BaseAccessPointArn;
use Aws\Arn\Access_Point_Arn_Interface;
use Aws\Arn\Arn;
use Aws\Arn\Exception\Invalid_Arn_Exception;
/**
 * This class represents an S3 Outposts access point ARN, which is in the
 * following format:
 *
 * arn:{partition}:s3-outposts:{region}:{accountId}:outpost:{outpostId}:accesspoint:{accesspointName}
 *
 * ':' and '/' can be used interchangeably as delimiters for components after
 * the account ID.
 *
 * @internal
 */
class Outposts_Access_Point_Arn extends Base_Access_Point_Arn implements Access_Point_Arn_Interface, Outposts_Arn_Interface
{
    public static function parse($string)
    {
        $data = parent::parse($string);
        return self::parse_outpost_data($data);
    }
    public function get_outpost_id()
    {
        return $this->data['outpost_id'];
    }
    public function get_accesspoint_name()
    {
        return $this->data['accesspoint_name'];
    }
    private static function parse_outpost_data(array $data): array
    {
        $resource_data = preg_split("/[\\/:]/", (string) $data['resource_id']);
        $data['outpost_id'] = $resource_data[0] ?? null;
        $data['accesspoint_type'] = $resource_data[1] ?? null;
        $data['accesspoint_name'] = $resource_data[2] ?? null;
        if (isset($resource_data[3])) {
            $data['resource_extra'] = implode(':', array_slice($resource_data, 3));
        }
        return $data;
    }
    /**
     * Validation specific to OutpostsAccessPointArn. Note this uses the base Arn
     * class validation instead of the direct parent due to it having slightly
     * differing requirements from its parent.
     */
    public static function validate(array $data): void
    {
        Arn::validate($data);
        if ($data['service'] !== 's3-outposts') {
            throw new Invalid_Arn_Exception('The 3rd component of an S3 Outposts' . ' access point ARN represents the service and must be' . " 's3-outposts'.");
        }
        self::validate_region($data, 'S3 Outposts access point ARN');
        self::validate_account_id($data, 'S3 Outposts access point ARN');
        if ($data['resource_type'] !== 'outpost') {
            throw new Invalid_Arn_Exception('The 6th component of an S3 Outposts' . ' access point ARN represents the resource type and must be' . " 'outpost'.");
        }
        if (!self::is_valid_host_label($data['outpost_id'])) {
            throw new Invalid_Arn_Exception('The 7th component of an S3 Outposts' . ' access point ARN is required, represents the outpost ID, and' . ' must be a valid host label.');
        }
        if ($data['accesspoint_type'] !== 'accesspoint') {
            throw new Invalid_Arn_Exception('The 8th component of an S3 Outposts' . " access point ARN must be 'accesspoint'");
        }
        if (!self::is_valid_host_label($data['accesspoint_name'])) {
            throw new Invalid_Arn_Exception('The 9th component of an S3 Outposts' . ' access point ARN is required, represents the accesspoint name,' . ' and must be a valid host label.');
        }
        if (!empty($data['resource_extra'])) {
            throw new Invalid_Arn_Exception('An S3 Outposts access point ARN' . ' should only have 9 components, delimited by the characters' . " ':' and '/'. '{$data['resource_extra']}' was found after the" . ' 9th component.');
        }
    }
}