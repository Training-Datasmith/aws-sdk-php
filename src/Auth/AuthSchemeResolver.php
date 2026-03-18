<?php

namespace Aws\Auth;

use Aws\Auth\Exception\UnresolvedAuthSchemeException;
use Aws\Exception\CredentialsException;
use Aws\Exception\TokenException;
use Aws\Identity\AwsCredentialIdentity;
use Aws\Identity\BearerTokenIdentity;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Houses logic for selecting an auth scheme modeled in a service's `auth` trait.
 * The `auth` trait can be modeled either in a service's metadata, or at the operation level.
 */
class AuthSchemeResolver implements AuthSchemeResolverInterface
{
    const UNSIGNED_BODY = '-unsigned-body';

    /**
     * @var string[] Default mapping of modeled auth trait auth schemes
     *               to the SDK's supported signature versions.
     */
    private static array $defaultAuthSchemeMap = [
        'aws.auth#sigv4' => 'v4',
        'aws.auth#sigv4a' => 'v4a',
        'smithy.api#httpBearerAuth' => 'bearer',
        'smithy.api#noAuth' => 'anonymous'
    ];

    /**
     * @var array Mapping of auth schemes to signature versions used in
     *            resolving a signature version.
     */
    private $authSchemeMap;
    private $tokenProvider;
    private $credentialProvider;


    public function __construct(
        callable $credentialProvider,
        ?callable $tokenProvider = null,
        array $authSchemeMap = []
    ){
        $this->credentialProvider = $credentialProvider;
        $this->tokenProvider = $tokenProvider;
        $this->authSchemeMap = empty($authSchemeMap)
            ? self::$defaultAuthSchemeMap
            : $authSchemeMap;
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
    public function selectAuthScheme(
        array $authSchemes,
        array $args = []
    ): string
    {
        $failureReasons = [];

        foreach($authSchemes as $authScheme) {
            $normalizedAuthScheme = $this->authSchemeMap[$authScheme] ?? $authScheme;

            if ($this->isCompatibleAuthScheme($normalizedAuthScheme)) {
                if ($normalizedAuthScheme === 'v4' && !empty($args['unsigned_payload'])) {
                    return $normalizedAuthScheme . self::UNSIGNED_BODY;
                }

                return $normalizedAuthScheme;
            }
            $failureReasons[] = $this->getIncompatibilityMessage($normalizedAuthScheme);
        }

        throw new UnresolvedAuthSchemeException(
            'Could not resolve an authentication scheme: '
            . implode('; ', $failureReasons)
        );
    }

    /**
     * Determines compatibility based on either Identity or the availability
     * of the CRT extension.
     *
     * @param $authScheme
     */
    private function isCompatibleAuthScheme($authScheme): bool
    {
        return match ($authScheme) {
            'v4', 'anonymous' => $this->hasAwsCredentialIdentity(),
            'v4a' => extension_loaded('awscrt') && $this->hasAwsCredentialIdentity(),
            'bearer' => $this->hasBearerTokenIdentity(),
            default => false,
        };
    }

    /**
     * Provides incompatibility messages in the event an incompatible auth scheme
     * is encountered.
     *
     * @param $authScheme
     */
    private function getIncompatibilityMessage($authScheme): string
    {
        return match ($authScheme) {
            'v4' => 'Signature V4 requires AWS credentials for request signing',
            'anonymous' => 'Anonymous signatures require AWS credentials for request signing',
            'v4a' => 'The aws-crt-php extension and AWS credentials are required to use Signature V4A',
            'bearer' => 'Bearer token credentials must be provided to use Bearer authentication',
            default => "The service does not support `{$authScheme}` authentication.",
        };
    }

    private function hasAwsCredentialIdentity(): bool
    {
        $fn = $this->credentialProvider;
        $result = $fn();

        if ($result instanceof PromiseInterface) {
            try {
                $resolved = $result->wait();
                return $resolved instanceof AwsCredentialIdentity;
            } catch (CredentialsException) {
                return false;
            }
        }

        return $result instanceof AwsCredentialIdentity;
    }

    private function hasBearerTokenIdentity(): bool
    {
        if ($this->tokenProvider) {
            $fn = $this->tokenProvider;
            $result = $fn();

            if ($result instanceof PromiseInterface) {
                try {
                    $resolved = $result->wait();
                    return $resolved instanceof BearerTokenIdentity;
                } catch (TokenException) {
                    return false;
                }
            }

            return $result instanceof BearerTokenIdentity;
        }

        return false;
    }
}
