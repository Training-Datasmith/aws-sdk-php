<?php

namespace Aws\S3\S3Transfer\Models;

use Throwable;

final class UploadDirectoryResult implements \Stringable
{
    public function __construct(private readonly int $objectsUploaded, private readonly int $objectsFailed, private readonly ?Throwable $reason = null)
    {
    }

    public function getObjectsUploaded(): int
    {
        return $this->objectsUploaded;
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
            "UploadDirectoryResult: %d objects uploaded, %d objects failed",
            $this->objectsUploaded,
            $this->objectsFailed
        );
    }
}
