<?php

declare (strict_types=1);
namespace Aws\Auth;

use Aws\Api\Service;
use Aws\Auth\Exception\Unresolved_Auth_Scheme_Exception;
use Aws\Command_Interface;
use Closure;
use Guzzle_Http\Promise\Promise;
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
class Auth_Selection_Middleware
{
    /** @var callable */
    private $next_handler;
    /**
     * Create a middleware wrapper function
     *
     *
     */
    public static function wrap(Auth_Scheme_Resolver_Interface $auth_resolver, Service $api, ?array $configured_auth_schemes): Closure
    {
        return fn(callable $handler) => new self($handler, $auth_resolver, $api, $configured_auth_schemes);
    }
    public function __construct(callable $next_handler, private readonly Auth_Scheme_Resolver_Interface $auth_resolver, private readonly Service $api, private readonly ?array $configured_auth_schemes = null)
    {
        $this->next_handler = $next_handler;
    }
    /**
     * @return Promise
     */
    public function __invoke(Command_Interface $command)
    {
        $next_handler = $this->next_handler;
        $service_auth = $this->api->get_metadata('auth') ?: [];
        $operation = $this->api->get_operation($command->get_name());
        $operation_auth = $operation['auth'] ?? [];
        $unsigned_payload = $operation['unsignedpayload'] ?? false;
        $resolvable_auth = $operation_auth ?: $service_auth;
        if (!empty($resolvable_auth)) {
            if (isset($command['@context']['auth_scheme_resolver']) && $command['@context']['auth_scheme_resolver'] instanceof Auth_Scheme_Resolver_Interface) {
                $resolver = $command['@context']['auth_scheme_resolver'];
            } else {
                $resolver = $this->auth_resolver;
            }
            try {
                $auth_scheme_list = $this->build_auth_scheme_list($resolvable_auth, $command['@context']['auth_scheme_preference'] ?? null);
                $selected_auth_scheme = $resolver->select_auth_scheme($auth_scheme_list, ['unsigned_payload' => $unsigned_payload]);
                if (!empty($selected_auth_scheme)) {
                    $command['@context']['signature_version'] = $selected_auth_scheme;
                }
            } catch (Unresolved_Auth_Scheme_Exception) {
                // There was an error resolving auth
                // The signature version will fall back to the modeled `signatureVersion`
                // or auth schemes resolved during endpoint resolution
            }
        }
        return $next_handler($command);
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
    private function build_auth_scheme_list(array $resolvable_auth_scheme_list, ?array $command_configured_auth_schemes): array
    {
        $user_configured_auth_schemes = $command_configured_auth_schemes ?? $this->configured_auth_schemes;
        if (empty($user_configured_auth_schemes)) {
            return $resolvable_auth_scheme_list;
        }
        $prioritized_auth_schemes = array_intersect($user_configured_auth_schemes, $resolvable_auth_scheme_list);
        // Get remaining schemes not in user preferences
        $remaining_auth_schemes = array_diff($resolvable_auth_scheme_list, $prioritized_auth_schemes);
        return array_merge($prioritized_auth_schemes, $remaining_auth_schemes);
    }
}