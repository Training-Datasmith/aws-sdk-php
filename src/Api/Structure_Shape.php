<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Represents a structure shape and resolve member shape references.
 */
class Structure_Shape extends Shape
{
    /**
     * @var Shape[]
     */
    private ?array $members = null;
    public function __construct(array $definition, Shape_Map $shape_map)
    {
        $definition['type'] = 'structure';
        if (!isset($definition['members'])) {
            $definition['members'] = [];
        }
        parent::__construct($definition, $shape_map);
    }
    /**
     * Gets a list of all members
     *
     * @return Shape[]
     */
    public function get_members()
    {
        if (empty($this->members)) {
            $this->generate_members_hash();
        }
        return $this->members;
    }
    /**
     * Check if a specific member exists by name.
     *
     * @param string $name Name of the member to check
     */
    public function has_member($name): bool
    {
        return isset($this->definition['members'][$name]);
    }
    /**
     * Retrieve a member by name.
     *
     * @param string $name Name of the member to retrieve
     *
     * @return Shape
     * @throws \InvalidArgumentException if the member is not found.
     */
    public function get_member(string $name)
    {
        $members = $this->get_members();
        if (!isset($members[$name])) {
            throw new \InvalidArgumentException('Unknown member ' . $name);
        }
        return $members[$name];
    }
    /**
     * Used to look up the shape's original definition.
     * ShapeMap::resolve() merges properties from both
     * member and target shape definitions, causing certain
     * properties like `locationName` to be overwritten.
     *
     * @internal This method is for internal use only and should not be used
     * by external code. It may be changed or removed without notice.
     */
    public function get_shape_map(): Shape_Map
    {
        return $this->shape_map;
    }
    /**
     * Used to look up a shape's original definition.
     *
     *
     */
    public function get_original_definition(string $name): ?array
    {
        return $this->shape_map[$name] ?? null;
    }
    private function generate_members_hash(): void
    {
        $this->members = [];
        foreach ($this->definition['members'] as $name => $definition) {
            $this->members[$name] = $this->shape_for($definition);
        }
    }
}