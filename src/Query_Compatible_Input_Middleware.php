<?php
namespace Aws;

use Aws\Api\ListShape;
use Aws\Api\MapShape;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;
use Closure;

/**
 * Inspects command input values and casts them to their modeled type.
 * This covers query compatible services which have migrated from query
 * to JSON wire protocols.
 *
 * @internal
 */
class QueryCompatibleInputMiddleware
{
    /** @var callable */
    private $nextHandler;

    private ?\Aws\CommandInterface $command = null;

    /**
     * Create a middleware wrapper function.
     */
    public static function wrap(Service $service) : Closure
    {
        return static fn(callable $handler) => new self($handler, $service);
    }

    public function __construct(callable $nextHandler, private readonly Service $service)
    {
        $this->nextHandler = $nextHandler;
    }

    public function __invoke(CommandInterface $cmd)
    {
        $this->command = $cmd;
        $nextHandler = $this->nextHandler;
        $op = $this->service->getOperation($cmd->getName());
        $inputMembers = $op->getInput()->getMembers();
        $input = $cmd->toArray();

        foreach ($input as $param => $value) {
            if (isset($inputMembers[$param])) {
                $shape = $inputMembers[$param];
                $this->processInput($value, $shape, [$param]);
            }
        }

        return $nextHandler($this->command);
    }

    /**
     * Recurses a given input shape. if a given scalar input does not match its
     * modeled type, it is cast to its modeled type.
     *
     * @param $input
     * @param $shape
     *
     */
    private function processInput($input, \Aws\Api\StructureShape|\Aws\Api\ListShape|\Aws\Api\MapShape|\Aws\Api\Shape $shape, array $path) : void
    {
        match ($shape->getType()) {
            'structure' => $this->processStructure($input, $shape, $path),
            'list' => $this->processList($input, $shape, $path),
            'map' => $this->processMap($input, $shape, $path),
            default => $this->processScalar($input, $shape, $path),
        };
    }

    private function processStructure(
        array $input,
        StructureShape $shape,
        array $path
    ) : void
    {
        foreach ($input as $param => $value) {
            if ($shape->hasMember($param)) {
                $memberPath = array_merge($path, [$param]);
                $this->processInput($value, $shape->getMember($param), $memberPath);
            }
        }
    }

    private function processList(
        array $input,
        ListShape $shape,
        array $path
    ) : void
    {
        foreach ($input as $param => $value) {
            $memberPath = array_merge($path, [$param]);
            $this->processInput($value, $shape->getMember(), $memberPath);
        }
    }

    private function processMap(array $input, MapShape $shape, array $path) : void
    {
        foreach ($input as $param => $value) {
            $memberPath = array_merge($path, [$param]);
            $this->processInput($value, $shape->getValue(), $memberPath);
        }
    }

    /**
     * @param $input
     *
     */
    private function processScalar($input, Shape $shape, array $path) : void
    {
        $expectedType = $shape->getType();

        if (!$this->isModeledType($input, $expectedType)) {
            trigger_error(
                "The provided type for `". implode(' -> ', $path) ."` value was `"
                . (gettype($input) ===  'double' ? 'float' : gettype($input)) . "`."
                . " The modeled type is `{$expectedType}`.",
                E_USER_WARNING
            );
            $value = $this->castValue($input, $expectedType);
            $this->changeValueAtPath($path, $value);
        }
    }

    /**
     * Modifies command in place
     *
     * @param $newValue
     *
     */
    private function changeValueAtPath(array $path, $newValue) : void
    {
        $commandRef = &$this->command;

        foreach ($path as $segment) {
            if (!isset($commandRef[$segment])) {
                return;
            }
            $commandRef = &$commandRef[$segment];
        }
        $commandRef = $newValue;
    }

    /**
     * @param $value
     * @param $type
     */
    private function isModeledType($value, $type) : bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer', 'long' => is_int($value),
            'float' => is_float($value),
            default => true,
        };
    }

    /**
     * @param $value
     * @param $type
     *
     * @return float|int|mixed|string
     */
    private function castValue($value, $type)
    {
        return match ($type) {
            'integer' => (int) $value,
            'long' => $value + 0,
            'float' => (float) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
