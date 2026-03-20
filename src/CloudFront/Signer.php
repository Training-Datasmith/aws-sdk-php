<?php

declare (strict_types=1);
namespace Aws\Cloud_Front;

/**
 * @internal
 */
class Signer
{
    private \Open_Ssl_Asymmetric_Key|bool|null $pk_handle = null;
    /**
     * A signer for creating the signature values used in CloudFront signed URLs
     * and signed cookies.
     *
     * @param $keyPairId  string ID of the key pair
     * @param $privateKey string Path to the private key used for signing
     * @param $passphrase string Passphrase to private key file, if one exists
     *
     * @throws \RuntimeException if the openssl extension is missing
     * @throws \InvalidArgumentException if the private key cannot be found.
     */
    public function __construct(private $key_pair_id, $private_key, $passphrase = '')
    {
        if (!extension_loaded('openssl')) {
            //@codeCoverageIgnoreStart
            throw new \RuntimeException('The openssl extension is required to ' . 'sign CloudFront urls.');
            //@codeCoverageIgnoreEnd
        }
        if (!$this->pk_handle = openssl_pkey_get_private($private_key, $passphrase)) {
            if (!file_exists($private_key)) {
                throw new \InvalidArgumentException("PK file not found: {$private_key}");
            }
            $this->pk_handle = openssl_pkey_get_private("file://{$private_key}", $passphrase);
            if (!$this->pk_handle) {
                $error_messages = [];
                while (($new_message = openssl_error_string()) !== false) {
                    $error_messages[] = $new_message;
                }
                throw new \InvalidArgumentException(implode("\n", $error_messages));
            }
        }
    }
    public function __destruct()
    {
        if (PHP_MAJOR_VERSION < 8) {
            $this->pk_handle && openssl_pkey_free($this->pk_handle);
        }
    }
    /**
     * Create the values used to construct signed URLs and cookies.
     *
     * @param string              $resource     The CloudFront resource to which
     *                                          this signature will grant access.
     *                                          Not used when a custom policy is
     *                                          provided.
     * @param string|integer|null $expires      UTC Unix timestamp used when
     *                                          signing with a canned policy.
     *                                          Not required when passing a
     *                                          custom $policy.
     * @param string              $policy       JSON policy. Use this option when
     *                                          creating a signature for a custom
     *                                          policy.
     *
     * @return array The values needed to construct a signed URL or cookie
     * @throws \InvalidArgumentException  when not provided either a policy or a
     *                                    resource and a expires
     * @throws \RuntimeException when generated signature is empty
     *
     * @link http://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-signed-cookies.html
     */
    public function get_signature($resource = null, $expires = null, $policy = null): array
    {
        $signature_hash = [];
        if ($policy) {
            $policy = preg_replace('/\s/s', '', $policy);
            self::validate_policy($policy);
            $signature_hash['Policy'] = $this->encode($policy);
        } elseif ($resource && $expires) {
            self::validate_resource_url($resource);
            $expires = (int) $expires;
            // Handle epoch passed as string
            $policy = $this->create_canned_policy($resource, $expires);
            $signature_hash['Expires'] = $expires;
        } else {
            throw new \InvalidArgumentException('Either a policy or a resource' . ' and an expiration time must be provided.');
        }
        $signature_hash['Signature'] = $this->encode($this->sign($policy));
        $signature_hash['Key-Pair-Id'] = $this->key_pair_id;
        return $signature_hash;
    }
    private function create_canned_policy($resource, int $expiration)
    {
        return json_encode(['Statement' => [['Resource' => $resource, 'Condition' => ['DateLessThan' => ['AWS:EpochTime' => $expiration]]]]], JSON_UNESCAPED_SLASHES);
    }
    private function sign($policy)
    {
        $signature = '';
        if (!openssl_sign($policy, $signature, $this->pk_handle)) {
            $error_messages = [];
            while (($new_message = openssl_error_string()) !== false) {
                $error_messages[] = $new_message;
            }
            $exception_message = 'An error has occurred when signing the policy';
            if (count($error_messages) > 0) {
                $exception_message = implode("\n", $error_messages);
            }
            throw new \RuntimeException($exception_message);
        }
        return $signature;
    }
    private function encode($policy): string
    {
        return strtr(base64_encode((string) $policy), '+=/', '-_~');
    }
    /**
     * Validates a customer provided json document.
     *
     *
     */
    private static function validate_policy(string $json_policy): void
    {
        $policy = json_decode($json_policy, true);
        foreach ($policy['Statement'] ?? [] as $statement) {
            if (isset($statement['Resource'])) {
                self::validate_resource_url($statement['Resource']);
            }
        }
    }
    private static function validate_resource_url(string $url): void
    {
        if (preg_match('/["\\\\\\x00-\x1F]/', $url)) {
            throw new \InvalidArgumentException('URL contains invalid characters: ", \, or control characters');
        }
    }
}