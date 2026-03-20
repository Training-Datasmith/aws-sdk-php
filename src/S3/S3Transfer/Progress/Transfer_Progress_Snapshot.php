<?php

namespace Aws\S3\S3Transfer\Progress;

use Throwable;

final class TransferProgressSnapshot
{
    public function __construct(private readonly string $identifier, private readonly int $transferredBytes, private readonly int $totalBytes, private readonly array|null $response = null, private readonly Throwable|string|null $reason = null)
    {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getTransferredBytes(): int
    {
        return $this->transferredBytes;
    }

    public function getTotalBytes(): int
    {
        return $this->totalBytes;
    }

    public function getResponse(): array|null
    {
        return $this->response;
    }

    public function ratioTransferred(): float
    {
        if ($this->totalBytes === 0) {
            // Unable to calculate ratio
            return 0;
        }

        return $this->transferredBytes / $this->totalBytes;
    }

    public function getReason(): Throwable|string|null
    {
        return $this->reason;
    }
}
