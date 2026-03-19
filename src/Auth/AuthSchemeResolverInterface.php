<?php

declare(strict_types=1);

namespace Aws\Auth;

/**
 * An AuthSchemeResolver object determines which auth scheme will be used for request signing.
 */
interface AuthSchemeResolverInterface
{
    /**
     * Selects an auth scheme for request signing.
     *
     * @param array $authSchemes a priority-ordered list of authentication schemes.
     *
     */
    public function selectAuthScheme(
        array $authSchemes,
        array $args
    ): ?string;
}
