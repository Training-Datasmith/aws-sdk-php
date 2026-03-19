<?php

declare(strict_types=1);

namespace Aws\Credentials;

use Aws\Arn\Arn;
use Aws\Exception\CredentialsException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Credential provider that fetches container credentials with GET request.
 * container environment variables are used in constructing request URI.
 */
class EcsCredentialProvider
{
    public const SERVER_URI = 'http://169.254.170.2';
    public const ENV_URI = 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI';
    public const ENV_FULL_URI = 'AWS_CONTAINER_CREDENTIALS_FULL_URI';
    public const ENV_AUTH_TOKEN = 'AWS_CONTAINER_AUTHORIZATION_TOKEN';
    public const ENV_AUTH_TOKEN_FILE = 'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE';
    public const ENV_TIMEOUT = 'AWS_METADATA_SERVICE_TIMEOUT';
    public const EKS_SERVER_HOST_IPV4 = '169.254.170.23';
    public const EKS_SERVER_HOST_IPV6 = 'fd00:ec2::23';
    public const ENV_RETRIES = 'AWS_METADATA_SERVICE_NUM_ATTEMPTS';

    public const DEFAULT_ENV_TIMEOUT = 1.0;
    public const DEFAULT_ENV_RETRIES = 3;

    /** @var callable */
    private $client;

    /** @var float|mixed */
    private $timeout;

    /** @var int */
    private $retries;

    private ?int $attempts = null;

    /**
     *  The constructor accepts following options:
     *  - timeout: (optional) Connection timeout, in seconds, default 1.0
     *  - retries: Optional number of retries to be attempted, default 3.
     *  - client: An EcsClient to make request from
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->timeout = (float) isset($config['timeout'])
            ? $config['timeout']
            : (getenv(self::ENV_TIMEOUT) ?: self::DEFAULT_ENV_TIMEOUT);
        $this->retries = (int) isset($config['retries'])
            ? $config['retries']
            : ((int) getenv(self::ENV_RETRIES) ?: self::DEFAULT_ENV_RETRIES);

        $this->client = $config['client'] ?? \Aws\default_http_handler();
    }

    /**
     * Load container credentials.
     *
     * @return PromiseInterface
     * @throws GuzzleException
     */
    public function __invoke()
    {
        $this->attempts = 0;
        $uri = $this->getEcsUri();
        if ($this->isCompatibleUri($uri)) {
            return Promise\Coroutine::of(function () {
                $client = $this->client;
                $request = new Request('GET', $this->getEcsUri());
                $headers = $this->getHeadersForAuthToken();
                $credentials = null;
                while ($credentials === null) {
                    $credentials = (yield $client(
                        $request,
                        [
                            'timeout' => $this->timeout,
                            'proxy' => '',
                            'headers' => $headers,
                        ]
                    )->then(function (ResponseInterface $response): \Aws\Credentials\Credentials {
                        $result = $this->decodeResult((string)$response->getBody());
                        if (!isset($result['AccountId']) && isset($result['RoleArn'])) {
                            try {
                                $parsedArn = new Arn($result['RoleArn']);
                                $result['AccountId'] = $parsedArn->getAccountId();
                            } catch (\Exception) {
                                // AccountId will be null
                            }
                        }

                        return new Credentials(
                            $result['AccessKeyId'],
                            $result['SecretAccessKey'],
                            $result['Token'],
                            strtotime((string) $result['Expiration']),
                            $result['AccountId'] ?? null,
                            CredentialSources::ECS
                        );
                    })->otherwise(function ($reason): void {
                        $reason = is_array($reason) ? $reason['exception'] : $reason;

                        $isRetryable = $reason instanceof ConnectException;
                        if ($isRetryable && ($this->attempts < $this->retries)) {
                            sleep((int)1.2 ** $this->attempts);
                        } else {
                            $msg = $reason->getMessage();
                            throw new CredentialsException(
                                sprintf('Error retrieving credentials from container metadata after attempt %d/%d (%s)', $this->attempts, $this->retries, $msg)
                            );
                        }
                    }));
                    $this->attempts++;
                }

                yield $credentials;
            });
        }

        throw new CredentialsException("Uri '{$uri}' contains an unsupported host.");
    }

    /**
     * Returns the number of attempts that have been done.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Retrieves authorization token.
     *
     * @return array|false|string
     */
    private function getEcsAuthToken(): string|false
    {
        if (!empty($path = getenv(self::ENV_AUTH_TOKEN_FILE))) {
            $token =  @file_get_contents($path);
            if (false === $token) {
                clearstatcache(true, dirname($path) . DIRECTORY_SEPARATOR . @readlink($path));
                clearstatcache(true, dirname($path) . DIRECTORY_SEPARATOR . dirname(@readlink($path)));
                clearstatcache(true, $path);
            }

            if (!is_readable($path)) {
                throw new CredentialsException("Failed to read authorization token from '{$path}': no such file or directory.");
            }

            $token = @file_get_contents($path);

            if (empty($token)) {
                throw new CredentialsException("Invalid authorization token read from `$path`. Token file is empty!");
            }

            return $token;
        }

        return getenv(self::ENV_AUTH_TOKEN);
    }

    /**
     * Provides headers for credential metadata request.
     *
     * @return array|array[]|string[]
     */
    private function getHeadersForAuthToken(): array
    {
        $authToken = self::getEcsAuthToken();

        if (!empty($authToken)) {
            return ['Authorization' => $authToken];
        }

        return [];
    }

    /** @deprecated
     * @return mixed[] */
    public function setHeaderForAuthToken(): array
    {
        $authToken = self::getEcsAuthToken();
        if (!empty($authToken)) {
            return ['Authorization' => $authToken];
        }

        return [];
    }

    /**
     * Fetch container metadata URI from container environment variable.
     *
     * @return string Returns container metadata URI
     */
    private function getEcsUri()
    {
        $credsUri = getenv(self::ENV_URI);

        if ($credsUri === false) {
            $credsUri = $_SERVER[self::ENV_URI] ?? '';
        }

        if (empty($credsUri)) {
            $credFullUri = getenv(self::ENV_FULL_URI);
            if ($credFullUri === false) {
                $credFullUri = $_SERVER[self::ENV_FULL_URI] ?? '';
            }

            if (!empty($credFullUri)) {
                return $credFullUri;
            }
        }

        return self::SERVER_URI . $credsUri;
    }

    private function decodeResult(string $response)
    {
        $result = json_decode($response, true);

        if (!isset($result['AccessKeyId'])) {
            throw new CredentialsException('Unexpected container metadata credentials value');
        }
        return $result;
    }

    /**
     * Determines whether or not a given request URI is a valid
     * container credential request URI.
     *
     * @param $uri
     */
    private function isCompatibleUri($uri): bool
    {
        $parsed = parse_url((string) $uri);

        if ($parsed['scheme'] !== 'https') {
            $host = trim($parsed['host'], '[]');
            $ecsHost = parse_url(self::SERVER_URI)['host'];
            $eksHost = self::EKS_SERVER_HOST_IPV4;

            if ($host !== $ecsHost
                && $host !== $eksHost
                && $host !== self::EKS_SERVER_HOST_IPV6
                && !CredentialsUtils::isLoopBackAddress(gethostbyname($host))
            ) {
                return false;
            }
        }

        return true;
    }
}
