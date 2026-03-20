<?php

declare (strict_types=1);
namespace Aws\Cloud_Front;

class Cookie_Signer
{
    private readonly \Aws\Cloud_Front\Signer $signer;
    private static array $schemes = ['http' => true, 'https' => true];
    /**
     * @param $keyPairId  string ID of the key pair
     * @param $privateKey string Path to the private key used for signing
     *
     * @throws \RuntimeException if the openssl extension is missing
     * @throws \InvalidArgumentException if the private key cannot be found.
     */
    public function __construct($key_pair_id, $private_key)
    {
        $this->signer = new Signer($key_pair_id, $private_key);
    }
    /**
     * Create a signed Amazon CloudFront Cookie.
     *
     * @param string              $url     URL to sign (can include query string
     *                                     and wildcards). Not required
     *                                     when passing a custom $policy.
     * @param string|integer|null $expires UTC Unix timestamp used when signing
     *                                     with a canned policy. Not required
     *                                     when passing a custom $policy.
     * @param string              $policy  JSON policy. Use this option when
     *                                     creating a signed cookie for a custom
     *                                     policy.
     *
     * @return array The authenticated cookie parameters
     * @throws \InvalidArgumentException if the URL provided is invalid
     * @link http://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-signed-cookies.html
     */
    public function get_signed_cookie($url = null, $expires = null, $policy = null): array
    {
        if ($url) {
            $this->validate_url($url);
        }
        $cookie_parameters = [];
        $signature = $this->signer->get_signature($url, $expires, $policy);
        foreach ($signature as $key => $value) {
            $cookie_parameters["CloudFront-{$key}"] = $value;
        }
        return $cookie_parameters;
    }
    private function validate_url($url): void
    {
        $scheme = str_replace('*', '', explode('://', (string) $url)[0]);
        if (empty(self::$schemes[strtolower($scheme)])) {
            throw new \InvalidArgumentException('Invalid or missing URI scheme');
        }
    }
}