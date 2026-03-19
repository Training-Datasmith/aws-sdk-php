<?php

declare(strict_types=1);

namespace Aws\CognitoIdentity;

use Aws\Credentials\Credentials;
use GuzzleHttp\Promise;

class CognitoIdentityProvider
{
    private readonly \Aws\CognitoIdentity\CognitoIdentityClient $client;

    /**
     * @param string $poolId
     * @param string|null $accountId
     */
    public function __construct(
        private $identityPoolId,
        array $clientOptions,
        private array $logins = [],
        private $accountId = null
    ) {
        $this->client = new CognitoIdentityClient($clientOptions + [
            'credentials' => false,
        ]);
    }

    public function __invoke()
    {
        return Promise\Coroutine::of(function () {
            $params = $this->logins ? ['Logins' => $this->logins] : [];
            $getIdParams = $params + ['IdentityPoolId' => $this->identityPoolId];
            if ($this->accountId) {
                $getIdParams['AccountId'] = $this->accountId;
            }

            $id = (yield $this->client->getId($getIdParams));
            $result = (yield $this->client->getCredentialsForIdentity([
                'IdentityId' => $id['IdentityId'],
            ] + $params));

            yield new Credentials(
                $result['Credentials']['AccessKeyId'],
                $result['Credentials']['SecretKey'],
                $result['Credentials']['SessionToken'],
                (int) $result['Credentials']['Expiration']->format('U')
            );
        });
    }

    public function updateLogin($key, $value): static
    {
        $this->logins[$key] = $value;

        return $this;
    }
}
