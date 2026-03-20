<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Date_Time_Result;
use Aws\Api\Shape;
/**
 * @internal Implements standard JSON parsing.
 */
class Json_Parser
{
    public function parse(Shape $shape, $value)
    {
        if ($value === null) {
            return $value;
        }
        switch ($shape['type']) {
            case 'structure':
                if (isset($shape['document']) && $shape['document']) {
                    return $value;
                }
                $target = [];
                foreach ($shape->get_members() as $name => $member) {
                    $location_name = $member['locationName'] ?: $name;
                    if (isset($value[$location_name])) {
                        $target[$name] = $this->parse($member, $value[$location_name]);
                    }
                }
                if (isset($shape['union']) && $shape['union'] && is_array($value) && empty($target)) {
                    foreach ($value as $key => $val) {
                        $target['Unknown'][$key] = $val;
                    }
                }
                return $target;
            case 'list':
                $member = $shape->get_member();
                $target = [];
                foreach ($value as $v) {
                    $target[] = $this->parse($member, $v);
                }
                return $target;
            case 'map':
                $values = $shape->get_value();
                $target = [];
                foreach ($value as $k => $v) {
                    // null map values should not be deserialized
                    if (!is_null($v)) {
                        $target[$k] = $this->parse($values, $v);
                    }
                }
                return $target;
            case 'timestamp':
                return Date_Time_Result::from_timestamp($value, !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : null);
            case 'blob':
                return base64_decode($value);
            default:
                return $value;
        }
    }
}