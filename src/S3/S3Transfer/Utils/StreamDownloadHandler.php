<?php

namespace Aws\S3\S3Transfer\Utils;

use Aws\S3\S3Transfer\Progress\AbstractTransferListener;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

final class StreamDownloadHandler extends AbstractDownloadHandler
{
    public function __construct(private ?StreamInterface $stream = null)
    {
    }

    public function transferInitiated(array $context): void
    {
        if (is_null($this->stream)) {
            $this->stream = Utils::streamFor(
                fopen('php://temp', 'w+')
            );
        } else {
            $this->stream->seek($this->stream->getSize());
        }
    }

    /**
     * @inheritDoc
     */
    public function bytesTransferred(array $context): bool
    {
        $snapshot = $context[AbstractTransferListener::PROGRESS_SNAPSHOT_KEY];
        $response = $snapshot->getResponse();
        $partBody = $response['Body'];
        if ($partBody->isSeekable()) {
            $partBody->rewind();
        }

        Utils::copyToStream(
            $partBody,
            $this->stream
        );

        return true;
    }

    public function transferComplete(array $context): void
    {
        $this->stream->rewind();
    }

    public function transferFail(array $context): void
    {
        $this->stream->close();
        $this->stream = null;
    }

    /**
     * @inheritDoc
     */
    public function getHandlerResult(): StreamInterface
    {
        return $this->stream;
    }
}
