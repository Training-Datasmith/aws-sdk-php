<?php

declare (strict_types=1);
namespace Aws\Auth\Exception;

use Aws\Has_Monitoring_Events_Trait;
use Aws\Monitoring_Events_Interface;
/**
 * Represents an error when attempting to resolve authentication.
 */
class Unresolved_Auth_Scheme_Exception extends \RuntimeException implements Monitoring_Events_Interface
{
    use Has_Monitoring_Events_Trait;
}