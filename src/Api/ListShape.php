<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Represents a list shape.
 */
class List_Shape extends Shape
{
    private $member;
    public function __construct(array $definition, Shape_Map $shape_map)
    {
        $definition['type'] = 'list';
        parent::__construct($definition, $shape_map);
    }
    /**
     * @return Shape
     * @throws \RuntimeException if no member is specified
     */
    public function get_member()
    {
        if (!$this->member) {
            if (!isset($this->definition['member'])) {
                throw new \RuntimeException('No member attribute specified');
            }
            $this->member = Shape::create($this->definition['member'], $this->shape_map);
        }
        return $this->member;
    }
}