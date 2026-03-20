<?php

declare (strict_types=1);
namespace Aws\Cognito_Identity;

use Aws\Credentials\Credentials;
use Guzzle_Http\Promise;
class Cognito_Identity_Provider
{
    private readonly \Aws\Cognito_Identity\Cognito_Identity_Client $client;
    /**
     * @param string $poolId
     * @param string|null $accountId
     */
    public function __construct(private $identity_pool_id, array $client_options, private array $logins = [], private $account_id = null)
    {
        $this->client = new Cognito_Identity_Client($client_options + ['credentials' => false]);
    }
    public function __invoke()
    {
        return Promise\Coroutine::of(function () {
            $params = $this->logins ? ['Logins' => $this->logins] : [];
            $get_id_params = $params + ['IdentityPoolId' => $this->identity_pool_id];
            if ($this->account_id) {
                $get_id_params['AccountId'] = $this->account_id;
            }
            $id = yield $this->client->get_id($get_id_params);
            $result = yield $this->client->get_credentials_for_identity(['IdentityId' => $id['IdentityId']] + $params);
            yield new Credentials($result['Credentials']['AccessKeyId'], $result['Credentials']['SecretKey'], $result['Credentials']['SessionToken'], (int) $result['Credentials']['Expiration']->format('U'));
        });
    }
    public function update_login($key, $value): static
    {
        $this->logins[$key] = $value;
        return $this;
    }
}