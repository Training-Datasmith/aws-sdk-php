<?php
namespace Aws;

use Aws\Api\Service;

/**
 * Validates the required input parameters of commands are non empty
 *
 * @internal
 */
class InputValidationMiddleware
{
    /** @var callable */
    private $nextHandler;

    /**
     * Create a middleware wrapper function.
     *
     * @param array $mandatoryAttributeList
     * @return callable     */
    public static function wrap(Service $service, $mandatoryAttributeList) {
        if (!is_array($mandatoryAttributeList) ||
            array_filter($mandatoryAttributeList, is_string(...)) !== $mandatoryAttributeList
        ) {
            throw new \InvalidArgumentException(
                "The mandatory attribute list must be an array of strings"
            );
        }
        return fn(callable $handler) => new self($handler, $service, $mandatoryAttributeList);
    }

    /**
     * @param mixed[] $mandatoryAttributeList
     */
    public function __construct(
        callable $nextHandler,
        private readonly Service $service,
        private $mandatoryAttributeList
    ) {
        $this->nextHandler = $nextHandler;
    }

    public function __invoke(CommandInterface $cmd) {
        $nextHandler = $this->nextHandler;
        $op = $this->service->getOperation($cmd->getName())->toArray();
        if (!empty($op['input']['shape'])) {
            $service = $this->service->toArray();
            if (!empty($input = $service['shapes'][$op['input']['shape']])) {
                if (!empty($input['required'])) {
                    foreach ($input['required'] as $member) {
                        if (in_array($member, $this->mandatoryAttributeList)) {
                            $argument = is_string($cmd[$member]) ? trim($cmd[$member]) : $cmd[$member];
                            if ($argument === '' || $argument === null) {
                                $commandName = $cmd->getName();
                                throw new \InvalidArgumentException(
                                    "The {$commandName} operation requires non-empty parameter: {$member}"
                                );
                            }
                        }
                    }
                }
            }
        }
        return $nextHandler($cmd);
    }
}
