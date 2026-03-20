<?php

declare (strict_types=1);
namespace Aws\Client_Side_Monitoring;

/**
 * Provides access to client-side monitoring configuration options:
 * 'client_id', 'enabled', 'host', 'port'
 */
interface Configuration_Interface
{
    /**
     * Checks whether or not client-side monitoring is enabled.
     *
     * @return bool
     */
    public function is_enabled();
    /**
     * Returns the Client ID, if available.
     *
     * @return string|null
     */
    public function get_client_id();
    /**
     * Returns the configured host.
     *
     * @return string|null
     */
    public function get_host();
    /**
     * Returns the configured port.
     *
     * @return int|null
     */
    public function get_port();
    /**
     * Returns the configuration as an associative array.
     *
     * @return array
     */
    public function to_array();
}