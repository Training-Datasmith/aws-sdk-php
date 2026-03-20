<?php
namespace Aws\Signature;

use Aws\Credentials\CredentialsInterface;
use AWS\CRT\Auth\SignatureType;
use AWS\CRT\Auth\SigningAlgorithm;
use AWS\CRT\Auth\SigningConfigAWS;
use Psr\Http\Message\RequestInterface;

/**
 * Amazon S3 signature version 4 support.
 */
class S3SignatureV4 extends SignatureV4
{
    /**
     * S3-specific signing logic
     *
     * {@inheritdoc}
     */
    use SignatureTrait;

    public function signRequest(
        RequestInterface $request,
        CredentialsInterface $credentials,
        $signingService = null
    ) {
        // Always add a x-amz-content-sha-256 for data integrity
        if (!$request->hasHeader('x-amz-content-sha256')) {
            $request = $request->withHeader(
                'x-amz-content-sha256',
                $this->getPayload($request)
            );
        }
        $useCrt =
            str_contains((string) $request->getUri()->getHost(), "accesspoint.s3-global");
        if (!$useCrt) {
            if (strpos((string) $request->getUri()->getHost(), "s3-object-lambda")) {
                return parent::signRequest($request, $credentials, "s3-object-lambda");
            }
            return parent::signRequest($request, $credentials);
        }
        $signingService = $signingService ?: 's3';
        return $this->signWithV4a($credentials, $request, $signingService);
    }

    /**
     * @param $signingService
     * @return RequestInterface
     *
     * Instantiates a separate sigv4a signing config.  All services except S3
     * use double encoding.  All services except S3 require path normalization.
     */
    protected function signWithV4a(
        CredentialsInterface $credentials,
        RequestInterface $request,
        $signingService,
        ?SigningConfigAWS $signingConfig = null
    ){
        $this->verifyCRTLoaded();
        $credentials_provider = $this->createCRTStaticCredentialsProvider($credentials);
        $signingConfig = new SigningConfigAWS([
            'algorithm' => SigningAlgorithm::SIGv4_ASYMMETRIC,
            'signature_type' => SignatureType::HTTP_REQUEST_HEADERS,
            'credentials_provider' => $credentials_provider,
            'signed_body_value' => $this->getPayload($request),
            'region' => $this->region,
            'should_normalize_uri_path' => false,
            'use_double_uri_encode' => false,
            'service' => $signingService,
            'date' => time(),
        ]);

        return parent::signWithV4a($credentials, $request, $signingService, $signingConfig);
    }

    /**
     * Always add a x-amz-content-sha-256 for data integrity.
     *
     * {@inheritdoc}
     */
    public function presign(
        RequestInterface $request,
        CredentialsInterface $credentials,
        $expires,
        array $options = []
    ) {
        if (!$request->hasHeader('x-amz-content-sha256')) {
            $request = $request->withHeader(
                'X-Amz-Content-Sha256',
                $this->getPresignedPayload($request)
            );
        }

        if (strpos((string) $request->getUri()->getHost(), "accesspoint.s3-global")) {
            $request = $request->withHeader("x-amz-region-set", "*");
        }

        return parent::presign($request, $credentials, $expires, $options);
    }

    /**
     * Override used to allow pre-signed URLs to be created for an
     * in-determinate request payload.
     */
    protected function getPresignedPayload(RequestInterface $request): string
    {
        return SignatureV4::UNSIGNED_PAYLOAD;
    }

    /**
     * Amazon S3 does not double-encode the path component in the canonical request
     */
    protected function createCanonicalizedPath($path): string
    {
        // Only remove one slash in case of keys that have a preceding slash
        if (str_starts_with((string) $path, '/')) {
            $path = substr((string) $path, 1);
        }
        return '/' . $path;
    }
}
