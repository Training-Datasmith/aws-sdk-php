<?php

declare (strict_types=1);
namespace Aws\Auth;

/**
 * An AuthSchemeResolver object determines which auth scheme will be used for request signing.
 */
interface Auth_Scheme_Resolver_Interface
{
    /**
     * Selects an auth scheme for request signing.
     *
     * @param array $authSchemes a priority-ordered list of authentication schemes.
     *
     */
    public function select_auth_scheme(array $auth_schemes, array $args): ?string;
}