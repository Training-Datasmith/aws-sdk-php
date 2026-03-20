<?php
namespace Aws\S3;

use Aws\CommandInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Simplifies the SSE-C process by encoding and hashing the key.
 * @internal
 */
class SSECMiddleware
{
    private $nextHandler;

    /**
     * Provide the URI scheme of the client sending requests.
     *
     * @param string $endpointScheme URI scheme (http/https).
     *
     * @return callable
     */
    public static function wrap($endpointScheme)
    {
        return fn(callable $handler) => new self($endpointScheme, $handler);
    }

    public function __construct(private $endpointScheme, callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    public function __invoke(
        CommandInterface $command,
        ?RequestInterface $request = null
    ) {
        // Allows only HTTPS connections when using SSE-C
        if (($command['SSECustomerKey'] || $command['CopySourceSSECustomerKey'])
            && $this->endpointScheme !== 'https'
        ) {
            throw new \RuntimeException('You must configure your S3 client to '
                . 'use HTTPS in order to use the SSE-C features.');
        }

        // Prepare the normal SSE-CPK headers
        if ($command['SSECustomerKey']) {
            $this->prepareSseParams($command);
        }

        // If it's a copy operation, prepare the SSE-CPK headers for the source.
        if ($command['CopySourceSSECustomerKey']) {
            $this->prepareSseParams($command, 'CopySource');
        }

        $f = $this->nextHandler;
        return $f($command, $request);
    }

    private function prepareSseParams(CommandInterface $command, string $prefix = ''): void
    {
        // Base64 encode the provided key
        $key = $command[$prefix . 'SSECustomerKey'];
        $command[$prefix . 'SSECustomerKey'] = base64_encode((string) $key);

        // Base64 the provided MD5 or, generate an MD5 if not provided
        if ($md5 = $command[$prefix . 'SSECustomerKeyMD5']) {
            $command[$prefix . 'SSECustomerKeyMD5'] = base64_encode((string) $md5);
        } else {
            $command[$prefix . 'SSECustomerKeyMD5'] = base64_encode(md5((string) $key, true));
        }
    }
}
