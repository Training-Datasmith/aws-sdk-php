<?php

namespace Aws\S3\S3Transfer;

use Aws\CommandInterface;
use Aws\CommandPool;
use Aws\ResultInterface;
use Aws\S3\S3ClientInterface;
use Aws\S3\S3Transfer\Exception\S3TransferException;
use Aws\S3\S3Transfer\Models\S3TransferManagerConfig;
use Aws\S3\S3Transfer\Progress\AbstractTransferListener;
use Aws\S3\S3Transfer\Progress\TransferListenerNotifier;
use Aws\S3\S3Transfer\Progress\TransferProgressSnapshot;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\PromisorInterface;
use Throwable;

/**
 * Abstract base class for multipart operations
 */
abstract class AbstractMultipartUploader implements PromisorInterface
{
    public const PART_MIN_SIZE = 5 * 1024 * 1024; // 5 MiB
    public const PART_MAX_SIZE = 5 * 1024 * 1024 * 1024; // 5 GiB
    public const PART_MAX_NUM = 10000;

    protected readonly array $config;

    protected array $onCompletionCallbacks = [];

    /**
     * @param array $config
     * - target_part_size_bytes: (int, optional)
     * - concurrency: (int, optional)
     */
    public function __construct(
        protected readonly S3ClientInterface $s3Client,
        protected readonly array $requestArgs,
        array $config = [],
        protected string|null $uploadId = null,
        protected array $parts = [],
        protected ?TransferProgressSnapshot $currentSnapshot = null,
        protected ?TransferListenerNotifier $listenerNotifier = null,
    ) {
        $this->validateConfig($config);
        $this->config = $config;
    }

    abstract protected function createMultipartOperation(): PromiseInterface;

    abstract protected function completeMultipartOperation(): PromiseInterface;

    abstract protected function processMultipartOperation(): PromiseInterface;

    abstract protected function getTotalSize(): int;

    abstract protected function createResponse(ResultInterface $result): mixed;

    protected function validateConfig(array &$config): void
    {
        if (!isset($config['target_part_size_bytes'])) {
            $config['target_part_size_bytes'] = S3TransferManagerConfig::DEFAULT_TARGET_PART_SIZE_BYTES;
        }

        if (!isset($config['concurrency'])) {
            $config['concurrency'] = S3TransferManagerConfig::DEFAULT_CONCURRENCY;
        }

        $partSize = $config['target_part_size_bytes'];
        if ($partSize < self::PART_MIN_SIZE || $partSize > self::PART_MAX_SIZE) {
            throw new \InvalidArgumentException(
                "Part size config must be between " . self::PART_MIN_SIZE
                ." and " . self::PART_MAX_SIZE . " bytes "
                ."but it is configured to $partSize"
            );
        }
    }

    public function getUploadId(): ?string
    {
        return $this->uploadId;
    }

    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * Get the current progress snapshot.
     */
    public function getCurrentSnapshot(): ?TransferProgressSnapshot
    {
        return $this->currentSnapshot;
    }

    public function promise(): PromiseInterface
    {
        return Coroutine::of(function () {
            try {
                yield $this->createMultipartOperation();
                yield $this->processMultipartOperation();
                yield $this->completeMultipartOperation();
            } finally {
                $this->callOnCompletionCallbacks();
            }
        })->then(fn(ResultInterface $result) => $this->createResponse($result))->otherwise(function (Throwable $e): void {
            $this->operationFailed($e);

            throw $e;
        });
    }

    protected function abortMultipartOperation(): PromiseInterface
    {
        $abortMultipartUploadArgs = $this->requestArgs;
        $abortMultipartUploadArgs['UploadId'] = $this->uploadId;
        $command = $this->s3Client->getCommand(
            'AbortMultipartUpload',
            $abortMultipartUploadArgs
        );

        return $this->s3Client->executeAsync($command);
    }

    protected function sortParts(): void
    {
        usort($this->parts, fn(array $partOne, array $partTwo) => $partOne['PartNumber'] <=> $partTwo['PartNumber']);
    }

