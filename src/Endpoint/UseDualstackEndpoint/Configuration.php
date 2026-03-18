<?php
namespace Aws\Endpoint\UseDualstackEndpoint;

use Aws;
use Aws\Endpoint\UseDualstackEndpoint\Exception\ConfigurationException;

class Configuration implements ConfigurationInterface
{
    private $useDualstackEndpoint;

    public function __construct($useDualstackEndpoint, $region)
    {
        $this->useDualstackEndpoint = Aws\boolean_value($useDualstackEndpoint);
        if (is_null($this->useDualstackEndpoint)) {
            throw new ConfigurationException("'use_dual_stack_endpoint' config option"
                . " must be a boolean value.");
        }
        if ($this->useDualstackEndpoint == true
            && (str_contains((string) $region, "iso-") || str_contains((string) $region, "-iso"))
        ) {
            throw new ConfigurationException("Dual-stack is not supported in ISO regions");        }
    }

    /**
     * {@inheritdoc}
     */
    public function isUseDualstackEndpoint()
    {
        return $this->useDualstackEndpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'use_dual_stack_endpoint' => $this->isUseDualstackEndpoint(),
        ];
    }
}
