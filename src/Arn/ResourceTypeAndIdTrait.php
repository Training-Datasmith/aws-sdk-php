<?php
namespace Aws\Arn;

/**
 * @internal
 */
trait ResourceTypeAndIdTrait
{
    public function getResourceType()
    {
        return $this->data['resource_type'];
    }

    public function getResourceId()
    {
        return $this->data['resource_id'];
    }

    protected static function parseResourceTypeAndId(array $data): array
    {
        $resourceData = preg_split("/[\/:]/", (string) $data['resource'], 2);
        $data['resource_type'] = $resourceData[0] ?? null;
        $data['resource_id'] = $resourceData[1] ?? null;
        return $data;
    }
}