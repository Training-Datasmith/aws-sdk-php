<?php

declare (strict_types=1);
namespace Aws\Credentials;

use Aws\Exception\Aws_Exception;
use Aws\Exception\Credentials_Exception;
use Aws\Sts\Sts_Client;
use Guzzle_Http\Promise;
/**
 * Credential provider that provides credentials via assuming a role with a web identity
 * More Information, see: https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sts-2011-06-15.html#assumerolewithwebidentity
 */
class Assume_Role_With_Web_Identity_Credential_Provider
{
    public const ERROR_MSG = "Missing required 'AssumeRoleWithWebIdentityCredentialProvider' configuration option: ";
    public const ENV_RETRIES = 'AWS_METADATA_SERVICE_NUM_ATTEMPTS';
    /** @var string */
    private $token_file;
    /** @var string */
    private $arn;
    /** @var string */
    private $session;
    /** @var StsClient */
    private $client;
    /** @var integer */
    private $retries;
    private int $authentication_attempts;
    private int $token_file_read_attempts;
    /** @var string */
    private $source;
    /**
     * The constructor attempts to load config from environment variables.
     * If not set, the following config options are used:
     *  - WebIdentityTokenFile: full path of token filename
     *  - RoleArn: arn of role to be assumed
     *  - SessionName: (optional) set by SDK if not provided
     *  - source: To identify if the provider was sourced by a profile or
     *    from environment definition. Default will be `sts_web_id_token`.
     *
     * @param array $config Configuration options
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config = [])
    {
        if (!isset($config['RoleArn'])) {
            throw new \InvalidArgumentException(self::ERROR_MSG . "'RoleArn'.");
        }
        $this->arn = $config['RoleArn'];
        if (!isset($config['WebIdentityTokenFile'])) {
            throw new \InvalidArgumentException(self::ERROR_MSG . "'WebIdentityTokenFile'.");
        }
        $this->token_file = $config['WebIdentityTokenFile'];
        if (!preg_match("/^\\w\\:|^\\/|^\\\\/", $this->token_file)) {
            throw new \InvalidArgumentException("'WebIdentityTokenFile' must be an absolute path.");
        }
        $this->retries = (int) getenv(self::ENV_RETRIES) ?: $config['retries'] ?? 3;
        $this->authentication_attempts = 0;
        $this->token_file_read_attempts = 0;
        $this->session = $config['SessionName'] ?? 'aws-sdk-php-' . round(microtime(true) * 1000);
        if (isset($config['client'])) {
            $this->client = $config['client'];
        } else {
            $region = $config['region'] ?? get_env(Credential_Provider::ENV_REGION) ?: null;
            $this->client = $this->create_default_sts_client($region);
        }
        $this->source = $config['source'] ?? Credential_Sources::STS_WEB_ID_TOKEN;
    }
    /**
     * Loads assume role with web identity credentials.
     *
     * @return Promise\PromiseInterface
     */
    public function __invoke()
    {
        return Promise\Coroutine::of(function () {
            $client = $this->client;
            $result = null;
            while ($result == null) {
                try {
                    $token = @file_get_contents($this->token_file);
                    if (false === $token) {
                        clearstatcache(true, dirname($this->token_file) . '/' . readlink($this->token_file));
                        clearstatcache(true, dirname($this->token_file) . '/' . dirname(readlink($this->token_file)));
                        clearstatcache(true, $this->token_file);
                        if (!@is_readable($this->token_file)) {
                            throw new Credentials_Exception("Unreadable tokenfile at location {$this->token_file}");
                        }
                        $token = @file_get_contents($this->token_file);
                    }
                    if (empty($token)) {
                        if ($this->token_file_read_attempts < $this->retries) {
                            sleep((int) 1.2 ** $this->token_file_read_attempts);
                            $this->token_file_read_attempts++;
                            continue;
                        }
                        throw new Credentials_Exception("InvalidIdentityToken from file: {$this->token_file}");
                    }
                } catch (\Exception $exception) {
                    throw new Credentials_Exception('Error reading WebIdentityTokenFile from ' . $this->token_file, 0, $exception);
                }
                $assume_params = ['RoleArn' => $this->arn, 'RoleSessionName' => $this->session, 'WebIdentityToken' => $token];
                try {
                    $result = $client->assume_role_with_web_identity($assume_params);
                } catch (Aws_Exception $e) {
                    if ($e->get_aws_error_code() == 'InvalidIdentityToken') {
                        if ($this->authentication_attempts < $this->retries) {
                            sleep((int) 1.2 ** $this->authentication_attempts);
                        } else {
                            throw new Credentials_Exception('InvalidIdentityToken, retries exhausted');
                        }
                    } else {
                        throw new Credentials_Exception('Error assuming role from web identity credentials', 0, $e);
                    }
                } catch (\Exception $e) {
                    throw new Credentials_Exception('Error retrieving web identity credentials: ' . $e->get_message() . ' (' . $e->get_code() . ')');
                }
                $this->authentication_attempts++;
            }
            yield $this->client->create_credentials($result, $this->source);
        });
    }
    private function create_default_sts_client(?string $region): Sts_Client
    {
        if (empty($region)) {
            $region = Credential_Provider::FALLBACK_REGION;
            trigger_error('NOTICE: STS client created without explicit `region` configuration.' . PHP_EOL . "Defaulting to {$region}. This fallback behavior may be removed." . PHP_EOL . 'To avoid potential disruptions, configure a region using one of the following methods:' . PHP_EOL . '(1) Pass `region` in the `$config` array when calling the provider,' . PHP_EOL . '(2) Set the `AWS_REGION` environment variable.' . PHP_EOL . 'OR provide an STS client in the `$config` array when creating the provider as `client`.' . PHP_EOL . 'See: https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/assume-role-with-web-identity-provider.html' . PHP_EOL, E_USER_NOTICE);
        }
        return new Sts_Client(['credentials' => false, 'region' => $region]);
    }
}