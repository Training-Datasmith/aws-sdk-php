<?php

declare (strict_types=1);
namespace Aws\Arn;

/**
 * @internal
 */
trait Resource_Type_And_Id_Trait
{
    public function get_resource_type()
    {
        return $this->data['resource_type'];
    }
    public function get_resource_id()
    {
        return $this->data['resource_id'];
    }
    protected static function parse_resource_type_and_id(array $data): array
    {
        $resource_data = preg_split("/[\\/:]/", (string) $data['resource'], 2);
        $data['resource_type'] = $resource_data[0] ?? null;
        $data['resource_id'] = $resource_data[1] ?? null;
        return $data;
    }
}