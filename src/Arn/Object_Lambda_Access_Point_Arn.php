<?php

declare (strict_types=1);
namespace Aws\Arn;

/**
 * This class represents an S3 Object bucket ARN, which is in the
 * following format:
 *
 * @internal
 */
class Object_Lambda_Access_Point_Arn extends Access_Point_Arn
{
    /**
     * Parses a string into an associative array of components that represent
     * a ObjectLambdaAccessPointArn
     *
     * @param $string
     * @return array
     */
    public static function parse($string)
    {
        $data = parent::parse($string);
        return parent::parse_resource_type_and_id($data);
    }
    protected static function validate(array $data)
    {
        parent::validate($data);
        self::validate_region($data, 'S3 Object Lambda ARN');
        self::validate_account_id($data, 'S3 Object Lambda ARN');
    }
}