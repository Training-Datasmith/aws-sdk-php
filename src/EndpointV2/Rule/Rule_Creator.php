<?php

namespace Aws\EndpointV2\Rule;

use Aws\Exception\UnresolvedEndpointException;

class RuleCreator
{
    public static function create($type, $definition): \Aws\EndpointV2\Rule\EndpointRule|\Aws\EndpointV2\Rule\ErrorRule|\Aws\EndpointV2\Rule\TreeRule
    {
        return match ($type) {
            'endpoint' => new EndpointRule($definition),
            'error' => new ErrorRule($definition),
            'tree' => new TreeRule($definition),
            default => throw new UnresolvedEndpointException(
                'Unknown rule type ' . $type .
                ' must be of type `endpoint`, `tree` or `error`'
            ),
        };
    }
}

