<?php

namespace Aws\S3\S3Transfer\Models;

use Throwable;

final class DownloadDirectoryResult implements \Stringable
{
    public function __construct(private readonly int $objectsDownloaded, private readonly int $objectsFailed, private readonly ?Throwable $reason = null)
    {
    }

    public function getObjectsDownloaded(): int
    {
        return $this->objectsDownloaded;
    }

    public function getObjectsFailed(): int
    {
        return $this->objectsFailed;
    }

    public function getReason(): ?Throwable
    {
        return $this->reason;
    }

    public function __toString(): string
    {
        return sprintf(
            "DownloadDirectoryResult: %d objects downloaded, %d objects failed",
            $this->objectsDownloaded,
            $this->objectsFailed
        );
    }
}
