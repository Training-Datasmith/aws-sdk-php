<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Aws\Result_Interface;
use Guzzle_Http\Psr7\Caching_Stream;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal
 */
abstract class Abstract_Parser
{
    /** @var callable */
    protected $parser;
    /**
     * @param Service $api Service description.
     */
    public function __construct(protected \Aws\Api\Service $api)
    {
    }
    /**
     * @param CommandInterface  $command  Command that was executed.
     * @param ResponseInterface $response Response that was received.
     *
     * @return ResultInterface
     */
    abstract public function __invoke(Command_Interface $command, Response_Interface $response);
    abstract public function parse_member_from_stream(Stream_Interface $stream, Structure_Shape $member, $response);
    public static function get_body_contents(Response_Interface $response): string
    {
        $body = $response->get_body();
        if ($body->is_seekable()) {
            $body->rewind();
        }
        return $body->get_contents();
    }
    public static function get_response_with_caching_stream(Response_Interface $response): Response_Interface
    {
        if (!$response->get_body()->is_seekable()) {
            return $response->with_body(new Caching_Stream($response->get_body()));
        }
        return $response;
    }
}