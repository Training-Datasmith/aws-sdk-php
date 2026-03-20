<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Aws\Exception\Aws_Exception;
use Guzzle_Http\Psr7;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Decorates a parser and validates the x-amz-crc32 header.
 */
class Crc32validating_Parser extends Abstract_Parser
{
    /**
     * @param callable $parser Parser to wrap.
     */
    public function __construct(callable $parser)
    {
        $this->parser = $parser;
    }
    public function __invoke(Command_Interface $command, Response_Interface $response)
    {
        if ($expected = $response->get_header_line('x-amz-crc32')) {
            $hash = hexdec(Psr7\Utils::hash($response->get_body(), 'crc32b'));
            if ($expected != $hash) {
                throw new Aws_Exception("crc32 mismatch. Expected {$expected}, found {$hash}.", $command, ['code' => 'ClientChecksumMismatch', 'connection_error' => true, 'response' => $response]);
            }
        }
        $fn = $this->parser;
        return $fn($command, $response);
    }
    public function parse_member_from_stream(Stream_Interface $stream, Structure_Shape $member, $response)
    {
        return $this->parser->parse_member_from_stream($stream, $member, $response);
    }
}