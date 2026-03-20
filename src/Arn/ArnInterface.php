<?php

declare (strict_types=1);
namespace Aws\Arn;

/**
 * Amazon Resource Names (ARNs) uniquely identify AWS resources. Classes
 * implementing ArnInterface parse and store an ARN object representation.
 *
 * Valid ARN formats include:
 *
 *   arn:partition:service:region:account-id:resource-id
 *   arn:partition:service:region:account-id:resource-type/resource-id
 *   arn:partition:service:region:account-id:resource-type:resource-id
 *
 * Some components may be omitted, depending on the service and resource type.
 *
 * @internal
 */
interface Arn_Interface
{
    public static function parse($string);
    public function __toString();
    public function get_prefix();
    public function get_partition();
    public function get_service();
    public function get_region();
    public function get_account_id();
    public function get_resource();
    public function to_array();
}