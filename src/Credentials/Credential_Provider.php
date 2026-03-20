<?php

declare (strict_types=1);
namespace Aws\Credentials;

use Aws;
use Aws\Api\Date_Time_Result;
use Aws\Cache_Interface;
use Aws\Exception\Credentials_Exception;
use Aws\Sts\Sts_Client;
use Guzzle_Http\Promise;
/**
 * Credential providers are functions that accept no arguments and return a
 * promise that is fulfilled with an {@see \Aws\Credentials\CredentialsInterface}
 * or rejected with an {@see \Aws\Exception\CredentialsException}.
 *
 * <code>
 * use Aws\Credentials\CredentialProvider;
 * $provider = CredentialProvider::defaultProvider();
 * // Returns a CredentialsInterface or throws.
 * $creds = $provider()->wait();
 * </code>
 *
 * Credential providers can be composed to create credentials using conditional
 * logic that can create different credentials in different environments. You
 * can compose multiple providers into a single provider using
 * {@see Aws\Credentials\CredentialProvider::chain}. This function accepts
 * providers as variadic arguments and returns a new function that will invoke
 * each provider until a successful set of credentials is returned.
 *
 * <code>
 * // First try an INI file at this location.
 * $a = CredentialProvider::ini(null, '/path/to/file.ini');
 * // Then try an INI file at this location.
 * $b = CredentialProvider::ini(null, '/path/to/other-file.ini');
 * // Then try loading from environment variables.
 * $c = CredentialProvider::env();
 * // Combine the three providers together.
 * $composed = CredentialProvider::chain($a, $b, $c);
 * // Returns a promise that is fulfilled with credentials or throws.
 * $promise = $composed();
 * // Wait on the credentials to resolve.
 * $creds = $promise->wait();
 * </code>
 */
