<?php

declare (strict_types=1);
namespace Aws\Client_Side_Monitoring\Exception;

use Aws\Has_Monitoring_Events_Trait;
use Aws\Monitoring_Events_Interface;
/**
 * Represents an error interacting with configuration for client-side monitoring.
 */
class Configuration_Exception extends \RuntimeException implements Monitoring_Events_Interface
{
    use Has_Monitoring_Events_Trait;
}