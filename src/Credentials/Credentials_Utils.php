<?php

declare (strict_types=1);
namespace Aws\Credentials;

final class Credentials_Utils
{
    /**
     * Determines whether a given host
     * is a loopback address.
     *
     * @param $host
     */
    public static function is_loop_back_address($host): bool
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($host === '::1') {
                return true;
            }
            return false;
        }
        $loopback_start = ip2long('127.0.0.0');
        $loopback_end = ip2long('127.255.255.255');
        $ip_long = ip2long($host);
        return $ip_long >= $loopback_start && $ip_long <= $loopback_end;
    }
}