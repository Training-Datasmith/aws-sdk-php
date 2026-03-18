<?php
namespace Aws\Auth;

use Aws\Api\Service;
use Aws\Auth\Exception\UnresolvedAuthSchemeException;
use Aws\CommandInterface;
use Closure;
use GuzzleHttp\Promise\Promise;

/**
 * Handles auth scheme resolution. If a service models and auth scheme using
 * the `auth` trait and the operation or metadata levels, this middleware will
 * attempt to select the first compatible auth scheme it encounters and apply its
 * signature version to the command's `@context` property bag.
 *
 * IMPORTANT: this middleware must be added to the "build" step.
 *
 * @internal
 */
class AuthSelectionMiddleware
{
    /** @var callable */
    private $nextHandler;

    /**
     * Create a middleware wrapper function
     *
     *
     */
    public static function wrap(
        AuthSchemeResolverInterface $authResolver,
        Service $api,
        ?array $configuredAuthSchemes
    ): Closure
    {
        return fn(callable $handler) => new self($handler, $authResolver, $api, $configuredAuthSchemes);
    }

    public function __construct(
        callable $nextHandler,
        private readonly AuthSchemeResolverInterface $authResolver,
        private readonly Service $api,
        private readonly ?array $configuredAuthSchemes = null
    )
    {
        $this->nextHandler = $nextHandler;
    }

    /**
     * @return Promise
     */
    public function __invoke(CommandInterface $command)
    {
        $nextHandler = $this->nextHandler;
        $serviceAuth = $this->api->getMetadata('auth') ?: [];
        $operation = $this->api->getOperation($command->getName());
        $operationAuth = $operation['auth'] ?? [];
        $unsignedPayload = $operation['unsignedpayload'] ?? false;
        $resolvableAuth = $operationAuth ?: $serviceAuth;

        if (!empty($resolvableAuth)) {
            if (isset($command['@context']['auth_scheme_resolver'])
                && $command['@context']['auth_scheme_resolver'] instanceof AuthSchemeResolverInterface
            ){
                $resolver = $command['@context']['auth_scheme_resolver'];
            } else {
                $resolver = $this->authResolver;
            }

            try {
                $authSchemeList = $this->buildAuthSchemeList(
                    $resolvableAuth,
                    $command['@context']['auth_scheme_preference']
                        ?? null,
                );
                $selectedAuthScheme = $resolver->selectAuthScheme(
                    $authSchemeList,
                    ['unsigned_payload' => $unsignedPayload]
                );

                if (!empty($selectedAuthScheme)) {
                    $command['@context']['signature_version'] = $selectedAuthScheme;
                }
            } catch (UnresolvedAuthSchemeException) {
                // There was an error resolving auth
                // The signature version will fall back to the modeled `signatureVersion`
                // or auth schemes resolved during endpoint resolution
            }
        }

        return $nextHandler($command);
    }

    /**
     * Prioritizes auth schemes according to user preference order.
     * User-preferred schemes that are available will be placed first,
     * followed by remaining available schemes.
     *
     * @param array $resolvableAuthSchemeList Available auth schemes
     * @param array|null $commandConfiguredAuthSchemes Command-level preferences (overrides config)
     *
     * @return array Reordered auth schemes with user preferences first
     */
    private function buildAuthSchemeList(
        array $resolvableAuthSchemeList,
        ?array $commandConfiguredAuthSchemes,
    ): array
    {
        $userConfiguredAuthSchemes = $commandConfiguredAuthSchemes
            ?? $this->configuredAuthSchemes;

        if (empty($userConfiguredAuthSchemes)) {
            return $resolvableAuthSchemeList;
        }

        $prioritizedAuthSchemes = array_intersect(
            $userConfiguredAuthSchemes,
            $resolvableAuthSchemeList
        );

        // Get remaining schemes not in user preferences
        $remainingAuthSchemes = array_diff(
            $resolvableAuthSchemeList,
            $prioritizedAuthSchemes
        );

        return array_merge($prioritizedAuthSchemes, $remainingAuthSchemes);
    }
}
