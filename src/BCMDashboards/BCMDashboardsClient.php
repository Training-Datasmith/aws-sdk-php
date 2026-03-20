<?php

declare (strict_types=1);
namespace Aws\Bcm_Dashboards;

use Aws\Aws_Client;
/**
 * This client is used to interact with the **AWS Billing and Cost Management Dashboards** service.
 * @method \Aws\Result createDashboard(array $args = [])
 * @method \GuzzleHttp\Promise\Promise createDashboardAsync(array $args = [])
 * @method \Aws\Result deleteDashboard(array $args = [])
 * @method \GuzzleHttp\Promise\Promise deleteDashboardAsync(array $args = [])
 * @method \Aws\Result getDashboard(array $args = [])
 * @method \GuzzleHttp\Promise\Promise getDashboardAsync(array $args = [])
 * @method \Aws\Result getResourcePolicy(array $args = [])
 * @method \GuzzleHttp\Promise\Promise getResourcePolicyAsync(array $args = [])
 * @method \Aws\Result listDashboards(array $args = [])
 * @method \GuzzleHttp\Promise\Promise listDashboardsAsync(array $args = [])
 * @method \Aws\Result listTagsForResource(array $args = [])
 * @method \GuzzleHttp\Promise\Promise listTagsForResourceAsync(array $args = [])
 * @method \Aws\Result tagResource(array $args = [])
 * @method \GuzzleHttp\Promise\Promise tagResourceAsync(array $args = [])
 * @method \Aws\Result untagResource(array $args = [])
 * @method \GuzzleHttp\Promise\Promise untagResourceAsync(array $args = [])
 * @method \Aws\Result updateDashboard(array $args = [])
 * @method \GuzzleHttp\Promise\Promise updateDashboardAsync(array $args = [])
 */
class Bcm_Dashboards_Client extends Aws_Client
{
}