    protected function collectPart(
        ResultInterface $result,
        CommandInterface $command
    ): void
    {
        $checksumResult = match($command->getName()) {
            'UploadPart' => $result,
            'UploadPartCopy' => $result['CopyPartResult'],
            default => $result[$command->getName() . 'Result']
        };

        $partData = [
            'PartNumber' => $command['PartNumber'],
            'ETag' => $checksumResult['ETag'],
        ];

        if (isset($command['ChecksumAlgorithm'])) {
            $checksumMemberName = 'Checksum' . strtoupper((string) $command['ChecksumAlgorithm']);
            $partData[$checksumMemberName] = $checksumResult[$checksumMemberName] ?? null;
        }

        $this->parts[] = $partData;
    }

    protected function createCommandPool(
        \Iterator $commands,
        callable $fulfilledCallback,
        callable $rejectedCallback
    ): PromiseInterface
    {
        return (new CommandPool(
            $this->s3Client,
            $commands,
            [
                'concurrency' => $this->config['concurrency'],
                'fulfilled' => $fulfilledCallback,
                'rejected' => $rejectedCallback
            ]
        ))->promise();
    }

    protected function operationInitiated(array $requestArgs): void
    {
        if ($this->currentSnapshot === null) {
            $this->currentSnapshot = new TransferProgressSnapshot(
                $requestArgs['Key'],
                0,
                $this->getTotalSize()
            );
        }

        $this->listenerNotifier?->transferInitiated([
            AbstractTransferListener::REQUEST_ARGS_KEY => $requestArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot
        ]);
    }

    protected function operationCompleted(ResultInterface $result): void
    {
        $newSnapshot = new TransferProgressSnapshot(
            $this->currentSnapshot->getIdentifier(),
            $this->currentSnapshot->getTransferredBytes(),
            $this->currentSnapshot->getTotalBytes(),
            $result->toArray(),
            $this->currentSnapshot->getReason(),
        );

        $this->currentSnapshot = $newSnapshot;

        $this->listenerNotifier?->transferComplete([
            AbstractTransferListener::REQUEST_ARGS_KEY =>
                $this->requestArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot
        ]);
    }

    protected function operationFailed(Throwable $reason): void
    {
        // Event already propagated
        if ($this->currentSnapshot?->getReason() !== null) {
            return;
        }

        if ($this->currentSnapshot === null) {
            $this->currentSnapshot = new TransferProgressSnapshot(
                'Unknown',
                0,
                0,
            );
        }

        $this->currentSnapshot = new TransferProgressSnapshot(
            $this->currentSnapshot->getIdentifier(),
            $this->currentSnapshot->getTransferredBytes(),
            $this->currentSnapshot->getTotalBytes(),
            $this->currentSnapshot->getResponse(),
            $reason
        );

        if (!empty($this->uploadId)) {
            trigger_error(
                "Multipart Upload with id: " . $this->uploadId . " failed",
            );
            $this->abortMultipartOperation()->wait();
        }

        $this->listenerNotifier?->transferFail([
            AbstractTransferListener::REQUEST_ARGS_KEY =>
                $this->requestArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot,
            'reason' => $reason,
        ]);
    }

    protected function partCompleted(
        int $partSize,
        array $requestArgs
    ): void
    {
        $newSnapshot = new TransferProgressSnapshot(
            $this->currentSnapshot->getIdentifier(),
            $this->currentSnapshot->getTransferredBytes() + $partSize,
            $this->currentSnapshot->getTotalBytes(),
            $this->currentSnapshot->getResponse(),
            $this->currentSnapshot->getReason(),
        );

        $this->currentSnapshot = $newSnapshot;

        $this->listenerNotifier?->bytesTransferred([
            AbstractTransferListener::REQUEST_ARGS_KEY => $requestArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot
        ]);
    }

    protected function callOnCompletionCallbacks(): void
    {
        foreach ($this->onCompletionCallbacks as $fn) {
            if (is_callable($fn)) {
                call_user_func($fn);
            }
        }

        $this->onCompletionCallbacks = [];
    }

    protected function partFailed(Throwable $reason): void
    {
        $this->operationFailed($reason);
    }

    protected function calculatePartSize(): int
    {
        return max(
            $this->getTotalSize() / self::PART_MAX_NUM,
            $this->config['target_part_size_bytes']
        );
    }
}
