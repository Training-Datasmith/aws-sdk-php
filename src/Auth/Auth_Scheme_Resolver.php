<?php

declare (strict_types=1);
namespace Aws\Auth;

use Aws\Auth\Exception\Unresolved_Auth_Scheme_Exception;
use Aws\Exception\Credentials_Exception;
use Aws\Exception\Token_Exception;
use Aws\Identity\Aws_Credential_Identity;
use Aws\Identity\Bearer_Token_Identity;
use Guzzle_Http\Promise\Promise_Interface;
/**
 * Houses logic for selecting an auth scheme modeled in a service's `auth` trait.
 * The `auth` trait can be modeled either in a service's metadata, or at the operation level.
 */
class Auth_Scheme_Resolver implements Auth_Scheme_Resolver_Interface
{
    public const UNSIGNED_BODY = '-unsigned-body';
    /**
     * @var string[] Default mapping of modeled auth trait auth schemes
     *               to the SDK's supported signature versions.
     */
    private static array $default_auth_scheme_map = ['aws.auth#sigv4' => 'v4', 'aws.auth#sigv4a' => 'v4a', 'smithy.api#httpBearerAuth' => 'bearer', 'smithy.api#noAuth' => 'anonymous'];
    /**
     * @var array Mapping of auth schemes to signature versions used in
     *            resolving a signature version.
     */
    private $auth_scheme_map;
    private $token_provider;
    private $credential_provider;
    public function __construct(callable $credential_provider, ?callable $token_provider = null, array $auth_scheme_map = [])
    {
        $this->credential_provider = $credential_provider;
        $this->token_provider = $token_provider;
        $this->auth_scheme_map = empty($auth_scheme_map) ? self::$default_auth_scheme_map : $auth_scheme_map;
    }
    /**
     * Accepts a priority-ordered list of auth schemes and an Identity
     * and selects the first compatible auth schemes, returning a normalized
     * signature version.  For example, based on the default auth scheme mapping,
     * if `aws.auth#sigv4` is selected, `v4` will be returned.
     *
     * @param $identity
     * @throws UnresolvedAuthSchemeException
     */
    public function select_auth_scheme(array $auth_schemes, array $args = []): string
    {
        $failure_reasons = [];
        foreach ($auth_schemes as $auth_scheme) {
            $normalized_auth_scheme = $this->auth_scheme_map[$auth_scheme] ?? $auth_scheme;
            if ($this->is_compatible_auth_scheme($normalized_auth_scheme)) {
                if ($normalized_auth_scheme === 'v4' && !empty($args['unsigned_payload'])) {
                    return $normalized_auth_scheme . self::UNSIGNED_BODY;
                }
                return $normalized_auth_scheme;
            }
            $failure_reasons[] = $this->get_incompatibility_message($normalized_auth_scheme);
        }
        throw new Unresolved_Auth_Scheme_Exception('Could not resolve an authentication scheme: ' . implode('; ', $failure_reasons));
    }
    /**
     * Determines compatibility based on either Identity or the availability
     * of the CRT extension.
     *
     * @param $authScheme
     */
    private function is_compatible_auth_scheme($auth_scheme): bool
    {
        return match ($auth_scheme) {
            'v4', 'anonymous' => $this->has_aws_credential_identity(),
            'v4a' => extension_loaded('awscrt') && $this->has_aws_credential_identity(),
            'bearer' => $this->has_bearer_token_identity(),
            default => false,
        };
    }
    /**
     * Provides incompatibility messages in the event an incompatible auth scheme
     * is encountered.
     *
     * @param $authScheme
     */
    private function get_incompatibility_message($auth_scheme): string
    {
        return match ($auth_scheme) {
            'v4' => 'Signature V4 requires AWS credentials for request signing',
            'anonymous' => 'Anonymous signatures require AWS credentials for request signing',
            'v4a' => 'The aws-crt-php extension and AWS credentials are required to use Signature V4A',
            'bearer' => 'Bearer token credentials must be provided to use Bearer authentication',
            default => "The service does not support `{$auth_scheme}` authentication.",
        };
    }
    private function has_aws_credential_identity(): bool
    {
        $fn = $this->credential_provider;
        $result = $fn();
        if ($result instanceof Promise_Interface) {
            try {
                $resolved = $result->wait();
                return $resolved instanceof Aws_Credential_Identity;
            } catch (Credentials_Exception) {
                return false;
            }
        }
        return $result instanceof Aws_Credential_Identity;
    }
    private function has_bearer_token_identity(): bool
    {
        if ($this->token_provider) {
            $fn = $this->token_provider;
            $result = $fn();
            if ($result instanceof Promise_Interface) {
                try {
                    $resolved = $result->wait();
                    return $resolved instanceof Bearer_Token_Identity;
                } catch (Token_Exception) {
                    return false;
                }
            }
            return $result instanceof Bearer_Token_Identity;
        }
        return false;
    }
}