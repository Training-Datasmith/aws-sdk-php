<?php

namespace Aws\S3\S3Transfer\Progress;

/**
 * Defines a progress bar format.
 */
abstract class AbstractProgressBarFormat
{
    public function __construct(private array $args = [])
    {
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * To set multiple arguments at once.
     * It does not override all the values, instead
     * it adds the arguments individually and if a value
     * already exists then that value will be overridden.
     *
     *
     */
    public function setArgs(array $args): void
    {
        foreach ($args as $key => $value) {
            $this->args[$key] = $value;
        }
    }

    public function setArg(string $key, mixed $value): void
    {
        $this->args[$key] = $value;
    }

    public function format(): string
    {
        $parameters = $this->getFormatParameters();
        $defaultParameterValues = $this->getFormatDefaultParameterValues();
        foreach ($parameters as $param) {
            if (!array_key_exists($param, $this->args)) {
                $this->args[$param] = $defaultParameterValues[$param] ?? '';
            }
        }

        $replacements = [];
        foreach ($parameters as $param) {
            $replacements["|$param|"] = $this->args[$param] ?? '';
        }

        return strtr($this->getFormatTemplate(), $replacements);
    }

    abstract public function getFormatTemplate(): string;

    abstract public function getFormatParameters(): array;

    abstract protected function getFormatDefaultParameterValues(): array;
}
