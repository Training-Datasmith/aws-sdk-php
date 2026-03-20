<?php

declare (strict_types=1);
namespace Aws;

use Guzzle_Http\Promise;
/**
 * A configuration provider is a function that returns a promise that is
 * fulfilled with a configuration object. This class provides base functionality
 * usable by specific configuration provider implementations
 */
abstract class Abstract_Configuration_Provider
{
    public const ENV_PROFILE = 'AWS_PROFILE';
    public const ENV_CONFIG_FILE = 'AWS_CONFIG_FILE';
    public static $cache_key;
    protected static $interface_class;
    protected static $exception_class;
    /**
     * Wraps a config provider and saves provided configuration in an
     * instance of Aws\CacheInterface. Forwards calls when no config found
     * in cache and updates cache with the results.
     *
     * @param callable $provider Configuration provider function to wrap
     * @param CacheInterface $cache Cache to store configuration
     * @param string|null $cacheKey (optional) Cache key to use
     *
     * @return callable
     */
    public static function cache(callable $provider, Cache_Interface $cache, $cache_key = null)
    {
        $cache_key = $cache_key ?: static::$cache_key;
        return function () use ($provider, $cache, $cache_key) {
            $found = $cache->get($cache_key);
            if ($found instanceof static::$interface_class) {
                return Promise\Create::promise_for($found);
            }
            return $provider()->then(function ($config) use ($cache, $cache_key) {
                $cache->set($cache_key, $config);
                return $config;
            });
        };
    }
    /**
     * Creates an aggregate configuration provider that invokes the provided
     * variadic providers one after the other until a provider returns
     * configuration.
     *
     * @return callable
     */
    public static function chain()
    {
        $links = func_get_args();
        if (empty($links)) {
            throw new \InvalidArgumentException('No providers in chain');
        }
        return function () use ($links) {
            /** @var callable $parent */
            $parent = array_shift($links);
            $promise = $parent();
            while ($next = array_shift($links)) {
                $promise = $promise->otherwise($next);
            }
            return $promise;
        };
    }
    /**
     * Gets the environment's HOME directory if available.
     *
     * @return null|string
     */
    protected static function get_home_dir()
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
     * Gets default config file location from environment, falling back to aws
     * default location
     *
     * @return string
     */
    protected static function get_default_config_filename()
    {
        if ($filename = getenv(self::ENV_CONFIG_FILE)) {
            return $filename;
        }
        return self::get_home_dir() . '/.aws/config';
    }
    /**
     * Wraps a config provider and caches previously provided configuration.
     *
     * @param callable $provider Config provider function to wrap.
     *
     * @return callable
     */
    public static function memoize(callable $provider)
    {
        return function () use ($provider) {
            static $result;
            static $is_constant;
            // Constant config will be returned constantly.
            if ($is_constant) {
                return $result;
            }
            // Create the initial promise that will be used as the cached value
            if (null === $result) {
                $result = $provider();
            }
            // Return config and set flag that provider is already set
            return $result->then(function ($config) use (&$is_constant) {
                $is_constant = true;
                return $config;
            });
        };
    }
    /**
     * Reject promise with standardized exception.
     *
     * @param $msg
     * @return Promise\RejectedPromise
     */
    protected static function reject($msg)
    {
        $exception_class = static::$exception_class;
        return new Promise\Rejected_Promise(new $exception_class($msg));
    }
}