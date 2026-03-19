<?php

declare(strict_types=1);

namespace Aws\Api\Serializer;

use Aws\Api\ListShape;
use Aws\Api\Shape;

/**
 * @internal
 */
class Ec2ParamBuilder extends QueryParamBuilder
{
    protected function queryName(Shape $shape, $default = null)
    {
        return ($shape['queryName']
            ?: ucfirst((string) @$shape['locationName'] ?: ''))
                ?: $default;
    }

    protected function isFlat(Shape $shape): bool
    {
        return false;
    }

    protected function format_list(
        ListShape $shape,
        array $value,
        $prefix,
        &$query
    ) {
        // Handle empty list serialization
        if (!empty($value)) {
            $items = $shape->getMember();
            foreach ($value as $k => $v) {
                $this->format($items, $v, $prefix . '.' . ($k + 1), $query);
            }
        }
    }
}
