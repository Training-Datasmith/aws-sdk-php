<?php

declare (strict_types=1);
namespace Aws\Credentials;

/**
 * Provides access to the AWS credentials used for accessing AWS services: AWS
 * access key ID, secret access key, and security token. These credentials are
 * used to securely sign requests to AWS services.
 */
interface Credentials_Interface
{
    /**
     * Returns the AWS access key ID for this credentials object.
     *
     * @return string
     */
    public function get_access_key_id();
    /**
     * Returns the AWS secret access key for this credentials object.
     *
     * @return string
     */
    public function get_secret_key();
    /**
     * Get the associated security token if available
     *
     * @return string|null
     */
    public function get_security_token();
    /**
     * Get the UNIX timestamp in which the credentials will expire
     *
     * @return int|null
     */
    public function get_expiration();
    /**
     * Check if the credentials are expired
     *
     * @return bool
     */
    public function is_expired();
    /**
     * Converts the credentials to an associative array.
     *
     * @return array
     */
    public function to_array();
}