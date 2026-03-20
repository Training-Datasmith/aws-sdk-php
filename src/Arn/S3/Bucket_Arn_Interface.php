<?php

declare (strict_types=1);
namespace Aws\Arn\S3;

use Aws\Arn\Arn_Interface;
/**
 * @internal
 */
interface Bucket_Arn_Interface extends Arn_Interface
{
    public function get_bucket_name();
}