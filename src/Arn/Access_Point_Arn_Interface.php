<?php

declare (strict_types=1);
namespace Aws\Arn;

/**
 * @internal
 */
interface Access_Point_Arn_Interface extends Arn_Interface
{
    public function get_accesspoint_name();
}