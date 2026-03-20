<?php

declare (strict_types=1);
namespace Aws\Api\Serializer;

use Aws\Api\List_Shape;
use Aws\Api\Shape;
/**
 * @internal
 */
class Ec2param_Builder extends Query_Param_Builder
{
    protected function query_name(Shape $shape, $default = null)
    {
        return ($shape['queryName'] ?: ucfirst((string) @$shape['locationName'] ?: '')) ?: $default;
    }
    protected function is_flat(Shape $shape): bool
    {
        return false;
    }
    protected function format_list(List_Shape $shape, array $value, $prefix, &$query)
    {
        // Handle empty list serialization
        if (!empty($value)) {
            $items = $shape->get_member();
            foreach ($value as $k => $v) {
                $this->format($items, $v, $prefix . '.' . ($k + 1), $query);
            }
        }
    }
}