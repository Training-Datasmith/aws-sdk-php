<?php

declare (strict_types=1);
namespace Aws\Api;

use Aws;
use stdClass;
/**
 * Validates a schema against a hash of input.
 */
class Validator
{
    private $path = [];
    private array $errors = [];
    private $constraints = [];
    private static array $default_constraints = ['required' => true, 'min' => true, 'max' => false, 'pattern' => false];
    /**
     * @param array $constraints Associative array of constraints to enforce.
     *                           Accepts the following keys: "required", "min",
     *                           "max", and "pattern". If a key is not
     *                           provided, the constraint will assume false.
     */
    public function __construct(?array $constraints = null)
    {
        static $assumed_false_values = ['required' => false, 'min' => false, 'max' => false, 'pattern' => false];
        $this->constraints = empty($constraints) ? self::$default_constraints : $constraints + $assumed_false_values;
    }
    /**
     * Validates the given input against the schema.
     *
     * @param string $name  Operation name
     * @param Shape  $shape Shape to validate
     * @param array  $input Input to validate
     *
     * @throws \InvalidArgumentException if the input is invalid.
     */
    public function validate($name, Shape $shape, array $input): void
    {
        $this->dispatch($shape, $input);
        if ($this->errors) {
            $message = sprintf('Found %d error%s while validating the input provided for the ' . "%s operation:\n%s", count($this->errors), count($this->errors) > 1 ? 's' : '', $name, implode("\n", $this->errors));
            $this->errors = [];
            throw new \InvalidArgumentException($message);
        }
    }
    private function dispatch(Shape $shape, $value): void
    {
        static $methods = ['structure' => 'check_structure', 'list' => 'check_list', 'map' => 'check_map', 'blob' => 'check_blob', 'boolean' => 'check_boolean', 'integer' => 'check_numeric', 'float' => 'check_numeric', 'long' => 'check_numeric', 'string' => 'check_string', 'byte' => 'check_string', 'char' => 'check_string'];
        $type = $shape->get_type();
        if (isset($methods[$type])) {
            $this->{$methods[$type]}($shape, $value);
        }
    }
    private function check_structure(Structure_Shape $shape, array $value): void
    {
        $is_document = isset($shape['document']) && $shape['document'];
        $is_union = isset($shape['union']) && $shape['union'];
        if ($is_document) {
            if (!$this->check_document_type($value)) {
                $this->add_error('is not a valid document type');
                return;
            }
        } elseif ($is_union) {
            if (!$this->check_union($value)) {
                $this->add_error('is a union type and must have exactly one non null value');
                return;
            }
        } elseif (!$this->check_associative_array($value)) {
            return;
        }
        if ($this->constraints['required'] && $shape['required']) {
            foreach ($shape['required'] as $req) {
                if (!isset($value[$req])) {
                    $this->path[] = $req;
                    $this->add_error('is missing and is a required parameter');
                    array_pop($this->path);
                }
            }
        }
        if (!$is_document) {
            foreach ($value as $name => $v) {
                if ($shape->has_member($name)) {
                    $this->path[] = $name;
                    $this->dispatch($shape->get_member($name), $value[$name] ?? null);
                    array_pop($this->path);
                }
            }
        }
    }
    private function check_list(List_Shape $shape, $value): void
    {
        if (!is_array($value)) {
            $this->add_error('must be an array. Found ' . Aws\describe_type($value));
            return;
        }
        $this->validate_range($shape, count($value), 'list element count');
        $items = $shape->get_member();
        foreach ($value as $index => $v) {
            $this->path[] = $index;
            $this->dispatch($items, $v);
            array_pop($this->path);
        }
    }
    private function check_map(Map_Shape $shape, $value): void
    {
        if (!$this->check_associative_array($value)) {
            return;
        }
        $values = $shape->get_value();
        foreach ($value as $key => $v) {
            $this->path[] = $key;
            $this->dispatch($values, $v);
            array_pop($this->path);
        }
    }
    private function check_blob($value): void
    {
        static $valid = ['string' => true, 'integer' => true, 'double' => true, 'resource' => true];
        $type = gettype($value);
        if (!isset($valid[$type])) {
            if ($type != 'object' || !method_exists($value, '__toString')) {
                $this->add_error('must be an fopen resource, a ' . 'GuzzleHttp\Stream\StreamInterface object, or something ' . 'that can be cast to a string. Found ' . Aws\describe_type($value));
            }
        }
    }
    private function check_numeric(Shape $shape, $value): void
    {
        if (!is_numeric($value)) {
            $this->add_error('must be numeric. Found ' . Aws\describe_type($value));
            return;
        }
        $this->validate_range($shape, $value, 'numeric value');
    }
    private function check_boolean($value): void
    {
        if (!is_bool($value)) {
            $this->add_error('must be a boolean. Found ' . Aws\describe_type($value));
        }
    }
    private function check_string(Shape $shape, $value): void
    {
        if ($shape['jsonvalue']) {
            if (!self::can_json_encode($value)) {
                $this->add_error('must be a value encodable with \'json_encode\'.' . ' Found ' . Aws\describe_type($value));
            }
            return;
        }
        if (!$this->check_can_string($value)) {
            $this->add_error('must be a string or an object that implements ' . '__toString(). Found ' . Aws\describe_type($value));
            return;
        }
        $value ??= '';
        $this->validate_range($shape, strlen($value), 'string length');
        if ($this->constraints['pattern']) {
            $pattern = $shape['pattern'];
            if ($pattern && !preg_match("/{$pattern}/", $value)) {
                $this->add_error("Pattern /{$pattern}/ failed to match '{$value}'");
            }
        }
    }
    private function validate_range(Shape $shape, $length, string $descriptor): void
    {
        if ($this->constraints['min']) {
            $min = $shape['min'];
            if ($min && $length < $min) {
                $this->add_error("expected {$descriptor} to be >= {$min}, but " . "found {$descriptor} of {$length}");
            }
        }
        if ($this->constraints['max']) {
            $max = $shape['max'];
            if ($max && $length > $max) {
                $this->add_error("expected {$descriptor} to be <= {$max}, but " . "found {$descriptor} of {$length}");
            }
        }
    }
    private function check_array(array $arr): bool
    {
        return array_is_list($arr) || Aws\is_associative($arr);
    }
    private function check_can_string($value): bool
    {
        static $valid = ['string' => true, 'integer' => true, 'double' => true, 'NULL' => true];
        $type = gettype($value);
        return isset($valid[$type]) || $type == 'object' && method_exists($value, '__toString');
    }
    private function check_associative_array($value): bool
    {
        $is_associative = false;
        if (is_array($value)) {
            $expected_index = 0;
            $key = key($value);
            do {
                $is_associative = $key !== $expected_index++;
                next($value);
                $key = key($value);
            } while (!$is_associative && null !== $key);
        }
        if (!$is_associative) {
            $this->add_error('must be an associative array. Found ' . Aws\describe_type($value));
            return false;
        }
        return true;
    }
    private function check_document_type($value)
    {
        // To allow objects like value, which
        // can be used within a member which type is `Document`
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            $type_of_first_key = gettype(key($value));
            foreach ($value as $key => $val) {
                if (!$this->check_document_type($val) || gettype($key) != $type_of_first_key) {
                    return false;
                }
            }
            return $this->check_array($value);
        }
        return is_null($value) || is_numeric($value) || is_string($value) || is_bool($value);
    }
    private function check_union(array $value): bool
    {
        if (is_array($value)) {
            $non_null_count = 0;
            foreach ($value as $key => $val) {
                if (!is_null($val) && !str_starts_with((string) $key, '@')) {
                    $non_null_count++;
                }
            }
            return $non_null_count == 1;
        }
        return !is_null($value);
    }
    private function add_error(string $message): void
    {
        $this->errors[] = implode('', array_map(fn($s) => "[{$s}]", $this->path)) . ' ' . $message;
    }
    private function can_json_encode($data): bool
    {
        return !is_resource($data);
    }
}