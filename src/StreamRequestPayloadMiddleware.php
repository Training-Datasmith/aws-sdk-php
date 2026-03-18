<?php
namespace Aws;

use Aws\Api\Service;
use Aws\Exception\IncalculablePayloadException;
use Psr\Http\Message\RequestInterface;

/**
 * @internal
 */
class StreamRequestPayloadMiddleware
{
    private $nextHandler;

    /**
     * Create a middleware wrapper function
     *
     * @return \Closure
     */
    public static function wrap(Service $service)
    {
        return fn(callable $handler) => new self($handler, $service);
    }

    public function __construct(callable $nextHandler, private readonly Service $service)
    {
        $this->nextHandler = $nextHandler;
    }

    public function __invoke(CommandInterface $command, RequestInterface $request)
    {
        $nextHandler = $this->nextHandler;

        $operation = $this->service->getOperation($command->getName());
        $contentLength = $request->getHeader('content-length');
        $hasStreaming = false;
        $requiresLength = false;

        // Check if any present input member is a stream and requires the
        // content length
        foreach ($operation->getInput()->getMembers() as $name => $member) {
            if (!empty($member['streaming']) && isset($command[$name])) {
                $hasStreaming = true;
                if (!empty($member['requiresLength'])) {
                    $requiresLength = true;
                }
            }
        }

        if ($hasStreaming) {

            // Add 'transfer-encoding' header if payload size not required to
            // to be calculated and not already known
            if (empty($requiresLength)
                && empty($contentLength)
                && isset($operation['authtype'])
                && $operation['authtype'] == 'v4-unsigned-body'
            ) {
                $request = $request->withHeader('transfer-encoding', 'chunked');

            // Otherwise, make sure 'content-length' header is added
            } else {
                if (empty($contentLength)) {
                    $size = $request->getBody()->getSize();
                    if (is_null($size)) {
                        throw new IncalculablePayloadException('Payload'
                            . ' content length is required and can not be'
                            . ' calculated.');
                    }
                    $request = $request->withHeader(
                        'Content-Length',
                        $size
                    );
                }
            }
        }

        return $nextHandler($command, $request);
    }
}
