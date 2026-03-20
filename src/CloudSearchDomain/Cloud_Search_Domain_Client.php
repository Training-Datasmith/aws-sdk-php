<?php

declare (strict_types=1);
namespace Aws\Cloud_Search_Domain;

use Aws\Aws_Client;
use Aws\Command_Interface;
use Guzzle_Http\Psr7;
use Guzzle_Http\Psr7\Uri;
use Psr\Http\Message\Request_Interface;
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
class Cloud_Search_Domain_Client extends Aws_Client
{
    public function __construct(array $args)
    {
        parent::__construct($args);
        $list = $this->get_handler_list();
        $list->append_build($this->search_by_post(), 'cloudsearchdomain.search_by_POST');
    }
    public static function get_arguments()
    {
        $args = parent::get_arguments();
        $args['endpoint']['required'] = true;
        $args['region']['default'] = fn(array $args) => explode('.', new Uri($args['endpoint']))[1];
        unset($args['endpoint']['default']);
        return $args;
    }
    /**
     * Use POST for search command
     *
     * Useful when query string is too long
     */
    private function search_by_post()
    {
        return static fn(callable $handler) => function (Command_Interface $c, ?Request_Interface $r = null) use ($handler) {
            if ($c->get_name() !== 'Search') {
                return $handler($c, $r);
            }
            return $handler($c, self::convert_get_to_post($r));
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
    public static function convert_get_to_post(Request_Interface $r)
    {
        if ($r->get_method() === 'POST') {
            return $r;
        }
        $query = $r->get_uri()->get_query();
        return $r->with_method('POST')->with_body(Psr7\Utils::stream_for($query))->with_header('Content-Length', strlen((string) $query))->with_header('Content-Type', 'application/x-www-form-urlencoded')->with_uri($r->get_uri()->with_query(''));
    }
}