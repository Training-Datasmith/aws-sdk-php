<?php

declare(strict_types=1);

namespace Aws\CloudSearchDomain;

use Aws\AwsClient;
use Aws\CommandInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;

/**
 * This client is used to search and upload documents to an **Amazon CloudSearch** Domain.
 *
 * @method \Aws\Result search(array $args = [])
 * @method \GuzzleHttp\Promise\Promise searchAsync(array $args = [])
 * @method \Aws\Result suggest(array $args = [])
 * @method \GuzzleHttp\Promise\Promise suggestAsync(array $args = [])
 * @method \Aws\Result uploadDocuments(array $args = [])
 * @method \GuzzleHttp\Promise\Promise uploadDocumentsAsync(array $args = [])
 */
class CloudSearchDomainClient extends AwsClient
{
    public function __construct(array $args)
    {
        parent::__construct($args);
        $list = $this->getHandlerList();
        $list->appendBuild($this->searchByPost(), 'cloudsearchdomain.search_by_POST');
    }

    public static function getArguments()
    {
        $args = parent::getArguments();
        $args['endpoint']['required'] = true;
        $args['region']['default'] =
            // Determine the region from the provided endpoint.
            // (e.g. http://search-blah.{region}.cloudsearch.amazonaws.com)
            (fn (array $args) => explode('.', new Uri($args['endpoint']))[1]);
        unset($args['endpoint']['default']);

        return $args;
    }

    /**
     * Use POST for search command
     *
     * Useful when query string is too long
     */
    private function searchByPost()
    {
        return static fn (callable $handler) => function (
            CommandInterface $c,
            ?RequestInterface $r = null
        ) use ($handler) {
            if ($c->getName() !== 'Search') {
                return $handler($c, $r);
            }
            return $handler($c, self::convertGetToPost($r));
        };
    }

    /**
     * Converts default GET request to a POST request
     *
     * Avoiding length restriction in query
     *
     * @param RequestInterface $r GET request to be converted
     * @return RequestInterface $req converted POST request
     */
    public static function convertGetToPost(RequestInterface $r)
    {
        if ($r->getMethod() === 'POST') {
            return $r;
        }

        $query = $r->getUri()->getQuery();
        return $r->withMethod('POST')
            ->withBody(Psr7\Utils::streamFor($query))
            ->withHeader('Content-Length', strlen((string) $query))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withUri($r->getUri()->withQuery(''));
    }
}
