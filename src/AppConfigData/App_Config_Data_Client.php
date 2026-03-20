<?php

declare (strict_types=1);
namespace Aws\App_Config_Data;

use Aws\Aws_Client;
/**
 * This client is used to interact with the **AWS AppConfig Data** service.
 * @method \Aws\Result getLatestConfiguration(array $args = [])
 * @method \GuzzleHttp\Promise\Promise getLatestConfigurationAsync(array $args = [])
 * @method \Aws\Result startConfigurationSession(array $args = [])
 * @method \GuzzleHttp\Promise\Promise startConfigurationSessionAsync(array $args = [])
 */
class App_Config_Data_Client extends Aws_Client
{
}