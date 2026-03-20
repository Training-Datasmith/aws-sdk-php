<?php

declare (strict_types=1);
namespace Aws\Cloud_Front;

use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Uri;
use Psr\Http\Message\Uri_Interface;
/**
 * Creates signed URLs for Amazon CloudFront resources.
 */
class Url_Signer
{
    private readonly \Aws\Cloud_Front\Signer $signer;
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
     * Create a signed Amazon CloudFront URL.
     *
     * Keep in mind that URLs meant for use in media/flash players may have
     * different requirements for URL formats (e.g. some require that the
     * extension be removed, some require the file name to be prefixed
     * - mp4:<path>, some require you to add "/cfx/st" into your URL).
     *
     * @param string              $url     URL to sign (can include query
     *                                     string string and wildcards)
     * @param string|integer|null $expires UTC Unix timestamp used when signing
     *                                     with a canned policy. Not required
     *                                     when passing a custom $policy.
     * @param string              $policy  JSON policy. Use this option when
     *                                     creating a signed URL for a custom
     *                                     policy.
     *
     * @return string The file URL with authentication parameters
     * @throws \InvalidArgumentException if the URL provided is invalid
     * @link http://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/WorkingWithStreamingDistributions.html
     */
    public function get_signed_url($url, $expires = null, $policy = null)
    {
        // Determine the scheme of the url
        $url_sections = explode('://', $url);
        if (count($url_sections) < 2) {
            throw new \InvalidArgumentException("Invalid URL: {$url}");
        }
        // Get the real scheme by removing wildcards from the scheme
        $scheme = str_replace('*', '', $url_sections[0]);
        $uri = new Uri($scheme . '://' . $url_sections[1]);
        $query = Psr7\Query::parse($uri->get_query(), PHP_QUERY_RFC3986);
        $signature = $this->signer->get_signature($this->create_resource($scheme, (string) $uri), $expires, $policy);
        $uri = $uri->with_query(http_build_query($query + $signature, '', '&', PHP_QUERY_RFC3986));
        return $scheme === 'rtmp' ? $this->create_rtmp_url($uri) : (string) $uri;
    }
    private function create_rtmp_url(Uri_Interface $uri): string
    {
        // Use a relative URL when creating Flash player URLs
        $result = ltrim($uri->get_path(), '/');
        if ($query = $uri->get_query()) {
            $result .= '?' . $query;
        }
        return $result;
    }
    /**
     * @param $scheme
     * @param $url
     *
     * @return string
     */
    private function create_resource(string|array $scheme, $url)
    {
        switch ($scheme) {
            case 'http':
            case 'http*':
            case 'https':
                return $url;
            case 'rtmp':
                $parts = parse_url((string) $url);
                $path_parts = pathinfo($parts['path']);
                $resource = ltrim(str_replace('\\', '/', $path_parts['dirname']) . '/' . $path_parts['basename'], '/');
                // Add a query string if present.
                if (isset($parts['query'])) {
                    $resource .= "?{$parts['query']}";
                }
                return $resource;
        }
        throw new \InvalidArgumentException("Invalid URI scheme: {$scheme}. " . 'Scheme must be one of: http, https, or rtmp');
    }
}