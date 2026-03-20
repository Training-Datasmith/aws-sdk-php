<?php

declare (strict_types=1);
namespace Aws\Credentials;

use Aws\Exception\Credentials_Exception;
use Aws\Result;
use Aws\Sts\Sts_Client;
use Guzzle_Http\Promise\Promise_Interface;
/**
 * Credential provider that provides credentials via assuming a role
 * More Information, see: http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sts-2011-06-15.html#assumerole
 */
class Assume_Role_Credential_Provider
{
    public const ERROR_MSG = "Missing required 'AssumeRoleCredentialProvider' configuration option: ";
    /** @var StsClient */
    private $client;
    /** @var array */
    private $assume_role_params;
    /**
     * The constructor requires following configure parameters:
     *  - client: a StsClient
     *  - assume_role_params: Parameters used to make assumeRole call
     *
     * @param array $config Configuration options
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config = [])
    {
        if (!isset($config['assume_role_params'])) {
            throw new \InvalidArgumentException(self::ERROR_MSG . "'assume_role_params'.");
        }
        if (!isset($config['client'])) {
            throw new \InvalidArgumentException(self::ERROR_MSG . "'client'.");
        }
        $this->client = $config['client'];
        $this->assume_role_params = $config['assume_role_params'];
    }
    /**
     * Loads assume role credentials.
     *
     * @return PromiseInterface
     */
    public function __invoke()
    {
        $client = $this->client;
        return $client->assume_role_async($this->assume_role_params)->then(fn(Result $result) => $this->client->create_credentials($result, Credential_Sources::STS_ASSUME_ROLE))->otherwise(function (\RuntimeException $exception): void {
            throw new Credentials_Exception('Error in retrieving assume role credentials.', 0, $exception);
        });
    }
}