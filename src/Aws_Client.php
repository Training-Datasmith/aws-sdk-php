<?php

declare (strict_types=1);
namespace Aws;

use Aws\Api\Api_Provider;
use Aws\Api\Doc_Model;
use Aws\Api\Service;
use Aws\Auth\Auth_Scheme_Resolver_Interface;
use Aws\Auth\Auth_Selection_Middleware;
use Aws\Endpoint_Discovery\Endpoint_Discovery_Middleware;
use Aws\Endpoint_V2\Endpoint_Provider_V2;
use Aws\Endpoint_V2\Endpoint_V2middleware;
use Aws\Exception\Aws_Exception;
use Aws\Signature\Signature_Provider;
use Guzzle_Http\Psr7\Uri;
use Psr\Http\Message\Request_Interface;
/**
 * Default AWS client implementation
 */
class Aws_Client implements Aws_Client_Interface
{
    use Aws_Client_Trait;
    /** @var array */
    private $aliases;
    /** @var array */
    private $config;
    /** @var string */
    private $region;
    /** @var string */
    private $signing_region_set;
    /** @var string */
    private \Guzzle_Http\Psr7\Uri $endpoint;
    /** @var Service */
    private $api;
    /** @var callable */
    private $signature_provider;
    /** @var AuthSchemeResolverInterface */
    private $auth_scheme_resolver;
    /** @var callable */
    private $credential_provider;
    /** @var callable */
    private $token_provider;
    private \Aws\Handler_List $handler_list;
    /** @var array*/
    private $default_request_options;
    /** @var array*/
    private $client_context_params = [];
    /** @var array*/
    protected $client_built_ins = [];
    /** @var  EndpointProviderV2 | callable */
    protected $endpoint_provider;
    /** @var callable */
    protected $serializer;
    /**
     * Get an array of client constructor arguments used by the client.
     *
     * @return array
     */
    public static function get_arguments()
    {
        return Client_Resolver::get_default_arguments();
    }
    /**
     * The client constructor accepts the following options:
     *
     * - api_provider: (callable) An optional PHP callable that accepts a
     *   type, service, and version argument, and returns an array of
     *   corresponding configuration data. The type value can be one of api,
     *   waiter, or paginator.
     * - credentials:
     *   (Aws\Credentials\CredentialsInterface|array|bool|callable) Specifies
     *   the credentials used to sign requests. Provide an
     *   Aws\Credentials\CredentialsInterface object, an associative array of
     *   "key", "secret", and an optional "token" key, `false` to use null
     *   credentials, or a callable credentials provider used to create
     *   credentials or return null. See Aws\Credentials\CredentialProvider for
     *   a list of built-in credentials providers. If no credentials are
     *   provided, the SDK will attempt to load them from the environment.
     * - token:
     *   (Aws\Token\TokenInterface|array|bool|callable) Specifies
     *   the token used to authorize requests. Provide an
     *   Aws\Token\TokenInterface object, an associative array of
     *   "token" and an optional "expires" key, `false` to use no
     *   token, or a callable token provider used to create a
     *   token or return null. See Aws\Token\TokenProvider for
     *   a list of built-in token providers. If no token is
     *   provided, the SDK will attempt to load one from the environment.
     * - csm:
     *   (Aws\ClientSideMonitoring\ConfigurationInterface|array|callable) Specifies
     *   the credentials used to sign requests. Provide an
     *   Aws\ClientSideMonitoring\ConfigurationInterface object, a callable
     *   configuration provider used to create client-side monitoring configuration,
     *   `false` to disable csm, or an associative array with the following keys:
     *   enabled: (bool) Set to true to enable client-side monitoring, defaults
     *   to false; host: (string) the host location to send monitoring events to,
     *   defaults to 127.0.0.1; port: (int) The port used for the host connection,
     *   defaults to 31000; client_id: (string) An identifier for this project
     * - debug: (bool|array) Set to true to display debug information when
     *   sending requests. Alternatively, you can provide an associative array
     *   with the following keys: logfn: (callable) Function that is invoked
     *   with log messages; stream_size: (int) When the size of a stream is
     *   greater than this number, the stream data will not be logged (set to
     *   "0" to not log any stream data); scrub_auth: (bool) Set to false to
     *   disable the scrubbing of auth data from the logged messages; http:
     *   (bool) Set to false to disable the "debug" feature of lower level HTTP
     *   adapters (e.g., verbose curl output).
     * - stats: (bool|array) Set to true to gather transfer statistics on
     *   requests sent. Alternatively, you can provide an associative array with
     *   the following keys: retries: (bool) Set to false to disable reporting
     *   on retries attempted; http: (bool) Set to true to enable collecting
     *   statistics from lower level HTTP adapters (e.g., values returned in
     *   GuzzleHttp\TransferStats). HTTP handlers must support an
     *   `http_stats_receiver` option for this to have an effect; timer: (bool)
     *   Set to true to enable a command timer that reports the total wall clock
     *   time spent on an operation in seconds.
     * - disable_host_prefix_injection: (bool) Set to true to disable host prefix
     *   injection logic for services that use it. This disables the entire
     *   prefix injection, including the portions supplied by user-defined
     *   parameters. Setting this flag will have no effect on services that do
     *   not use host prefix injection.
     * - endpoint: (string) The full URI of the webservice. This is only
     *   required when connecting to a custom endpoint (e.g., a local version
     *   of S3).
     * - endpoint_discovery: (Aws\EndpointDiscovery\ConfigurationInterface,
     *   Aws\CacheInterface, array, callable) Settings for endpoint discovery.
     *   Provide an instance of Aws\EndpointDiscovery\ConfigurationInterface,
     *   an instance Aws\CacheInterface, a callable that provides a promise for
     *   a Configuration object, or an associative array with the following
     *   keys: enabled: (bool) Set to true to enable endpoint discovery, false
     *   to explicitly disable it, defaults to false; cache_limit: (int) The
     *   maximum number of keys in the endpoints cache, defaults to 1000.
     * - endpoint_provider: (callable) An optional PHP callable that
     *   accepts a hash of options including a "service" and "region" key and
     *   returns NULL or a hash of endpoint data, of which the "endpoint" key
     *   is required. See Aws\Endpoint\EndpointProvider for a list of built-in
     *   providers.
     * - handler: (callable) A handler that accepts a command object,
     *   request object and returns a promise that is fulfilled with an
     *   Aws\ResultInterface object or rejected with an
     *   Aws\Exception\AwsException. A handler does not accept a next handler
     *   as it is terminal and expected to fulfill a command. If no handler is
     *   provided, a default Guzzle handler will be utilized.
     * - http: (array, default=array(0)) Set to an array of SDK request
     *   options to apply to each request (e.g., proxy, verify, etc.).
     * - http_handler: (callable) An HTTP handler is a function that
     *   accepts a PSR-7 request object and returns a promise that is fulfilled
     *   with a PSR-7 response object or rejected with an array of exception
     *   data. NOTE: This option supersedes any provided "handler" option.
     * - idempotency_auto_fill: (bool|callable) Set to false to disable SDK to
     *   populate parameters that enabled 'idempotencyToken' trait with a random
     *   UUID v4 value on your behalf. Using default value 'true' still allows
     *   parameter value to be overwritten when provided. Note: auto-fill only
     *   works when cryptographically secure random bytes generator functions
     *   (random_bytes, openssl_random_pseudo_bytes or mcrypt_create_iv) can be
     *   found. You may also provide a callable source of random bytes.
     * - profile: (string) Allows you to specify which profile to use when
     *   credentials are created from the AWS credentials file in your HOME
     *   directory. This setting overrides the AWS_PROFILE environment
     *   variable. Note: Specifying "profile" will cause the "credentials" key
     *   to be ignored.
     * - region: (string, required) Region to connect to. See
     *   http://docs.aws.amazon.com/general/latest/gr/rande.html for a list of
     *   available regions.
     * - retries: (int, Aws\Retry\ConfigurationInterface, Aws\CacheInterface,
     *   array, callable) Configures the retry mode and maximum number of
     *   allowed retries for a client (pass 0 to disable retries). Provide an
     *   integer for 'legacy' mode with the specified number of retries.
     *   Otherwise provide an instance of Aws\Retry\ConfigurationInterface, an
     *   instance of  Aws\CacheInterface, a callable function, or an array with
     *   the following keys: mode: (string) Set to 'legacy', 'standard' (uses
     *   retry quota management), or 'adapative' (an experimental mode that adds
     *   client-side rate limiting to standard mode); max_attempts (int) The
     *   maximum number of attempts for a given request.
     * - scheme: (string, default=string(5) "https") URI scheme to use when
     *   connecting connect. The SDK will utilize "https" endpoints (i.e.,
     *   utilize SSL/TLS connections) by default. You can attempt to connect to
     *   a service over an unencrypted "http" endpoint by setting ``scheme`` to
     *   "http".
     * - signature_provider: (callable) A callable that accepts a signature
     *   version name (e.g., "v4"), a service name, and region, and
     *   returns a SignatureInterface object or null. This provider is used to
     *   create signers utilized by the client. See
     *   Aws\Signature\SignatureProvider for a list of built-in providers
     * - signature_version: (string) A string representing a custom
     *   signature version to use with a service (e.g., v4). Note that
     *   per/operation signature version MAY override this requested signature
     *   version.
     * - use_aws_shared_config_files: (bool, default=bool(true)) Set to false to
     *   disable checking for shared config file in '~/.aws/config' and
     *   '~/.aws/credentials'.  This will override the AWS_CONFIG_FILE
     *   environment variable.
     * - validate: (bool, default=bool(true)) Set to false to disable
     *   client-side parameter validation.
     * - version: (string, required) The version of the webservice to
     *   utilize (e.g., 2006-03-01).
     * - account_id_endpoint_mode: (string, default(preferred)) this option
     *   decides whether credentials should resolve an accountId value,
     *   which is going to be used as part of the endpoint resolution.
     *   The valid values for this option are:
     *   - preferred: when this value is set then, a warning is logged when
     *     accountId is empty in the resolved identity.
     *   - required: when this value is set then, an exception is thrown when
     *     accountId is empty in the resolved identity.
     *   - disabled: when this value is set then, the validation for if accountId
     *     was resolved or not, is ignored.
     * - ua_append: (string, array) To pass custom user agent parameters.
     * - app_id: (string) an optional application specific identifier that can be set.
     *   When set it will be appended to the User-Agent header of every request
     *   in the form of App/{AppId}. This variable is sourced from environment
     *   variable AWS_SDK_UA_APP_ID or the shared config profile attribute sdk_ua_app_id.
     *   See https://docs.aws.amazon.com/sdkref/latest/guide/settings-reference.html for
     *   more information on environment variables and shared config settings.
     *
     * @param array $args Client configuration arguments.
     *
     * @throws \InvalidArgumentException if any required options are missing or
     *                                   the service is not supported.
     */
    public function __construct(array $args)
    {
        [$service, $exception_class] = $this->parse_class();
        if (!isset($args['service'])) {
            $args['service'] = manifest($service)['endpoint'];
        }
        if (!isset($args['exception_class'])) {
            $args['exception_class'] = $exception_class;
        }
        $this->handler_list = new Handler_List();
        $resolver = new Client_Resolver(static::get_arguments());
        $config = $resolver->resolve($args, $this->handler_list);
        $this->api = $config['api'];
        $this->signature_provider = $config['signature_provider'];
        $this->auth_scheme_resolver = $config['auth_scheme_resolver'];
        $this->endpoint = new Uri($config['endpoint']);
        $this->credential_provider = $config['credentials'];
        $this->token_provider = $config['token'];
        $this->region = $config['region'] ?? null;
        $this->signing_region_set = $config['sigv4a_signing_region_set'] ?? null;
        $this->config = $config['config'];
        $this->set_client_built_ins($args, $config);
        $this->client_context_params = $this->set_client_context_params($args);
        $this->default_request_options = $config['http'];
        $this->endpoint_provider = $config['endpoint_provider'];
        $this->serializer = $config['serializer'];
        $this->add_signature_middleware($args);
        $this->add_invocation_id();
        $this->add_endpoint_parameter_middleware($args);
        $this->add_endpoint_discovery_middleware($config, $args);
        $this->add_request_compression_middleware($config);
        $this->load_aliases();
        $this->add_stream_request_payload();
        $this->add_recursion_detection();
        if ($this->is_use_endpoint_v2()) {
            $this->add_endpoint_v2middleware();
        }
        $this->add_auth_selection_middleware($config['config']);
        if (!is_null($this->api->get_metadata('awsQueryCompatible'))) {
            $this->add_query_compatible_input_middleware($this->api);
            $this->add_query_mode_header();
        }
        if (isset($args['with_resolved'])) {
            $args['with_resolved']($config);
        }
        $this->add_user_agent_middleware($config);
        $this->add_event_stream_http_flag_middleware();
    }
    public function get_handler_list()
    {
        return $this->handler_list;
    }
    public function get_config($option = null)
    {
        return $option === null ? $this->config : $this->config[$option] ?? null;
    }
    public function get_credentials()
    {
        $fn = $this->credential_provider;
        return $fn();
    }
    public function get_token()
    {
        $fn = $this->token_provider;
        return $fn();
    }
    public function get_endpoint()
    {
        return $this->endpoint;
    }
    public function get_region()
    {
        return $this->region;
    }
    public function get_api()
    {
        return $this->api;
    }
    public function get_command($name, array $args = []): \Aws\Command
    {
        // Fail fast if the command cannot be found in the description.
        if (!isset($this->get_api()['operations'][$name])) {
            $name = ucfirst($name);
            if (!isset($this->get_api()['operations'][$name])) {
                throw new \InvalidArgumentException("Operation not found: {$name}");
            }
        }
        if (!isset($args['@http'])) {
            $args['@http'] = $this->default_request_options;
        } else {
            $args['@http'] += $this->default_request_options;
        }
        return new Command($name, $args, clone $this->get_handler_list());
    }
    public function get_endpoint_provider()
    {
        return $this->endpoint_provider;
    }
    /**
     * Provides the set of service context parameter
     * key-value pairs used for endpoint resolution.
     *
     * @return array
     */
    public function get_client_context_params()
    {
        return $this->client_context_params;
    }
    /**
     * Provides the set of built-in keys and values
     * used for endpoint resolution
     *
     * @return array
     */
    public function get_client_built_ins()
    {
        return $this->client_built_ins;
    }
    public function __sleep()
    {
        throw new \RuntimeException('Instances of ' . static::class . ' cannot be serialized');
    }
    /**
     * Get the signature_provider function of the client.
     *
     * @return callable
     */
    final public function get_signature_provider()
    {
        return $this->signature_provider;
    }
    /**
     * Parse the class name and setup the custom exception class of the client
     * and return the "service" name of the client and "exception_class".
     */
    private function parse_class(): array
    {
        $klass = static::class;
        if ($klass === self::class) {
            return ['', Aws_Exception::class];
        }
        $service = substr($klass, strrpos($klass, '\\') + 1, -6);
        return [strtolower($service), "Aws\\{$service}\\Exception\\{$service}Exception"];
    }
    private function add_endpoint_parameter_middleware(array $args): void
    {
        if (empty($args['disable_host_prefix_injection'])) {
            $list = $this->get_handler_list();
            $list->append_build(Endpoint_Parameter_Middleware::wrap($this->api), 'endpoint_parameter');
        }
    }
    private function add_endpoint_discovery_middleware(array $config, array $args): void
    {
        $list = $this->get_handler_list();
        if (!isset($args['endpoint'])) {
            $list->append_build(Endpoint_Discovery_Middleware::wrap($this, $args, $config['endpoint_discovery']), 'EndpointDiscoveryMiddleware');
        }
    }
    private function add_signature_middleware(array $args): void
    {
        $api = $this->get_api();
        $provider = $this->signature_provider;
        $signature_version = $this->config['signature_version'];
        $name = $this->config['signing_name'];
        $region = $this->config['signing_region'];
        $signing_region_set = $this->signing_region_set;
        if (isset($args['signature_version']) || isset($this->config['configured_signature_version'])) {
            $configured_signature_version = true;
        } else {
            $configured_signature_version = false;
        }
        $resolver = static function (Command_Interface $command) use ($api, $provider, $name, $region, $signature_version, $configured_signature_version, $signing_region_set) {
            if (!$configured_signature_version) {
                if (!empty($command['@context']['signing_region'])) {
                    $region = $command['@context']['signing_region'];
                }
                if (!empty($command['@context']['signing_service'])) {
                    $name = $command['@context']['signing_service'];
                }
                if (!empty($command['@context']['signature_version'])) {
                    $signature_version = $command['@context']['signature_version'];
                }
                $auth_type = $api->get_operation($command->get_name())['authtype'];
                switch ($auth_type) {
                    case 'none':
                        $signature_version = 'anonymous';
                        break;
                    case 'v4-unsigned-body':
                        $signature_version = 'v4-unsigned-body';
                        break;
                    case 'bearer':
                        $signature_version = 'bearer';
                        break;
                }
            }
            if ($signature_version === 'v4a') {
                $command_signing_region_set = !empty($command['@context']['signing_region_set']) ? implode(', ', $command['@context']['signing_region_set']) : null;
                $region = $signing_region_set ?? $command_signing_region_set ?? $region;
            }
            // Capture signature metric
            $command->get_metrics_builder()->identify_metric_by_value_and_append('signature', $signature_version);
            return Signature_Provider::resolve($provider, $signature_version, $name, $region);
        };
        $this->handler_list->append_sign(Middleware::signer($this->credential_provider, $resolver, $this->token_provider, $this->get_config()), 'signer');
    }
    private function add_request_compression_middleware(array $config): void
    {
        if (empty($config['disable_request_compression'])) {
            $list = $this->get_handler_list();
            $list->append_build(Request_Compression_Middleware::wrap($config), 'request-compression');
        }
    }
    private function add_query_compatible_input_middleware(Service $api): void
    {
        $list = $this->get_handler_list();
        $list->append_validate(Query_Compatible_Input_Middleware::wrap($api), 'query-compatible-input');
    }
    private function add_query_mode_header(): void
    {
        $list = $this->get_handler_list();
        $list->append_build(Middleware::map_request(fn(Request_Interface $r) => $r->with_header('x-amzn-query-mode', 'true')), 'x-amzn-query-mode-header');
    }
    private function add_invocation_id(): void
    {
        // Add invocation id to each request
        $this->handler_list->prepend_sign(Middleware::invocation_id(), 'invocation-id');
    }
    private function load_aliases($file = null): void
    {
        if (!isset($this->aliases)) {
            if (is_null($file)) {
                $file = __DIR__ . '/data/aliases.json';
            }
            $aliases = \Aws\load_compiled_json($file);
            $service_id = $this->api->get_service_id();
            $version = $this->get_api()->get_api_version();
            $service_aliases = null;
            if (!is_null($service_id) && isset($aliases['operations'][$service_id])) {
                $service_aliases = $aliases['operations'][$service_id];
            }
            if ($service_aliases && isset($service_aliases[$version])) {
                $this->aliases = array_flip($service_aliases[$version]);
            }
        }
    }
    private function add_stream_request_payload(): void
    {
        $stream_request_payload_middleware = Stream_Request_Payload_Middleware::wrap($this->api);
        $this->handler_list->prepend_sign($stream_request_payload_middleware, 'StreamRequestPayloadMiddleware');
    }
    private function add_recursion_detection(): void
    {
        // Add recursion detection header to requests
        // originating in supported Lambda runtimes
        $this->handler_list->append_build(Middleware::recursion_detection(), 'recursion-detection');
    }
    private function add_auth_selection_middleware(array $args): void
    {
        $list = $this->get_handler_list();
        $list->prepend_build(Auth_Selection_Middleware::wrap($this->auth_scheme_resolver, $this->get_api(), $args['auth_scheme_preference'] ?? null), 'auth-selection');
    }
    private function add_endpoint_v2middleware(): void
    {
        $list = $this->get_handler_list();
        $endpoint_args = $this->get_endpoint_provider_args();
        $list->prepend_build(Endpoint_V2middleware::wrap($this->endpoint_provider, $this->get_api(), $endpoint_args, $this->credential_provider), 'endpoint-resolution');
    }
    /**
     * Appends the user agent middleware.
     * This middleware MUST be appended after the
     * signature middleware `addSignatureMiddleware`,
     * so that metrics around signatures are properly
     * captured.
     *
     * @param $args
     */
    private function add_user_agent_middleware(array $args): void
    {
        $this->get_handler_list()->append_sign(User_Agent_Middleware::wrap($args), 'user-agent');
    }
    /**
     * Enables streaming the response by using the stream flag.
     */
    private function add_event_stream_http_flag_middleware(): void
    {
        $this->get_handler_list()->append_init(fn(callable $handler) => function (Command_Interface $command, $request = null) use ($handler) {
            $operation = $this->get_api()->get_operation($command->get_name());
            $output = $operation->get_output();
            foreach ($output->get_members() as $member_props) {
                if (!empty($member_props['eventstream'])) {
                    $command['@http']['stream'] = true;
                    break;
                }
            }
            return $handler($command, $request);
        }, 'event-streaming-flag-middleware');
    }
    /**
     * Retrieves client context param definition from service model,
     * creates mapping of client context param names with client-provided
     * values.
     */
    private function set_client_context_params(array $args): array
    {
        $api = $this->get_api();
        $resolved_params = [];
        if (!empty($param_definitions = $api->get_client_context_params())) {
            foreach ($param_definitions as $param_name => $param_value) {
                if (isset($args[$param_name])) {
                    $resolved_params[$param_name] = $args[$param_name];
                }
            }
        }
        return $resolved_params;
    }
    /**
     * Retrieves and sets default values used for endpoint resolution.
     */
    private function set_client_built_ins(array $args, array $resolved_config): void
    {
        $built_ins = [];
        $config = $resolved_config['config'];
        $service = $args['service'];
        $built_ins['SDK::Endpoint'] = null;
        if (!empty($args['endpoint'])) {
            $built_ins['SDK::Endpoint'] = $args['endpoint'];
        } elseif (isset($config['configured_endpoint_url'])) {
            $built_ins['SDK::Endpoint'] = (string) $this->get_endpoint();
        }
        $built_ins['AWS::Region'] = $this->get_region();
        $built_ins['AWS::UseFIPS'] = $config['use_fips_endpoint']->is_use_fips_endpoint();
        $built_ins['AWS::UseDualStack'] = $config['use_dual_stack_endpoint']->is_use_dualstack_endpoint();
        if ($service === 's3' || $service === 's3control') {
            $built_ins['AWS::S3::UseArnRegion'] = $config['use_arn_region']->is_use_arn_region();
        }
        if ($service === 's3') {
            $built_ins['AWS::S3::UseArnRegion'] = $config['use_arn_region']->is_use_arn_region();
            $built_ins['AWS::S3::Accelerate'] = $config['use_accelerate_endpoint'];
            $built_ins['AWS::S3::ForcePathStyle'] = $config['use_path_style_endpoint'];
            $built_ins['AWS::S3::DisableMultiRegionAccessPoints'] = $config['disable_multiregion_access_points'];
        }
        $built_ins['AWS::Auth::AccountIdEndpointMode'] = $resolved_config['account_id_endpoint_mode'];
        $this->client_built_ins += $built_ins;
    }
    /**
     * Retrieves arguments to be used in endpoint resolution.
     *
     * @return array
     */
    public function get_endpoint_provider_args()
    {
        return $this->normalize_endpoint_provider_args();
    }
    /**
     * Combines built-in and client context parameter values in
     * order of specificity.  Client context parameter values supersede
     * built-in values.
     */
    private function normalize_endpoint_provider_args(): array
    {
        $normalized_built_ins = [];
        foreach ($this->client_built_ins as $name => $value) {
            $normalized_name = explode('::', (string) $name);
            $normalized_name = $normalized_name[count($normalized_name) - 1];
            $normalized_built_ins[$normalized_name] = $value;
        }
        return array_merge($normalized_built_ins, $this->get_client_context_params());
    }
    protected function is_use_endpoint_v2(): bool
    {
        return $this->endpoint_provider instanceof Endpoint_Provider_V2;
    }
    /**
     * Returns a service model and doc model with any necessary changes
     * applied.
     *
     * @param array $api  Array of service data being documented.
     * @param array $docs Array of doc model data.
     *
     * @return array Tuple containing a [Service, DocModel]
     *
     * @internal This should only used to document the service API.
     * @codeCoverageIgnore
     */
    public static function apply_doc_filters(array $api, array $docs): array
    {
        $aliases = \Aws\load_compiled_json(__DIR__ . '/data/aliases.json');
        $service_id = $api['metadata']['serviceId'] ?? '';
        $version = $api['metadata']['apiVersion'];
        // Replace names for any operations with SDK aliases
        if (!empty($aliases['operations'][$service_id][$version])) {
            foreach ($aliases['operations'][$service_id][$version] as $op => $alias) {
                $api['operations'][$alias] = $api['operations'][$op];
                $docs['operations'][$alias] = $docs['operations'][$op];
                unset($api['operations'][$op], $docs['operations'][$op]);
            }
        }
        ksort($api['operations']);
        return [new Service($api, Api_Provider::default_provider()), new Doc_Model($docs)];
    }
    /**
     * @deprecated
     */
    public static function factory(array $config = []): static
    {
        return new static($config);
    }
}