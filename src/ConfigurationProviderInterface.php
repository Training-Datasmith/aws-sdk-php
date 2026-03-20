<?php

declare (strict_types=1);
namespace Aws;

interface Configuration_Provider_Interface
{
    /**
     * Create a default config provider
     *
     * @return callable
     */
    public static function default_provider(array $config = []);
}