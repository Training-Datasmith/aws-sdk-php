<?php

declare (strict_types=1);
namespace Aws\Api;

/**
 * Represents an API operation.
 */
class Operation extends Abstract_Model
{
    private $input;
    private $output;
    private $errors;
    private $static_context_params = [];
    private $context_params;
    private $operation_context_params = [];
    public function __construct(array $definition, Shape_Map $shape_map)
    {
        $definition['type'] = 'structure';
        if (!isset($definition['http']['method'])) {
            $definition['http']['method'] = 'POST';
        }
        if (!isset($definition['http']['requestUri'])) {
            $definition['http']['requestUri'] = '/';
        }
        if (isset($definition['staticContextParams'])) {
            $this->static_context_params = $definition['staticContextParams'];
        }
        if (isset($definition['operationContextParams'])) {
            $this->operation_context_params = $definition['operationContextParams'];
        }
        parent::__construct($definition, $shape_map);
        $this->context_params = $this->set_context_params();
    }
    /**
     * Returns an associative array of the HTTP attribute of the operation:
     *
     * - method: HTTP method of the operation
     * - requestUri: URI of the request (can include URI template placeholders)
     *
     * @return array
     */
    public function get_http()
    {
        return $this->definition['http'];
    }
    /**
     * Get the input shape of the operation.
     *
     * @return StructureShape
     */
    public function get_input()
    {
        if (!$this->input) {
            if ($input = $this['input']) {
                $this->input = $this->shape_for($input);
            } else {
                $this->input = new Structure_Shape([], $this->shape_map);
            }
        }
        return $this->input;
    }
    /**
     * Get the output shape of the operation.
     *
     * @return StructureShape
     */
    public function get_output()
    {
        if (!$this->output) {
            if ($output = $this['output']) {
                $this->output = $this->shape_for($output);
            } else {
                $this->output = new Structure_Shape([], $this->shape_map);
            }
        }
        return $this->output;
    }
    /**
     * Get an array of operation error shapes.
     *
     * @return StructureShape[]
     */
    public function get_errors()
    {
        if ($this->errors === null) {
            if ($errors = $this['errors']) {
                foreach ($errors as $key => $error) {
                    $errors[$key] = $this->shape_for($error);
                }
                $this->errors = $errors;
            } else {
                $this->errors = [];
            }
        }
        return $this->errors;
    }
    /**
     * Gets static modeled static values used for
     * endpoint resolution.
     *
     * @return array
     */
    public function get_static_context_params()
    {
        return $this->static_context_params;
    }
    /**
     * Gets definition of modeled dynamic values used
     * for endpoint resolution
     *
     * @return array
     */
    public function get_context_params()
    {
        return $this->context_params;
    }
    /**
     * Gets definition of modeled dynamic values used
     * for endpoint resolution
     */
    public function get_operation_context_params(): array
    {
        return $this->operation_context_params;
    }
    /**
     * @return array{shape: mixed, type: mixed}[]
     */
    private function set_context_params(): array
    {
        $members = $this->get_input()->get_members();
        $context_params = [];
        foreach ($members as $name => $shape) {
            if (!empty($context_param = $shape->get_context_param())) {
                $context_params[$context_param['name']] = ['shape' => $name, 'type' => $shape->get_type()];
            }
        }
        return $context_params;
    }
}