<?php

declare (strict_types=1);
namespace Aws\Credentials;

use Aws\Identity\Aws_Credential_Identity;
/**
 * Basic implementation of the AWS Credentials interface that allows callers to
 * pass in the AWS Access Key and AWS Secret Access Key in the constructor.
 */
class Credentials extends Aws_Credential_Identity implements Credentials_Interface, \Serializable
{
    private string $key;
    private string $secret;
    private $source;
    /**
     * Constructs a new BasicAWSCredentials object, with the specified AWS
     * access key and AWS secret key
     *
     * @param string $key     AWS access key ID
     * @param string $secret  AWS secret access key
     * @param string $token   Security token to use
     * @param int    $expires UNIX timestamp for when credentials expire
     */
    public function __construct($key, $secret, private $token = null, private $expires = null, private $account_id = null, $source = Credential_Sources::STATIC)
    {
        $this->key = trim((string) $key);
        $this->secret = trim((string) $secret);
        $this->source = $source ?? Credential_Sources::STATIC;
    }
    public static function __set_state(array $state)
    {
        return new self($state['key'], $state['secret'], $state['token'], $state['expires'], $state['accountId'], $state['source'] ?? null);
    }
    public function get_access_key_id()
    {
        return $this->key;
    }
    public function get_secret_key()
    {
        return $this->secret;
    }
    public function get_security_token()
    {
        return $this->token;
    }
    public function get_expiration()
    {
        return $this->expires;
    }
    public function is_expired(): bool
    {
        return $this->expires !== null && time() >= $this->expires;
    }
    public function get_account_id()
    {
        return $this->account_id;
    }
    public function get_source()
    {
        return $this->source;
    }
    public function to_array(): array
    {
        return ['key' => $this->key, 'secret' => $this->secret, 'token' => $this->token, 'expires' => $this->expires, 'accountId' => $this->account_id, 'source' => $this->source];
    }
    public function serialize()
    {
        return json_encode($this->__serialize());
    }
    public function unserialize($serialized): void
    {
        $data = json_decode($serialized, true);
        $this->__unserialize($data);
    }
    public function __serialize()
    {
        return $this->to_array();
    }
    public function __unserialize(array $data)
    {
        $this->key = $data['key'];
        $this->secret = $data['secret'];
        $this->token = $data['token'];
        $this->expires = $data['expires'];
        $this->account_id = $data['accountId'] ?? null;
        $this->source = $data['source'] ?? null;
    }
    /**
     * Internal-only. Used when IMDS is unreachable
     * or returns expires credentials.
     *
     * @internal
     */
    public function extend_expiration(): void
    {
        $extension = mt_rand(5, 10);
        $this->expires = time() + $extension * 60;
        $message = <<<EOT
        Attempting credential expiration extension due to a credential service 
        availability issue. A refresh of these credentials will be attempted again 
        after {$extension} minutes.
        
        EOT;
        trigger_error($message, E_USER_WARNING);
    }
}