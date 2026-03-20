<?php

namespace Aws\S3\S3Transfer\Models;

use Aws\S3\S3Transfer\Utils\FileDownloadHandler;

final class DownloadFileRequest
{
    private readonly DownloadRequest $downloadRequest;

    public function __construct(
        private readonly string $destination,
        /**
         * To decide whether an error should be raised
         * if the destination file exists.
         */
        private readonly bool $failsWhenDestinationExists,
        DownloadRequest $downloadRequest
    ) {
        $this->downloadRequest = DownloadRequest::fromDownloadRequestAndDownloadHandler(
            $downloadRequest,
            new FileDownloadHandler(
                $this->destination,
                $this->failsWhenDestinationExists
            )
        );
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function isFailsWhenDestinationExists(): bool
    {
        return $this->failsWhenDestinationExists;
    }

    public function getDownloadRequest(): DownloadRequest
    {
        return $this->downloadRequest;
    }
}