class Credential_Provider
{
    public const ENV_ARN = 'AWS_ROLE_ARN';
    public const ENV_KEY = 'AWS_ACCESS_KEY_ID';
    public const ENV_PROFILE = 'AWS_PROFILE';
    public const ENV_ROLE_SESSION_NAME = 'AWS_ROLE_SESSION_NAME';
    public const ENV_SECRET = 'AWS_SECRET_ACCESS_KEY';
    public const ENV_ACCOUNT_ID = 'AWS_ACCOUNT_ID';
    public const ENV_SESSION = 'AWS_SESSION_TOKEN';
    public const ENV_TOKEN_FILE = 'AWS_WEB_IDENTITY_TOKEN_FILE';
    public const ENV_SHARED_CREDENTIALS_FILE = 'AWS_SHARED_CREDENTIALS_FILE';
    public const ENV_CONFIG_FILE = 'AWS_CONFIG_FILE';
    public const ENV_REGION = 'AWS_REGION';
    public const FALLBACK_REGION = 'us-east-1';
    public const REFRESH_WINDOW = 60;
    /**
     * Create a default credential provider that
     * first checks for environment variables,
     * then checks for assumed role via web identity,
     * then checks for cached SSO credentials from the CLI,
     * then check for credential_process in the "default" profile in ~/.aws/credentials,
     * then checks for the "default" profile in ~/.aws/credentials,
     * then for credential_process in the "default profile" profile in ~/.aws/config,
     * then checks for "profile default" profile in ~/.aws/config (which is
     * the default profile of AWS CLI),
     * then tries to make a GET Request to fetch credentials if ECS environment variable is presented,
     * finally checks for EC2 instance profile credentials.
     *
     * This provider is automatically wrapped in a memoize function that caches
     * previously provided credentials.
     *
     * @param array $config Optional array of ecs/instance profile credentials
     *                      provider options.
     *
     * @return callable
     */
    public static function default_provider(array $config = [])
    {
        $cacheable = ['web_identity', 'sso', 'login', 'process_credentials', 'process_config', 'ecs', 'instance'];
        $profile_name = getenv(self::ENV_PROFILE) ?: 'default';
        $default_chain = ['env' => self::env(), 'web_identity' => self::assume_role_with_web_identity_credential_provider($config)];
        if (!isset($config['use_aws_shared_config_files']) || $config['use_aws_shared_config_files'] !== false) {
            $default_chain['sso'] = self::sso($profile_name, self::get_config_file_name(), $config);
            $default_chain['login'] = self::login($profile_name, $config);
            $default_chain['process_credentials'] = self::process();
            $default_chain['ini'] = self::ini(null, null, $config);
            $default_chain['process_config'] = self::process('profile ' . $profile_name, self::get_config_file_name());
            $default_chain['ini_config'] = self::ini('profile ' . $profile_name, self::get_config_file_name());
        }
        if (self::should_use_ecs()) {
            $default_chain['ecs'] = self::ecs_credentials($config);
        } else {
            $default_chain['instance'] = self::instance_profile($config);
        }
        if (isset($config['credentials']) && $config['credentials'] instanceof Cache_Interface) {
            foreach ($cacheable as $provider) {
                if (isset($default_chain[$provider])) {
                    $default_chain[$provider] = self::cache($default_chain[$provider], $config['credentials'], 'aws_cached_' . $provider . '_credentials');
                }
            }
        }
        return self::memoize(call_user_func_array(Credential_Provider::chain(...), array_values($default_chain)));
    }
    /**
     * Create a credential provider function from a set of static credentials.
     *
     *
     * @return callable
     */
    public static function from_credentials(Credentials_Interface $creds)
    {
        $promise = Promise\Create::promise_for($creds);
        return fn() => $promise;
    }
    /**
     * Creates an aggregate credentials provider that invokes the provided
     * variadic providers one after the other until a provider returns
     * credentials.
     *
     * @return callable
     */
    public static function chain()
    {
        $links = func_get_args();
        if (empty($links)) {
            throw new \InvalidArgumentException('No providers in chain');
        }
        return function ($previous_creds = null) use ($links) {
            /** @var callable $parent */
            $parent = array_shift($links);
            $promise = $parent();
            while ($next = array_shift($links)) {
                if ($next instanceof Instance_Profile_Provider && $previous_creds instanceof Credentials) {
                    $promise = $promise->otherwise(fn() => $next($previous_creds));
                } else {
                    $promise = $promise->otherwise($next);
                }
            }
            return $promise;
        };
    }
    /**
     * Wraps a credential provider and caches previously provided credentials.
     *
     * Ensures that cached credentials are refreshed when they expire.
     *
     * @param callable $provider Credentials provider function to wrap.
     *
     * @return callable
     */
    public static function memoize(callable $provider)
    {
        return function () use ($provider) {
            static $result;
            static $is_constant;
            // Constant credentials will be returned constantly.
            if ($is_constant) {
                return $result;
            }
            // Create the initial promise that will be used as the cached value
            // until it expires.
            if (null === $result) {
                $result = $provider();
            }
            // Return credentials that could expire and refresh when needed.
            return $result->then(function (Credentials_Interface $creds) use ($provider, &$is_constant, &$result) {
                // Determine if these are constant credentials.
                if (!$creds->get_expiration()) {
                    $is_constant = true;
                    return $creds;
                }
                // Check if credentials are expired or will expire in 1 minute
                $needs_refresh = $creds->get_expiration() - time() <= self::REFRESH_WINDOW;
                // Refresh if expired or expiring soon
                if (!$needs_refresh && !$creds->is_expired()) {
                    return $creds;
                }
                // Refresh the result and forward the promise.
                return $result = $provider($creds);
            })->otherwise(function ($reason) use (&$result): \Guzzle_Http\Promise\Rejected_Promise {
                // Cleanup rejected promise.
                $result = null;
                return new Promise\Rejected_Promise($reason);
            });
        };
    }
    /**
     * Wraps a credential provider and saves provided credentials in an
     * instance of Aws\CacheInterface. Forwards calls when no credentials found
     * in cache and updates cache with the results.
     *
     * @param callable $provider Credentials provider function to wrap
     * @param CacheInterface $cache Cache to store credentials
     * @param string|null $cacheKey (optional) Cache key to use
     *
     * @return callable
     */
    public static function cache(callable $provider, Cache_Interface $cache, $cache_key = null)
    {
        $cache_key = $cache_key ?: 'aws_cached_credentials';
        return function () use ($provider, $cache, $cache_key) {
            $found = $cache->get($cache_key);
            if ($found instanceof Credentials_Interface && !$found->is_expired()) {
                return Promise\Create::promise_for($found);
            }
            return $provider()->then(function (Credentials_Interface $creds) use ($cache, $cache_key): \Aws\Credentials\Credentials_Interface {
                $cache->set($cache_key, $creds, null === $creds->get_expiration() ? 0 : $creds->get_expiration() - time());
                return $creds;
            });
        };
    }
    /**
     * Provider that creates credentials from environment variables
     * AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and AWS_SESSION_TOKEN.
     *
     * @return callable
     */
    public static function env()
    {
        return function () {
            // Use credentials from environment variables, if available
            $key = getenv(self::ENV_KEY);
            $secret = getenv(self::ENV_SECRET);
            $account_id = getenv(self::ENV_ACCOUNT_ID) ?: null;
            $token = getenv(self::ENV_SESSION) ?: null;
            if ($key && $secret) {
                return Promise\Create::promise_for(new Credentials($key, $secret, $token, null, $account_id, Credential_Sources::ENVIRONMENT));
            }
            return self::reject('Could not find environment variable ' . 'credentials in ' . self::ENV_KEY . '/' . self::ENV_SECRET);
        };
    }
    /**
     * Credential provider that creates credentials using instance profile
     * credentials.
     *
     * @param array $config Array of configuration data.
     *
     * @see Aws\Credentials\InstanceProfileProvider for $config details.
     */
    public static function instance_profile(array $config = []): \Aws\Credentials\Instance_Profile_Provider
    {
        return new Instance_Profile_Provider($config);
    }
    /**
     * Credential provider that retrieves cached SSO credentials from the CLI
     *
     * @return callable
     */
    public static function sso($sso_profile_name = 'default', $filename = null, $config = [])
    {
        $filename ??= self::get_config_file_name();
        return function () use ($sso_profile_name, $filename, $config) {
            if (!@is_readable($filename)) {
                return self::reject("Cannot read credentials from {$filename}");
            }
            $profiles = self::load_profiles($filename);
            if (isset($profiles[$sso_profile_name])) {
                $sso_profile = $profiles[$sso_profile_name];
            } elseif (isset($profiles['profile ' . $sso_profile_name])) {
                $sso_profile_name = 'profile ' . $sso_profile_name;
                $sso_profile = $profiles[$sso_profile_name];
            } else {
                return self::reject("Profile {$sso_profile_name} does not exist in {$filename}.");
            }
            if (!empty($sso_profile['sso_session'])) {
                return Credential_Provider::get_sso_credentials($profiles, $sso_profile_name, $filename, $config);
            }
            return Credential_Provider::get_sso_credentials_legacy($profiles, $sso_profile_name, $filename, $config);
        };
    }
    /**
     * Credential provider that creates credentials using
     * ecs credentials by a GET request, whose uri is specified
     * by environment variable
     *
     * @param array $config Array of configuration data.
     *
     * @see Aws\Credentials\EcsCredentialProvider for $config details.
     */
    public static function ecs_credentials(array $config = []): \Aws\Credentials\Ecs_Credential_Provider
    {
        return new Ecs_Credential_Provider($config);
    }
    /**
     * Credential provider that creates credentials using assume role
     *
     * @param array $config Array of configuration data
     * @return callable
     * @see Aws\Credentials\AssumeRoleCredentialProvider for $config details.
     */
    public static function assume_role(array $config = []): \Aws\Credentials\Assume_Role_Credential_Provider
    {
        return new Assume_Role_Credential_Provider($config);
    }
    /**
     * Credential provider that creates credentials by assuming role from a
     * Web Identity Token
     *
     * @param array $config Array of configuration data
     * @return callable
     * @see Aws\Credentials\AssumeRoleWithWebIdentityCredentialProvider for
     * $config details.
     */
    public static function assume_role_with_web_identity_credential_provider(array $config = [])
    {
        return function () use ($config) {
            $arn_from_env = getenv(self::ENV_ARN);
            $token_from_env = getenv(self::ENV_TOKEN_FILE);
            $sts_client = $config['stsClient'] ?? null;
            $region = $config['region'] ?? null;
            if ($token_from_env && $arn_from_env) {
                $session_name = getenv(self::ENV_ROLE_SESSION_NAME) ?: null;
                $provider = new Assume_Role_With_Web_Identity_Credential_Provider(['RoleArn' => $arn_from_env, 'WebIdentityTokenFile' => $token_from_env, 'SessionName' => $session_name, 'client' => $sts_client, 'region' => $region, 'source' => Credential_Sources::ENVIRONMENT_STS_WEB_ID_TOKEN]);
                return $provider();
            }
            $profile_name = getenv(self::ENV_PROFILE) ?: 'default';
            if (isset($config['filename'])) {
                $profiles = self::load_profiles($config['filename']);
            } else {
                $profiles = self::load_default_profiles();
            }
            if (isset($profiles[$profile_name])) {
                $profile = $profiles[$profile_name];
                if (isset($profile['region'])) {
                    $region = $profile['region'];
                }
                if (isset($profile['web_identity_token_file']) && isset($profile['role_arn'])) {
                    $session_name = $profile['role_session_name'] ?? null;
                    $provider = new Assume_Role_With_Web_Identity_Credential_Provider(['RoleArn' => $profile['role_arn'], 'WebIdentityTokenFile' => $profile['web_identity_token_file'], 'SessionName' => $session_name, 'client' => $sts_client, 'region' => $region, 'source' => Credential_Sources::PROFILE_STS_WEB_ID_TOKEN]);
                    return $provider();
                }
            } else {
                return self::reject("Unknown profile: {$profile_name}");
            }
            return self::reject('No RoleArn or WebIdentityTokenFile specified');
        };
    }
    /**
     * Credentials provider that creates credentials using an ini file stored
     * in the current user's home directory.  A source can be provided
     * in this file for assuming a role using the credential_source config option.
     *
     * @param string|null $profile  Profile to use. If not specified will use
     *                              the "default" profile in "~/.aws/credentials".
     * @param string|null $filename If provided, uses a custom filename rather
     *                              than looking in the home directory.
     * @param array|null $config If provided, may contain the following:
     *                           preferStaticCredentials: If true, prefer static
     *                           credentials to role_arn if both are present
     *                           disableAssumeRole: If true, disable support for
     *                           roles that assume an IAM role. If true and role profile
     *                           is selected, an error is raised.
     *                           stsClient: StsClient used to assume role specified in profile
     *
     * @return callable
     */
    public static function ini($profile = null, $filename = null, array $config = [])
    {
        $filename = self::get_credentials_file_name($filename);
        $profile = $profile ?: (getenv(self::ENV_PROFILE) ?: 'default');
        return function () use ($profile, $filename, $config) {
            $prefer_static_credentials = $config['preferStaticCredentials'] ?? false;
            $disable_assume_role = $config['disableAssumeRole'] ?? false;
            $sts_client = $config['stsClient'] ?? null;
            if (!@is_readable($filename)) {
                return self::reject("Cannot read credentials from {$filename}");
            }
            $data = self::load_profiles($filename);
            if ($data === false) {
                return self::reject("Invalid credentials file: {$filename}");
            }
            if (!isset($data[$profile])) {
                return self::reject("'{$profile}' not found in credentials file");
            }
            /*
            In the CLI, the presence of both a role_arn and static credentials have
            different meanings depending on how many profiles have been visited. For
            the first profile processed, role_arn takes precedence over any static
            credentials, but for all subsequent profiles, static credentials are
            used if present, and only in their absence will the profile's
            source_profile and role_arn keys be used to load another set of
            credentials. This bool is intended to yield compatible behaviour in this
            sdk.
            */
            $prefer_static_credentials_to_role_arn = $prefer_static_credentials && isset($data[$profile]['aws_access_key_id']) && isset($data[$profile]['aws_secret_access_key']);
            if (isset($data[$profile]['role_arn']) && !$prefer_static_credentials_to_role_arn) {
                if ($disable_assume_role) {
                    return self::reject('Role assumption profiles are disabled. ' . 'Failed to load profile ' . $profile);
                }
                return self::load_role_profile($data, $profile, $filename, $sts_client, $config);
            }
            if (!isset($data[$profile]['aws_access_key_id']) || !isset($data[$profile]['aws_secret_access_key'])) {
                return self::reject('No credentials present in INI profile ' . "'{$profile}' ({$filename})");
            }
            if (empty($data[$profile]['aws_session_token'])) {
                $data[$profile]['aws_session_token'] = $data[$profile]['aws_security_token'] ?? null;
            }
            return Promise\Create::promise_for(new Credentials($data[$profile]['aws_access_key_id'], $data[$profile]['aws_secret_access_key'], $data[$profile]['aws_session_token'], null, $data[$profile]['aws_account_id'] ?? null, Credential_Sources::PROFILE));
        };
    }
    /**
     * Credentials provider that creates credentials using a process configured in
     * ini file stored in the current user's home directory.
     *
     * @param string|null $profile  Profile to use. If not specified will use
     *                              the "default" profile in "~/.aws/credentials".
     * @param string|null $filename If provided, uses a custom filename rather
     *                              than looking in the home directory.
     *
     * @return callable
     */
    public static function process($profile = null, $filename = null)
    {
        $filename = self::get_credentials_file_name($filename);
        $profile = $profile ?: (getenv(self::ENV_PROFILE) ?: 'default');
        return function () use ($profile, $filename) {
            if (!@is_readable($filename)) {
                return self::reject("Cannot read process credentials from {$filename}");
            }
            $data = \Aws\parse_ini_file($filename, true, INI_SCANNER_RAW);
            if ($data === false) {
                return self::reject("Invalid credentials file: {$filename}");
            }
            if (!isset($data[$profile])) {
                return self::reject("'{$profile}' not found in credentials file");
            }
            if (!isset($data[$profile]['credential_process'])) {
                return self::reject('No credential_process present in INI profile ' . "'{$profile}' ({$filename})");
            }
            $credential_process = $data[$profile]['credential_process'];
            $json = shell_exec($credential_process);
            $process_data = json_decode($json, true);
            // Only support version 1
            if (isset($process_data['Version'])) {
                if ($process_data['Version'] !== 1) {
                    return self::reject('credential_process does not return Version == 1');
                }
            }
            if (!isset($process_data['AccessKeyId']) || !isset($process_data['SecretAccessKey'])) {
                return self::reject('credential_process does not return valid credentials');
            }
            if (isset($process_data['Expiration'])) {
                try {
                    $expiration = new Date_Time_Result($process_data['Expiration']);
                } catch (\Exception) {
                    return self::reject('credential_process returned invalid expiration');
                }
                $now = new Date_Time_Result();
                if ($expiration < $now) {
                    return self::reject('credential_process returned expired credentials');
                }
                $expires = $expiration->get_timestamp();
            } else {
                $expires = null;
            }
            if (empty($process_data['SessionToken'])) {
                $process_data['SessionToken'] = null;
            }
            $account_id = null;
            if (!empty($process_data['AccountId'])) {
                $account_id = $process_data['AccountId'];
            } elseif (!empty($data[$profile]['aws_account_id'])) {
                $account_id = $data[$profile]['aws_account_id'];
            }
            return Promise\Create::promise_for(new Credentials($process_data['AccessKeyId'], $process_data['SecretAccessKey'], $process_data['SessionToken'], $expires, $account_id, Credential_Sources::PROFILE_PROCESS));
        };
    }
    /**
     * Login credential provider for AWS local development using console credentials
     *
     * @param string|null $profileName profile containing your console login session information
     * @param array $config region used for refresh requests.
     *                      pass `'region' => <your_region>` to configure a region,
     *                      otherwise, provider construction falls back to AWS_REGION,
     *                      then the profile specified for `login`
     */
    public static function login(?string $profile_name = null, array $config = []): callable
    {
        $resolved_profile = $profile_name ?? getenv(self::ENV_PROFILE) ?: 'default';
        return static function () use ($resolved_profile, $config) {
            try {
                $provider = new Login_Credential_Provider($resolved_profile, $config['region'] ?? null);
            } catch (\Exception $e) {
                return self::reject("Failed to initialize login credential provider for profile '{$resolved_profile}': " . $e->get_message());
            }
            return $provider();
        };
    }
    /**
     * Assumes role for profile that includes role_arn
     *
     * @return callable
     */
    private static function load_role_profile(array $profiles, string $profile_name, string $filename, $sts_client, array $config = [])
    {
        $role_profile = $profiles[$profile_name];
        $role_arn = $role_profile['role_arn'] ?? '';
        $role_session_name = $role_profile['role_session_name'] ?? 'aws-sdk-php-' . round(microtime(true) * 1000);
        if (empty($role_profile['source_profile']) == empty($role_profile['credential_source'])) {
            return self::reject('Either source_profile or credential_source must be set ' . 'using profile ' . $profile_name . ', but not both.');
        }
        $source_profile_name = '';
        if (!empty($role_profile['source_profile'])) {
            $source_profile_name = $role_profile['source_profile'];
            if (!isset($profiles[$source_profile_name])) {
                return self::reject('source_profile ' . $source_profile_name . ' using profile ' . $profile_name . ' does not exist');
            }
            if (isset($config['visited_profiles']) && in_array($role_profile['source_profile'], $config['visited_profiles'])) {
                return self::reject('Circular source_profile reference found.');
            }
            $config['visited_profiles'][] = $role_profile['source_profile'];
        } else if (empty($role_arn)) {
            return self::reject('A role_arn must be provided with credential_source in ' . "file {$filename} under profile {$profile_name} ");
        }
        if (empty($sts_client)) {
            $config['preferStaticCredentials'] = true;
            $source_credentials = null;
            if (!empty($role_profile['source_profile'])) {
                $source_credentials = call_user_func(Credential_Provider::ini($source_profile_name, $filename, $config))->wait();
            } else {
                $source_credentials = self::get_credentials_from_source($profile_name, $filename);
            }
            $region = $profiles[$source_profile_name]['region'] ?? $config['region'] ?? get_env(self::ENV_REGION) ?: null;
            $sts_client = self::create_default_sts_client($source_credentials, $region);
        }
        $result = $sts_client->assume_role(['RoleArn' => $role_arn, 'RoleSessionName' => $role_session_name]);
        $credentials = $sts_client->create_credentials($result, Credential_Sources::STS_ASSUME_ROLE);
        return Promise\Create::promise_for($credentials);
    }
    /**
     * Gets the environment's HOME directory if available.
     *
     * @return null|string
     */
    public static function get_home_dir()
    {
        // On Linux/Unix-like systems, use the HOME environment variable
        if ($home_dir = getenv('HOME')) {
            return $home_dir;
        }
        // Get the HOMEDRIVE and HOMEPATH values for Windows hosts
        $home_drive = getenv('HOMEDRIVE');
        $home_path = getenv('HOMEPATH');
        return $home_drive && $home_path ? $home_drive . $home_path : null;
    }
    /**
     * Gets profiles from specified $filename, or default ini files.
     */
    public static function load_profiles($filename)
    {
        $profile_data = \Aws\parse_ini_file($filename, true, INI_SCANNER_RAW);
        // If loading .aws/credentials, also load .aws/config when AWS_SDK_LOAD_NONDEFAULT_CONFIG is set
        if ($filename === self::get_home_dir() . '/.aws/credentials' && getenv('AWS_SDK_LOAD_NONDEFAULT_CONFIG')) {
            $config_filename = self::get_config_file_name();
            $config_profile_data = \Aws\parse_ini_file($config_filename, true, INI_SCANNER_RAW);
            foreach ($config_profile_data as $name => $profile) {
                // standardize config profile names
                $name = str_replace('profile ', '', $name);
                if (!isset($profile_data[$name])) {
                    $profile_data[$name] = $profile;
                }
            }
        }
        return $profile_data;
    }
    /**
     * Gets profiles from ~/.aws/credentials and ~/.aws/config ini files
     */
    private static function load_default_profiles()
    {
        $profiles = [];
        $cred_file = self::get_home_dir() . '/.aws/credentials';
        $config_file = self::get_home_dir() . '/.aws/config';
        if (file_exists($cred_file)) {
            $profiles = \Aws\parse_ini_file($cred_file, true, INI_SCANNER_RAW);
        }
        if (file_exists($config_file)) {
            $config_profile_data = \Aws\parse_ini_file($config_file, true, INI_SCANNER_RAW);
            foreach ($config_profile_data as $name => $profile) {
                // standardize config profile names
                $name = str_replace('profile ', '', $name);
                if (!isset($profiles[$name])) {
                    $profiles[$name] = $profile;
                }
            }
        }
        return $profiles;
    }
    public static function get_credentials_from_source($profile_name = '', $filename = '', array $config = [])
    {
        $data = self::load_profiles($filename);
        $credential_source = !empty($data[$profile_name]['credential_source']) ? $data[$profile_name]['credential_source'] : null;
        $credentials_promise = match ($credential_source) {
            'Environment' => self::env(),
            'Ec2InstanceMetadata' => self::instance_profile($config),
            'EcsContainer' => self::ecs_credentials($config),
            default => throw new Credentials_Exception("Invalid credential_source found in config file: {$credential_source}. Valid inputs " . 'include Environment, Ec2InstanceMetadata, and EcsContainer.'),
        };
        $credentials_result = null;
        try {
            $credentials_result = $credentials_promise()->wait();
        } catch (\Exception $reason) {
            return self::reject('Unable to successfully retrieve credentials from the source specified in the' . " credentials file: {$credential_source}; failure message was: " . $reason->get_message());
        }
        return fn() => Promise\Create::promise_for($credentials_result);
    }
    private static function reject(string $msg)
    {
        return new Promise\Rejected_Promise(new Credentials_Exception($msg));
    }
    /**
     * Locates shared configuration file by first checking for AWS_CONFIG,
     * then falling back to the default location.  Returns the path of the
     * resolved configuration file.
     */
    public static function get_config_file_name(): string
    {
        return getenv(self::ENV_CONFIG_FILE) ?: self::get_home_dir() . '/.aws/config';
    }
    /**
     *  Locates credentials file by first checking for AWS_SHARED_CREDENTIALS_FILE,
     *  then falling back to the default location.  Returns the path of the
     *  resolved credentials file.
     *
     * @param $filename
     */
    public static function get_credentials_file_name($filename): string
    {
        if (!isset($filename)) {
            return getenv(self::ENV_SHARED_CREDENTIALS_FILE) ?: self::get_home_dir() . '/.aws/credentials';
        }
        return $filename;
    }
    public static function should_use_ecs(): bool
    {
        //Check for relative uri. if not, then full uri.
        //fall back to server for each as getenv is not thread-safe.
        return !empty(getenv(Ecs_Credential_Provider::ENV_URI)) || !empty($_SERVER[Ecs_Credential_Provider::ENV_URI]) || !empty(getenv(Ecs_Credential_Provider::ENV_FULL_URI)) || !empty($_SERVER[Ecs_Credential_Provider::ENV_FULL_URI]);
    }
    /**
     * @param $profiles
     * @param $ssoProfileName
     * @param $filename
     * @param $config
     * @return Promise\PromiseInterface
     */
    private static function get_sso_credentials(array $profiles, $sso_profile_name, string $filename, array $config)
    {
        if (empty($config['ssoOidcClient'])) {
            $sso_profile = $profiles[$sso_profile_name];
            $session_name = $sso_profile['sso_session'];
            if (empty($profiles['sso-session ' . $session_name])) {
                return self::reject("Could not find sso-session {$session_name} in {$filename}");
            }
            $sso_session = $profiles['sso-session ' . $sso_profile['sso_session']];
            $sso_oidc_client = new Aws\SSOOIDC\Ssooidc_Client(['region' => $sso_session['sso_region'], 'version' => '2019-06-10', 'credentials' => false]);
        } else {
            $sso_oidc_client = $config['ssoClient'];
        }
        $token_promise = new Aws\Token\Sso_Token_Provider($sso_profile_name, $filename, $sso_oidc_client);
        $token = $token_promise()->wait();
        $sso_credentials = Credential_Provider::get_credentials_from_sso_service($sso_profile, $sso_session['sso_region'], $token->get_token(), $config);
        //Expiration value is returned in epoch milliseconds. Conversion to seconds
        $expiration = intdiv($sso_credentials['expiration'], 1000);
        return Promise\Create::promise_for(new Credentials($sso_credentials['accessKeyId'], $sso_credentials['secretAccessKey'], $sso_credentials['sessionToken'], $expiration, $sso_profile['sso_account_id'], Credential_Sources::PROFILE_SSO));
    }
    /**
     * @param $profiles
     * @param $ssoProfileName
     * @param $filename
     * @param $config
     * @return Promise\PromiseInterface
     */
    private static function get_sso_credentials_legacy(array $profiles, $sso_profile_name, string $filename, $config)
    {
        $sso_profile = $profiles[$sso_profile_name];
        if (empty($sso_profile['sso_start_url']) || empty($sso_profile['sso_region']) || empty($sso_profile['sso_account_id']) || empty($sso_profile['sso_role_name'])) {
            return self::reject("Profile {$sso_profile_name} in {$filename} must contain the following keys: " . 'sso_start_url, sso_region, sso_account_id, and sso_role_name.');
        }
        $token_location = self::get_home_dir() . '/.aws/sso/cache/' . sha1((string) $sso_profile['sso_start_url']) . '.json';
        if (!@is_readable($token_location)) {
            return self::reject("Unable to read token file at {$token_location}");
        }
        $token_data = json_decode(file_get_contents($token_location), true);
        if (empty($token_data['accessToken']) || empty($token_data['expiresAt'])) {
            return self::reject("Token file at {$token_location} must contain an access token and an expiration");
        }
        try {
            $expiration = (new Date_Time_Result($token_data['expiresAt']))->get_timestamp();
        } catch (\Exception) {
            return self::reject('Cached SSO credentials returned an invalid expiration');
        }
        $now = time();
        if ($expiration < $now) {
            return self::reject('Cached SSO credentials returned expired credentials');
        }
        $sso_credentials = Credential_Provider::get_credentials_from_sso_service($sso_profile, $sso_profile['sso_region'], $token_data['accessToken'], $config);
        return Promise\Create::promise_for(new Credentials($sso_credentials['accessKeyId'], $sso_credentials['secretAccessKey'], $sso_credentials['sessionToken'], $expiration, $sso_profile['sso_account_id'], Credential_Sources::PROFILE_SSO_LEGACY));
    }
    /**
     * @param string $clientRegion
     * @param string $accessToken
     * @return array|null
     */
    private static function get_credentials_from_sso_service(array $sso_profile, $client_region, $access_token, array $config)
    {
        if (empty($config['ssoClient'])) {
            $sso_client = new Aws\SSO\Sso_Client(['region' => $client_region, 'version' => '2019-06-10', 'credentials' => false]);
        } else {
            $sso_client = $config['ssoClient'];
        }
        $sso_response = $sso_client->get_role_credentials(['accessToken' => $access_token, 'accountId' => $sso_profile['sso_account_id'], 'roleName' => $sso_profile['sso_role_name']]);
        return $sso_response['roleCredentials'];
    }
    /**
     * @param CredentialsInterface $credentials
     *
     */
    private static function create_default_sts_client(Credentials_Interface|callable $credentials, ?string $region): Sts_Client
    {
        if (empty($region)) {
            $region = self::FALLBACK_REGION;
            trigger_error('NOTICE: STS client created without explicit `region` configuration.' . PHP_EOL . "Defaulting to `{$region}`. This fallback behavior may be removed." . PHP_EOL . 'To avoid potential disruptions, configure a `region` using one of the following methods:' . PHP_EOL . '(1) Add `region` to your source profile in ~/.aws/credentials,' . PHP_EOL . '(2) Pass `region` in the `$config` array when calling the provider,' . PHP_EOL . '(3) Set the `AWS_REGION` environment variable.' . PHP_EOL . 'See: https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_assume_role.html#assume-role-with-profile' . PHP_EOL, E_USER_NOTICE);
        }
        return new Sts_Client(['credentials' => $credentials, 'region' => $region]);
    }
}