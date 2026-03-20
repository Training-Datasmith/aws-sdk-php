<?php

declare (strict_types=1);
namespace Aws\Credentials;

use Aws\Configuration\Configuration_Resolver;
use Aws\Exception\Credentials_Exception;
use Aws\Signin\Exception\Signin_Exception;
use Aws\Signin\Signin_Client;
use Guzzle_Http\Promise;
/**
 * Credential provider for login using console credentials
 */
final class Login_Credential_Provider
{
    private const EXT_OPENSSL = 'openssl';
    private const DEFAULT_REFRESH_THRESHOLD = 180;
    // 3 minutes
    private const ENV_CACHE_DIRECTORY = 'AWS_LOGIN_CACHE_DIRECTORY';
    private const DEFAULT_CACHE_DIRECTORY = '/.aws/login/cache';
    private const REAUTHENTICATE_MSG = ' Please reauthenticate using `aws login`.';
    private const PROFILE_DEFAULT = 'default';
    private const PROFILE_SECONDARY = 'profile ';
    private const GRANT_TYPE = 'refresh_token';
    private const REQUEST_KEY_GRANT_TYPE = 'grantType';
    private const REQUEST_KEY_CLIENT_ID = 'clientId';
    private const REQUEST_KEY_REFRESH_TOKEN = 'refreshToken';
    private const REQUEST_KEY_TOKEN_INPUT = 'tokenInput';
    private const RESULT_TOKEN_OUTPUT = 'tokenOutput';
    private const RESULT_EXPIRES_IN = 'expiresIn';
    private const CLIENT_SIGNATURE_DPOP = 'dpop';
    private const KEY_CLIENT_SIGNATURE_VERSION = 'signature_version';
    private const KEY_CLIENT_REGION = 'region';
    private const KEY_CLIENT_CREDENTIALS = 'credentials';
    private const KEY_PROFILE_LOGIN_SESSION = 'login_session';
    private const KEY_ACCESS_TOKEN = 'accessToken';
    private const KEY_DPOP_KEY = 'dpopKey';
    private const KEY_ACCESS_KEY_ID = 'accessKeyId';
    private const KEY_SECRET_ACCESS_KEY = 'secretAccessKey';
    private const KEY_SESSION_TOKEN = 'sessionToken';
    private const KEY_ACCOUNT_ID = 'accountId';
    private const KEY_EXPIRES_AT = 'expiresAt';
    private const REQUIRED_CACHE_KEYS = [self::KEY_ACCESS_TOKEN, self::REQUEST_KEY_CLIENT_ID, self::REQUEST_KEY_REFRESH_TOKEN, self::KEY_DPOP_KEY];
    private const REQUIRED_ACCESS_TOKEN_KEYS = [self::KEY_ACCESS_KEY_ID, self::KEY_SECRET_ACCESS_KEY, self::KEY_SESSION_TOKEN, self::KEY_ACCOUNT_ID, self::KEY_EXPIRES_AT];
    /** @var SigninClient The Signin service client used for token refresh operations */
    private readonly Signin_Client $client;
    /** @var string The file path to the cached token location */
    private readonly string $token_location;
    /** @var array|null The cached token data including access token, refresh token, and DPoP key */
    private ?array $token = null;
    /**
     * @param string $profileName Profile containing your console login session information
     * @param string|null $region Region used for refresh requests. If not provided,
     *                            attempts will be made to resolve a region using
     *                            `AWS_REGION`, then the profile specified for `login`.
     */
    public function __construct(private readonly string $profile_name, ?string $region = null)
    {
        if (!extension_loaded(self::EXT_OPENSSL)) {
            throw new \RuntimeException('The `openssl` extension is required to use Login credentials. ' . 'Please install or enable the `openssl` extension.');
        }
        $this->client = $this->create_signin_client($this->profile_name, $region);
        $this->token_location = $this->resolve_token_location($this->profile_name);
    }
    /**
     * Returns a promise that resolves to AWS credentials
     *
     * This method loads the cached token, refreshes it if necessary,
     * and returns AWS credentials sourced from the access token.
     *
     * @return Promise\PromiseInterface A promise that resolves to a Credentials object
     * @throws CredentialsException If re-authentication is required or credentials cannot be loaded
     * @throws SigninException If the token refresh fails with a SigninException
     */
    public function __invoke(): Promise\Promise_Interface
    {
        return Promise\Coroutine::of(function () {
            $this->token ??= $this->load_token();
            $credentials = $this->token[self::KEY_ACCESS_TOKEN];
            if ($this->should_refresh($credentials)) {
                try {
                    $credentials = yield from $this->refresh($credentials);
                } catch (Credentials_Exception $e) {
                    // For specific re-authentication errors, re-throw
                    throw $e;
                } catch (Signin_Exception $e) {
                    // For SigninException not handled by refresh(), re-throw
                    throw new Credentials_Exception('Unable to refresh login credentials: ' . $e->get_aws_error_message(), $e->get_code(), $e);
                } catch (\Exception $e) {
                    // For other refresh failures, log and continue with existing token
                    trigger_error('Continuing with existing token after refresh failure: ' . $e->get_message(), E_USER_NOTICE);
                }
            }
            yield $credentials;
        });
    }
    /**
     * Refreshes the access token using the refresh token and returns new credentials,
     * or, if refreshed from another source, returns the externally refreshed credentials.
     *
     * @param Credentials $currentCredentials The current credentials to refresh
     *
     * @return \Generator Generator that yields and returns refreshed credentials
     * @throws CredentialsException If re-authentication is required due to expired or changed credentials
     * @throws SigninException If the token refresh fails with a SigninException
     * @throws \Exception For unexpected errors during refresh
     */
    private function refresh(Credentials $current_credentials): \Generator
    {
        // Check for external refresh
        if ($refreshed_token = $this->get_external_refresh($current_credentials)) {
            $this->token = $refreshed_token;
            return $refreshed_token[self::KEY_ACCESS_TOKEN];
        }
        try {
            $refreshed = (yield $this->client->create_o_auth2token_async([self::REQUEST_KEY_TOKEN_INPUT => [self::REQUEST_KEY_CLIENT_ID => $this->token[self::REQUEST_KEY_CLIENT_ID], self::REQUEST_KEY_GRANT_TYPE => self::GRANT_TYPE, self::REQUEST_KEY_REFRESH_TOKEN => $this->token[self::REQUEST_KEY_REFRESH_TOKEN]], self::KEY_DPOP_KEY => $this->token[self::KEY_DPOP_KEY]]))->get(self::RESULT_TOKEN_OUTPUT);
            $new_credentials = self::create_credentials($refreshed[self::KEY_ACCESS_TOKEN], time() + $refreshed[self::RESULT_EXPIRES_IN], $current_credentials->get_account_id());
            $this->token[self::KEY_ACCESS_TOKEN] = $new_credentials;
            $this->token[self::REQUEST_KEY_REFRESH_TOKEN] = $refreshed[self::REQUEST_KEY_REFRESH_TOKEN];
        } catch (\Exception $e) {
            throw $this->handle_refresh_exception($e);
        }
        try {
            $this->write_to_cache();
        } catch (\Json_Exception|\RuntimeException $e) {
            trigger_error('Failed to update credential cache during refresh: ' . $e->get_message() . '. Using refreshed credentials in memory.', E_USER_NOTICE);
        }
        return $new_credentials;
    }
    /**
     * Gets externally refreshed token if token has been refreshed from another source.
     * If the new token does not need refreshing, returns it.
     *
     * @param Credentials $currentCredentials Current credentials to compare against
     * @return array|null Returns the updated token array if externally refreshed, null otherwise
     */
    private function get_external_refresh(Credentials $current_credentials): ?array
    {
        try {
            $latest_token = $this->load_token();
            $latest_credentials = $latest_token[self::KEY_ACCESS_TOKEN];
            // Refresh token must be different
            if ($latest_token[self::REQUEST_KEY_REFRESH_TOKEN] === $this->token[self::REQUEST_KEY_REFRESH_TOKEN]) {
                return null;
            }
            // Expiration must be newer
            if ($latest_credentials->get_expiration() <= $current_credentials->get_expiration()) {
                return null;
            }
            // New token should not need refresh itself
            if ($this->should_refresh($latest_credentials)) {
                return null;
            }
            return $latest_token;
        } catch (\Exception) {
            return null;
        }
    }
    /**
     * Writes the updated access token and refresh token to the cache file
     *
     * @throws \JsonException|\RuntimeException
     */
    private function write_to_cache(): void
    {
        $credentials = $this->token[self::KEY_ACCESS_TOKEN];
        $updates = [self::KEY_ACCESS_TOKEN => [self::KEY_ACCESS_KEY_ID => $credentials->get_access_key_id(), self::KEY_SECRET_ACCESS_KEY => $credentials->get_secret_key(), self::KEY_SESSION_TOKEN => $credentials->get_security_token(), self::KEY_ACCOUNT_ID => $credentials->get_account_id(), self::KEY_EXPIRES_AT => gmdate('Y-m-d\TH:i:s\Z', $credentials->get_expiration())], self::REQUEST_KEY_REFRESH_TOKEN => $this->token[self::REQUEST_KEY_REFRESH_TOKEN]];
        $existing = json_decode(file_get_contents($this->token_location), true, 512, JSON_THROW_ON_ERROR);
        $merged = array_merge($existing, $updates);
        $result = file_put_contents($this->token_location, json_encode($merged, JSON_THROW_ON_ERROR), LOCK_EX);
        if (!$result) {
            throw new \RuntimeException('Failed to write cache file');
        }
    }
    /**
     * Loads and validates the token from the cache file
     *
     * @return array The loaded token data with Credentials object, DPoP key, and other fields
     * @throws CredentialsException If the cache file is invalid, missing required keys,
     *                              or DPoP key cannot be loaded
     */
    private function load_token(): array
    {
        try {
            $cached = json_decode(file_get_contents($this->token_location), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Json_Exception $e) {
            throw new Credentials_Exception('Invalid JSON in cache file: ' . $e->get_message(), 0, $e);
        }
        if (self::has_all_required_keys($cached, self::REQUIRED_CACHE_KEYS) && self::has_all_required_keys($cached[self::KEY_ACCESS_TOKEN] ?? [], self::REQUIRED_ACCESS_TOKEN_KEYS)) {
            // Convert expiresAt to Unix timestamp
            $expires_at = strtotime((string) $cached[self::KEY_ACCESS_TOKEN][self::KEY_EXPIRES_AT]);
            if ($expires_at === false) {
                throw new Credentials_Exception('Invalid expiration date format in cached token `' . self::KEY_EXPIRES_AT . '` field.' . self::REAUTHENTICATE_MSG);
            }
            $cached[self::KEY_ACCESS_TOKEN] = self::create_credentials($cached[self::KEY_ACCESS_TOKEN], $expires_at, $cached[self::KEY_ACCESS_TOKEN][self::KEY_ACCOUNT_ID]);
            // Load DPoP key
            $cached[self::KEY_DPOP_KEY] = openssl_pkey_get_private($cached[self::KEY_DPOP_KEY]);
            if ($cached[self::KEY_DPOP_KEY] === false) {
                $error = openssl_error_string();
                throw new Credentials_Exception('Failed to load DPoP private key from cached token for profile ' . "{$this->profile_name}: " . ($error ? ": {$error}" : '.') . self::REAUTHENTICATE_MSG);
            }
            return $cached;
        }
        throw new Credentials_Exception('Missing required keys in cached token for profile ' . $this->profile_name . '.' . self::REAUTHENTICATE_MSG);
    }
    /**
     * @param Credentials $credentials The credentials to check for expiration
     *
     * @return bool True if the token expires within the refresh threshold
     */
    private function should_refresh(Credentials $credentials): bool
    {
        return $credentials->get_expiration() - time() <= self::DEFAULT_REFRESH_THRESHOLD;
    }
    /**
     * Handles exceptions thrown during token refresh
     *
     * @param \Exception $e The exception thrown during refresh
     *
     * @return \Exception The exception to be thrown (either transformed or original)
     */
    private function handle_refresh_exception(\Throwable $e): \Exception
    {
        if ($e instanceof Signin_Exception) {
            trigger_error('Failed to refresh login credentials: ' . $e->get_aws_error_message(), E_USER_NOTICE);
            if ($e->get_aws_error_code() === 'AccessDeniedException') {
                $error = strtolower((string) $e->get('error'));
                switch ($error) {
                    case 'token_expired':
                        return new Credentials_Exception('Your session has expired.' . self::REAUTHENTICATE_MSG, 0, $e);
                    case 'user_credentials_changed':
                        return new Credentials_Exception('Unable to refresh credentials because of a change in your password. ' . 'Please reauthenticate with your new password.', 0, $e);
                    case 'insufficient_permissions':
                        return new Credentials_Exception('Unable to refresh credentials due to insufficient permissions. ' . 'You may be missing permission for the `CreateOAuth2Token` action.', 0, $e);
                }
            }
            return $e;
        }
        // For all other exceptions
        trigger_error('Unexpected error refreshing login credentials: ' . $e->get_message(), E_USER_NOTICE);
        return $e;
    }
    /**
     * Resolves the cache file location for the given profile
     *
     * @param string $profileName The profile name to resolve the token location for
     *
     * @return string The full path to the cache file
     * @throws CredentialsException If the profile doesn't exist, lacks login_session,
     *                              or cache file is not readable
     */
    private function resolve_token_location(string $profile_name): string
    {
        $config_file = Credential_Provider::get_config_file_name();
        if (!is_readable($config_file)) {
            throw new Credentials_Exception('Unable to load configuration file at ' . $config_file . '. Please ensure the file exists at the specified location.');
        }
        // Ensure profile and session are set
        $profiles = Credential_Provider::load_profiles($config_file);
        if ($profile_name === self::PROFILE_DEFAULT) {
            $profile_data = $profiles[self::PROFILE_DEFAULT] ?? null;
        } elseif (str_starts_with($profile_name, self::PROFILE_SECONDARY)) {
            $profile_data = $profiles[$profile_name] ?? null;
        } else {
            // Try without prefix first, then with prefix
            $profile_data = $profiles[$profile_name] ?? $profiles[self::PROFILE_SECONDARY . $profile_name] ?? null;
        }
        if (!$profile_data) {
            throw new Credentials_Exception("Profile '{$profile_name}' does not exist. " . "Please ensure the specified profile is set at {$config_file}.");
        }
        if (empty($session = $profile_data[self::KEY_PROFILE_LOGIN_SESSION] ?? null)) {
            throw new Credentials_Exception("Profile '{$profile_name}' did not contain a " . self::KEY_PROFILE_LOGIN_SESSION . ' value. ' . 're-authentication using `aws login` may be needed.');
        }
        // Resolve location and ensure it exists
        $cache_directory = getenv(self::ENV_CACHE_DIRECTORY) ?: Credential_Provider::get_home_dir() . self::DEFAULT_CACHE_DIRECTORY;
        $cache_file = $cache_directory . DIRECTORY_SEPARATOR . hash('sha256', trim((string) $session)) . '.json';
        if (!@is_readable($cache_file)) {
            throw new Credentials_Exception('Failed to load cached credentials for profile ' . "'{$profile_name}'." . self::REAUTHENTICATE_MSG);
        }
        return $cache_file;
    }
    /**
     * Creates a SigninClient configured for DPoP authentication
     *
     * @param string|null $region The AWS region for the Signin service
     * @return SigninClient A configured SigninClient instance with DPoP signature version
     */
    private function create_signin_client(string $profile, ?string $region): Signin_Client
    {
        $resolved_region = $region ?? Configuration_Resolver::env(self::KEY_CLIENT_REGION) ?? Configuration_Resolver::ini(self::KEY_CLIENT_REGION, 'string', $profile) ?? Configuration_Resolver::ini(self::KEY_CLIENT_REGION, 'string', self::PROFILE_SECONDARY . $profile);
        if (empty($resolved_region)) {
            throw new Credentials_Exception('Unable to determine region for the Sign-In service client ' . ' used for refreshing Login credentials. You can provide a region in-code ' . 'when constructing the provider, by setting the AWS_REGION environment variable' . ', or by setting a `region` in your specified profile.');
        }
        return new Signin_Client([self::KEY_CLIENT_REGION => $resolved_region, self::KEY_CLIENT_SIGNATURE_VERSION => self::CLIENT_SIGNATURE_DPOP, self::KEY_CLIENT_CREDENTIALS => false]);
    }
    /**
     * Creates a Credentials object from token data
     *
     * @param array $tokenData The token data containing access key, secret, and session token
     * @param int $expiration Unix timestamp for credential expiration
     * @param string $accountId The AWS account ID
     *
     * @return Credentials The created Credentials object
     */
    private static function create_credentials(array $token_data, int $expiration, string $account_id): Credentials
    {
        return new Credentials($token_data[self::KEY_ACCESS_KEY_ID], $token_data[self::KEY_SECRET_ACCESS_KEY], $token_data[self::KEY_SESSION_TOKEN], $expiration, $account_id, Credential_Sources::PROFILE_LOGIN);
    }
    /**
     * Checks if all required keys are present and non-empty in the data array
     *
     * @param array $data The data array to check
     * @param array $requiredKeys The list of keys that must be present and non-empty
     *
     * @return bool True if all required keys are present and non-empty, false otherwise
     */
    private static function has_all_required_keys(array $data, array $required_keys): bool
    {
        foreach ($required_keys as $key) {
            if (empty($data[$key])) {
                return false;
            }
        }
        return true;
    }
}