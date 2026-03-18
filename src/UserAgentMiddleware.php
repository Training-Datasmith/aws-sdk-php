<?php

namespace Aws;

use Aws\EndpointDiscovery\Configuration;
use Closure;
use Psr\Http\Message\RequestInterface;

/**
 * Builds and injects the user agent header values.
 * This middleware must be appended into step where all the
 * metrics to be gathered are already resolved. As of now it should be
 * after the signing step.
 */
class UserAgentMiddleware
{
    const AGENT_VERSION = 2.1;

    static $userAgentFnList = [
        'getSdkVersion',
        'getUserAgentVersion',
        'getHhvmVersion',
        'getOsName',
        'getLangVersion',
        'getExecEnv',
        'getEndpointDiscovery',
        'getAppId',
        'getMetrics'
    ];

    /** @var callable  */
    private $nextHandler;

    private ?\Aws\MetricsBuilder $metricsBuilder = null;

    /**
     * Returns a middleware wrapper function.
     *
     *
     */
    public static function wrap(
        array $args
    ) : Closure
    {
        return fn(callable $handler) => new self($handler, $args);
    }

    public function __construct(callable $nextHandler, private array $args=[])
    {
        $this->nextHandler = $nextHandler;
    }

    /**
     * When invoked, its injects the user agent header into the
     * request headers.
     *
     *
     * @return mixed
     */
    public function __invoke(CommandInterface $command, RequestInterface $request)
    {
        $handler = $this->nextHandler;
        $this->metricsBuilder = MetricsBuilder::fromCommand($command);
        $request = $this->requestWithUserAgentHeader($request);

        return $handler($command, $request);
    }

    /**
     * Builds the user agent header value, and injects it into the request
     * headers. Then, it returns the mutated request.
     *
     *
     */
    private function requestWithUserAgentHeader(RequestInterface $request): RequestInterface
    {
        $uaAppend = $this->args['ua_append'] ?? [];
        $userAgentValue = array_merge(
            $this->buildUserAgentValue(),
            $uaAppend
        );
        // It includes the user agent values just for the User-Agent header.
        // The reason is that the SEP does not mention appending the
        // metrics into the X-Amz-User-Agent header.
        return $request->withHeader(
            'User-Agent',
            implode(' ', array_merge(
                $userAgentValue,
                $request->getHeader('User-Agent')
            ))
        );
    }

    /**
     * Builds the different user agent values.
     */
    private function buildUserAgentValue(): array
    {
        $userAgentValue = [];
        foreach (self::$userAgentFnList as $fn) {
            $val = $this->{$fn}();
            if (!empty($val)) {
                $userAgentValue[] = $val;
            }
        }

        return $userAgentValue;
    }

    /**
     * Returns the user agent value for SDK version.
     */
    private function getSdkVersion(): string
    {
        return 'aws-sdk-php/' . Sdk::VERSION;
    }

    /**
     * Returns the user agent value for the agent version.
     */
    private function getUserAgentVersion(): string
    {
        return 'ua/' . self::AGENT_VERSION;
    }

    /**
     * Returns the user agent value for the hhvm version, but just
     * when it is defined.
     */
    private function getHhvmVersion(): string
    {
        if (defined('HHVM_VERSION')) {
            return 'HHVM/' . HHVM_VERSION;
        }

        return "";
    }

    /**
     * Returns the user agent value for the os version.
     */
    private function getOsName(): string
    {
        $disabledFunctions = explode(',', ini_get('disable_functions'));
        if (function_exists('php_uname')
            && !in_array('php_uname', $disabledFunctions, true)
        ) {
            // Replace spaces with underscores to prevent breaking the user agent format
            $os = str_replace(' ', '_', php_uname('s'));
            $release = php_uname('r');
            return "OS/{$os}#{$release}";
        }

        return "";
    }

    /**
     * Returns the user agent value for the php language used.
     */
    private function getLangVersion(): string
    {
        return 'lang/php#' . phpversion();
    }

    /**
     * Returns the user agent value for the execution env.
     */
    private function getExecEnv(): string
    {
        if ($executionEnvironment = getenv('AWS_EXECUTION_ENV')) {
            return $executionEnvironment;
        }

        return "";
    }

    /**
     * Returns the user agent value for endpoint discovery as cfg.
     * This feature is deprecated.
     */
    private function getEndpointDiscovery(): string
    {
        $args = $this->args;
        if (!isset($args['endpoint_discovery'])) {
            return "";
        }
        if ($args['endpoint_discovery'] instanceof Configuration
            && $args['endpoint_discovery']->isEnabled()) {
            return 'cfg/endpoint-discovery';
        }
        if (is_array($args['endpoint_discovery'])
            && isset($args['endpoint_discovery']['enabled'])
            && $args['endpoint_discovery']['enabled']) {
            return 'cfg/endpoint-discovery';
        }

        return "";
    }

    /**
     * Returns the user agent value for app id, but just when an
     * app id was provided as a client argument.
     */
    private function getAppId(): string
    {
        if (empty($this->args['app_id'])) {
            return "";
        }

        return 'app/' . $this->args['app_id'];
    }

    /**
     * Returns the user agent value for metrics.
     */
    private function getMetrics(): string
    {
        // Resolve first metrics related to client arguments.
        $this->metricsBuilder->resolveAndAppendFromArgs($this->args);
        // Build the metrics.
        $metricsEncoded = $this->metricsBuilder->build();
        if (empty($metricsEncoded)) {
            return "";
        }

        return "m/" . $metricsEncoded;
    }
}
