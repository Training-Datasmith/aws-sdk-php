<?php

namespace Aws\S3\S3Transfer\Utils;

use Aws\S3\S3Transfer\Exception\FileDownloadException;
use Aws\S3\S3Transfer\Progress\AbstractTransferListener;

final class FileDownloadHandler extends AbstractDownloadHandler
{
    private const IDENTIFIER_LENGTH = 8;
    private const TEMP_INFIX = '.s3tmp.';

    private string $temporaryDestination;

    public function __construct(
        private readonly string $destination,
        private readonly bool $failsWhenDestinationExists
    ) {
        $this->temporaryDestination = "";
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function isFailsWhenDestinationExists(): bool
    {
        return $this->failsWhenDestinationExists;
    }

    public function transferInitiated(array $context): void
    {
        if ($this->failsWhenDestinationExists && file_exists($this->destination)) {
            throw new FileDownloadException(
                "The destination '$this->destination' already exists."
            );
        }
        if (is_dir($this->destination)) {
            throw new FileDownloadException(
                "The destination '$this->destination' can't be a directory."
            );
        }

        // Create directory if necessary
        $directory = dirname($this->destination);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $uniqueId = self::getUniqueIdentifier();
        $temporaryName = $this->destination . self::TEMP_INFIX . $uniqueId;
        while (file_exists($temporaryName)) {
            $uniqueId = self::getUniqueIdentifier();
            $temporaryName = $this->destination . self::TEMP_INFIX . $uniqueId;
        }

        // Create the file
        file_put_contents($temporaryName, "");
        $this->temporaryDestination = $temporaryName;
    }

    public function bytesTransferred(array $context): bool
    {
        $snapshot = $context[AbstractTransferListener::PROGRESS_SNAPSHOT_KEY];
        $response = $snapshot->getResponse();
        $partBody = $response['Body'];
        if ($partBody->isSeekable()) {
            $partBody->rewind();
        }

        file_put_contents(
            $this->temporaryDestination,
            $partBody,
            FILE_APPEND
        );

        return true;
    }

    public function transferComplete(array $context): void
    {
        // Make sure the file is deleted if exists
        if (file_exists($this->destination) && is_file($this->destination)) {
            if ($this->failsWhenDestinationExists) {
                throw new FileDownloadException(
                    "The destination '$this->destination' already exists."
                );
            }
            unlink($this->destination);
        }

        if (!rename($this->temporaryDestination, $this->destination)) {
            throw new FileDownloadException(
                "Unable to rename the file `$this->temporaryDestination` to `$this->destination`."
            );
        }
    }

    public function transferFail(array $context): void
    {
        if (file_exists($this->temporaryDestination)) {
            unlink($this->temporaryDestination);
        } elseif (file_exists($this->destination)
            && !str_contains(
                (string) $context[self::REASON_KEY],
                "The destination '$this->destination' already exists.")
        ) {
            unlink($this->destination);
        }
    }

    private static function getUniqueIdentifier(): string
    {
        $uniqueId = uniqid();
        if (strlen($uniqueId) > self::IDENTIFIER_LENGTH) {
            return substr($uniqueId, 0, self::IDENTIFIER_LENGTH);
        }

        return str_pad($uniqueId, self::IDENTIFIER_LENGTH, "0");
    }

    public function getHandlerResult(): string
    {
        return $this->destination;
    }
}
