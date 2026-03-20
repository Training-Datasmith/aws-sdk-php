<?php

declare (strict_types=1);
namespace Aws\Api\Exception;

use Aws\Has_Monitoring_Events_Trait;
use Aws\Monitoring_Events_Interface;
class Rpc_V2cbor_Exception extends \RuntimeException implements Monitoring_Events_Interface
{
    use Has_Monitoring_Events_Trait;
}