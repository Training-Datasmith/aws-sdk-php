<?php

declare (strict_types=1);
namespace Aws\Arn\S3;

use Aws\Arn\Access_Point_Arn as BaseAccessPointArn;
use Aws\Arn\Access_Point_Arn_Interface;
use Aws\Arn\Exception\Invalid_Arn_Exception;
/**
 * @internal
 */
class Access_Point_Arn extends Base_Access_Point_Arn implements Access_Point_Arn_Interface
{
    /**
     * Validation specific to AccessPointArn
     */
    public static function validate(array $data): void
    {
        parent::validate($data);
        if ($data['service'] !== 's3') {
            throw new Invalid_Arn_Exception('The 3rd component of an S3 access' . " point ARN represents the region and must be 's3'.");
        }
    }
}