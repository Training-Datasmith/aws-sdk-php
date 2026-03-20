<?php

declare (strict_types=1);
namespace Aws\Configuration;

class Configuration_Resolver
{
    public const ENV_PROFILE = 'AWS_PROFILE';
    public const ENV_CONFIG_FILE = 'AWS_CONFIG_FILE';
    public static $env_prefix = 'AWS_';
    /**
     * Generic configuration resolver that first checks for environment
     * variables, then checks for a specified profile in the environment-defined
     * config file location (env variable is 'AWS_CONFIG_FILE', file location
     * defaults to ~/.aws/config), then checks for the "default" profile in the
     * environment-defined config file location, and failing those uses a default
     * fallback value.
     *
     * @param string $key      Configuration key to be used when attempting
     *                         to retrieve value from the environment or ini file.
     * @param mixed $defaultValue
     * @param string $expectedType  The expected type of the retrieved value.
     * @param array $config additional configuration options.
     *
     * @return mixed
     */
    public static function resolve($key, $default_value, $expected_type, array $config = [])
    {
        $ini_options = $config['ini_resolver_options'] ?? [];
        $env_value = self::env($key, $expected_type);
        if (!is_null($env_value)) {
            return $env_value;
        }
        if (!isset($config['use_aws_shared_config_files']) || $config['use_aws_shared_config_files'] != false) {
            $ini_value = self::ini($key, $expected_type, null, null, $ini_options);
            if (!is_null($ini_value)) {
                return $ini_value;
            }
        }
        return $default_value;
    }
    /**
     * Resolves config values from environment variables.
     *
     * @param string $key      Configuration key to be used when attempting
     *                         to retrieve value from the environment.
     * @param string $expectedType  The expected type of the retrieved value.
     *
     * @return null | mixed
     */
    public static function env($key, $expected_type = 'string')
    {
        // Use config from environment variables, if available
        $env_value = getenv(self::$env_prefix . strtoupper($key));
        if (!empty($env_value)) {
            if ($expected_type) {
                return self::convert_type($env_value, $expected_type);
            }
            return $env_value;
        }
        return null;
    }
    /**
     * Gets config values from a config file whose location
     * is specified by an environment variable 'AWS_CONFIG_FILE', defaulting to
     * ~/.aws/config if not specified
     *
     *
     * @param string $key      Configuration key to be used when attempting
     *                         to retrieve value from ini file.
     * @param string $expectedType  The expected type of the retrieved value.
     * @param string|null $profile  Profile to use. If not specified will use
     *                              the "default" profile.
     * @param string|null $filename If provided, uses a custom filename rather
     *                              than looking in the default directory.
     *
     * @return null | mixed
     */
    public static function ini($key, $expected_type, $profile = null, $filename = null, array $options = [])
    {
        $filename = $filename ?: self::get_default_config_filename();
        $profile = $profile ?: (getenv(self::ENV_PROFILE) ?: 'default');
        if (!@is_readable($filename)) {
            return null;
        }
        // Use INI_SCANNER_NORMAL instead of INI_SCANNER_TYPED for PHP 5.5 compatibility
        //TODO change after deprecation
        $data = @\Aws\parse_ini_file($filename, true, INI_SCANNER_NORMAL);
        if (isset($options['section']) && isset($options['subsection']) && isset($options['key'])) {
            return self::retrieve_value_from_ini_subsection($data, $profile, $filename, $expected_type, $options);
        }
        if ($data === false || !isset($data[$profile]) || !isset($data[$profile][$key])) {
            return null;
        }
        // INI_SCANNER_NORMAL parses false-y values as an empty string
        if ($data[$profile][$key] === '') {
            if ($expected_type === 'bool') {
                $data[$profile][$key] = false;
            } elseif ($expected_type === 'int') {
                $data[$profile][$key] = 0;
            }
        }
        return self::convert_type($data[$profile][$key], $expected_type);
    }
    /**
     * Gets the environment's HOME directory if available.
     *
     * @return null | string
     */
    private static function get_home_dir()
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
    private static function get_default_config_filename()
    {
        if ($filename = getenv(self::ENV_CONFIG_FILE)) {
            return $filename;
        }
        return self::get_home_dir() . '/.aws/config';
    }
    /**
     * Normalizes string values pulled out of ini files and
     * environment variables.
     *
     * @param string $value The value retrieved from the environment or
     *                      ini file.
     * @param $type $string The type that the value needs to be converted to.
     *
     * @return mixed
     */
    private static function convert_type($value, $type)
    {
        if ($type === 'bool' && !is_null($converted_value = \Aws\boolean_value($value))) {
            return $converted_value;
        }
        if ($type === 'int' && filter_var($value, FILTER_VALIDATE_INT)) {
            return int_val($value);
        }
        return $value;
    }
    /**
     * Normalizes string values pulled out of ini files and
     * environment variables.
     *
     * @param array $data The data retrieved the ini file
     * @param string $profile The specified ini profile
     * @param string $filename The full path to the ini file
     * @param array $options Additional arguments passed to the configuration resolver
     *
     * @return mixed
     */
    private static function retrieve_value_from_ini_subsection(array $data, $profile, string $filename, $expected_type, array $options)
    {
        $section = $options['section'];
        if ($data === false || !isset($data[$profile][$section]) || !isset($data["{$section} {$data[$profile][$section]}"])) {
            return null;
        }
        $services_section = \Aws\parse_ini_section_with_subsections($filename, "services {$data[$profile]['services']}");
        if (empty($options['subsection']) || empty($options['key'])) {
            return null;
        }
        if (!isset($services_section[$options['subsection']][$options['key']])) {
            return null;
        }
        return self::convert_type($services_section[$options['subsection']][$options['key']], $expected_type);
    }
}