<?php
namespace Aws\S3\Crypto;

use Aws\AwsClientInterface;
use Aws\Middleware;
use Psr\Http\Message\RequestInterface;

trait UserAgentTrait
{
    private function appendUserAgent(AwsClientInterface $client, $agentString): void
    {
        $list = $client->getHandlerList();
        $list->appendBuild(Middleware::mapRequest(
            function(RequestInterface $req) use ($agentString) {
                if (!empty($req->getHeader('User-Agent'))
                    && !empty($req->getHeader('User-Agent')[0])
                ) {
                    $userAgent = $req->getHeader('User-Agent')[0];
                    if (!str_contains($userAgent, (string) $agentString)) {
                        $userAgent .= " {$agentString}";
                    };
                } else {
                    $userAgent = $agentString;
                }
                return $req->withHeader('User-Agent', $userAgent);
            }
        ));
    }
}
