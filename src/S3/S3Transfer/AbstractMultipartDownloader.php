<?php
namespace Aws\S3\S3Transfer;

use Aws\CommandInterface;
use Aws\ResultInterface;
use Aws\S3\S3ClientInterface;
use Aws\S3\S3Transfer\Exception\S3TransferException;
use Aws\S3\S3Transfer\Models\DownloadResult;
use Aws\S3\S3Transfer\Models\S3TransferManagerConfig;
use Aws\S3\S3Transfer\Progress\AbstractTransferListener;
use Aws\S3\S3Transfer\Progress\TransferListenerNotifier;
use Aws\S3\S3Transfer\Progress\TransferProgressSnapshot;
use Aws\S3\S3Transfer\Utils\AbstractDownloadHandler;
use Aws\S3\S3Transfer\Utils\StreamDownloadHandler;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\PromisorInterface;

abstract class AbstractMultipartDownloader implements PromisorInterface
{
    public const GET_OBJECT_COMMAND = "GetObject";
    public const PART_GET_MULTIPART_DOWNLOADER = "part";
    public const RANGED_GET_MULTIPART_DOWNLOADER = "ranged";
    private const OBJECT_SIZE_REGEX = "/\/(\d+)$/";

    protected readonly array $config;

    private readonly AbstractDownloadHandler $downloadHandler;

    private readonly ?TransferListenerNotifier $listenerNotifier;

    public function __construct(
        protected readonly S3ClientInterface $s3Client,
        protected readonly array $downloadRequestArgs,
        array $config = [],
        ?AbstractDownloadHandler $downloadHandler = null,
        protected int $currentPartNo = 0,
        protected int $objectPartsCount = 0,
        protected int $objectSizeInBytes = 0,
        protected ?string $eTag = null,
        private ?TransferProgressSnapshot $currentSnapshot = null,
        ?TransferListenerNotifier $listenerNotifier  = null
    ) {
        $this->validateConfig($config);
        $this->config = $config;
        if ($downloadHandler === null) {
            $downloadHandler = new StreamDownloadHandler();
        }
        $this->downloadHandler = $downloadHandler;
        if ($listenerNotifier === null) {
            $listenerNotifier = new TransferListenerNotifier();
        }
        // Add download handler to the listener notifier
        $listenerNotifier->addListener($downloadHandler);
        $this->listenerNotifier  = $listenerNotifier;
    }

    /**
     * Returns the next command for fetching the next object part.
     */
    abstract protected function nextCommand(): CommandInterface;

    /**
     * Compute the object dimensions, such as size and parts count.
     *
     *
     */
    abstract protected function computeObjectDimensions(ResultInterface $result): void;

    private function validateConfig(array &$config): void
    {
        if (!isset($config['target_part_size_bytes'])) {
            $config['target_part_size_bytes'] = S3TransferManagerConfig::DEFAULT_TARGET_PART_SIZE_BYTES;
        }

        if (!isset($config['response_checksum_validation'])) {
            $config['response_checksum_validation'] = S3TransferManagerConfig::DEFAULT_RESPONSE_CHECKSUM_VALIDATION;
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getCurrentPartNo(): int
    {
        return $this->currentPartNo;
    }

    public function getObjectPartsCount(): int
    {
        return $this->objectPartsCount;
    }

    public function getObjectSizeInBytes(): int
    {
        return $this->objectSizeInBytes;
    }

    public function getCurrentSnapshot(): TransferProgressSnapshot
    {
        return $this->currentSnapshot;
    }

    public function download(): DownloadResult
    {
        return $this->promise()->wait();
    }

    /**
     * Returns that resolves a multipart download operation,
     * or to a rejection in case of any failures.
     */
    public function promise(): PromiseInterface
    {
        return Coroutine::of(function () {
            try {
                $initialRequestResult = yield $this->initialRequest();
                $prevPartNo = $this->currentPartNo - 1;
                while ($this->currentPartNo < $this->objectPartsCount) {
                    // To prevent infinite loops
                    if ($prevPartNo !== $this->currentPartNo - 1) {
                        throw new S3TransferException(
                            "Current part `$this->currentPartNo` MUST increment."
                        );
                    }

                    $prevPartNo = $this->currentPartNo;

                    $command = $this->nextCommand();
                    yield $this->s3Client->executeAsync($command)
                        ->then(function (\Aws\ResultInterface $result) use ($command): \Aws\ResultInterface {
                            $this->partDownloadCompleted(
                                $result,
                                $command->toArray()
                            );

                            return $result;
                        })->otherwise(function (\Throwable $reason): void {
                            $this->partDownloadFailed($reason);

                            throw $reason;
                        });
                }

                if ($this->currentPartNo !== $this->objectPartsCount) {
                    throw new S3TransferException(
                        "Expected number of parts `$this->objectPartsCount`"
                        . " to have been transferred but got `$this->currentPartNo`."
                    );
                }

                // Transfer completed
                $this->downloadComplete();

                // Return response
                $result = $initialRequestResult->toArray();
                unset($result['Body']);

                yield Create::promiseFor(new DownloadResult(
                    $this->downloadHandler->getHandlerResult(),
                    $result,
                ));
            } catch (\Throwable $e) {
                $this->downloadFailed($e);
                yield Create::rejectionFor($e);
            }
        });
    }

    /**
     * Perform the initial download request.
     */
    protected function initialRequest(): PromiseInterface
    {
        $command = $this->nextCommand();
        // Notify download initiated
        $this->downloadInitiated($command->toArray());

        return $this->s3Client->executeAsync($command)
            ->then(function (ResultInterface $result) use ($command): \Aws\ResultInterface {
                // Compute object dimensions such as parts count and object size
                $this->computeObjectDimensions($result);

                // If there are more than one part then save the ETag
                if ($this->objectPartsCount > 1) {
                    $this->eTag = $result['ETag'];
                }

                // Notify listeners
                $this->partDownloadCompleted(
                    $result,
                    $command->toArray()
                );

                // Assign custom fields in the result
                $result['ContentLength'] = $this->objectSizeInBytes;

                return $result;
            })->otherwise(function (\Throwable $reason): void  {
                $this->partDownloadFailed($reason);

                throw $reason;
            });
    }

    /**
     * Calculates the object size from content range.
     */
    protected function computeObjectSizeFromContentRange(
        string $contentRange
    ): int
    {
        if (empty($contentRange)) {
            return 0;
        }

        // For extracting the object size from the ContentRange header value.
        if (preg_match(self::OBJECT_SIZE_REGEX, $contentRange, $matches)) {
            return $matches[1];
        }

        throw new S3TransferException(
            "Invalid content range \"$contentRange\""
        );
    }

    /**
     * Main purpose of this method is to propagate
     * the download-initiated event to listeners, but
     * also it does some computation regarding internal states
     * that need to be maintained.
     *
     *
     */
    private function downloadInitiated(array $commandArgs): void
    {
       if ($this->currentSnapshot === null) {
           $this->currentSnapshot = new TransferProgressSnapshot(
               $commandArgs['Key'],
               0,
               $this->objectSizeInBytes
           );
       } else {
           $this->currentSnapshot = new TransferProgressSnapshot(
               $this->currentSnapshot->getIdentifier(),
               $this->currentSnapshot->getTransferredBytes(),
               $this->currentSnapshot->getTotalBytes(),
               $this->currentSnapshot->getResponse()
           );
       }

        $this->listenerNotifier?->transferInitiated([
            AbstractTransferListener::REQUEST_ARGS_KEY => $commandArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot,
        ]);
    }

    /**
     * Propagates download-failed event to listeners.
     *
     *
     */
    private function downloadFailed(\Throwable $reason): void
    {
        // Event already propagated.
        if ($this->currentSnapshot->getReason() !== null) {
            return;
        }

        $this->currentSnapshot = new TransferProgressSnapshot(
            $this->currentSnapshot->getIdentifier(),
            $this->currentSnapshot->getTransferredBytes(),
            $this->currentSnapshot->getTotalBytes(),
            $this->currentSnapshot->getResponse(),
            $reason
        );

        $this->listenerNotifier?->transferFail([
            AbstractTransferListener::REQUEST_ARGS_KEY => $this->downloadRequestArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot,
            'reason' => $reason,
        ]);
    }

    /**
     * Propagates part-download-completed to listeners.
     * It also does some computation in order to maintain internal states.
     *
     *
     */
    private function partDownloadCompleted(
        ResultInterface $result,
        array $requestArgs
    ): void
    {
        $partDownloadBytes = $result['ContentLength'];
        if (isset($result['ETag'])) {
            $this->eTag = $result['ETag'];
        }

        $newSnapshot = new TransferProgressSnapshot(
            $this->currentSnapshot->getIdentifier(),
            $this->currentSnapshot->getTransferredBytes() + $partDownloadBytes,
            $this->objectSizeInBytes,
            $result->toArray()
        );
        $this->currentSnapshot = $newSnapshot;
        $this->listenerNotifier?->bytesTransferred([
            AbstractTransferListener::REQUEST_ARGS_KEY => $requestArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot,
        ]);
    }

    /**
     * Propagates part-download-failed event to listeners.
     *
     *
     */
    private function partDownloadFailed(
        \Throwable $reason,
    ): void
    {
        $this->downloadFailed($reason);
    }

    /**
     * Propagates object-download-completed event to listeners.
     */
    private function downloadComplete(): void
    {
        $newSnapshot = new TransferProgressSnapshot(
            $this->currentSnapshot->getIdentifier(),
            $this->currentSnapshot->getTransferredBytes(),
            $this->objectSizeInBytes,
            $this->currentSnapshot->getResponse()
        );
        $this->currentSnapshot = $newSnapshot;
        $this->listenerNotifier?->transferComplete([
            AbstractTransferListener::REQUEST_ARGS_KEY => $this->downloadRequestArgs,
            AbstractTransferListener::PROGRESS_SNAPSHOT_KEY => $this->currentSnapshot,
        ]);
    }

    /**
     * @param mixed $multipartDownloadType
     */
    public static function chooseDownloaderClass(
        string $multipartDownloadType
    ): string
    {
        return match ($multipartDownloadType) {
            AbstractMultipartDownloader::PART_GET_MULTIPART_DOWNLOADER => PartGetMultipartDownloader::class,
            AbstractMultipartDownloader::RANGED_GET_MULTIPART_DOWNLOADER => RangeGetMultipartDownloader::class,
            default => throw new \InvalidArgumentException(
                "The config value for `multipart_download_type` must be one of:\n"
                . "\t* " . AbstractMultipartDownloader::PART_GET_MULTIPART_DOWNLOADER
                ."\n"
                . "\t* " . AbstractMultipartDownloader::RANGED_GET_MULTIPART_DOWNLOADER
            )
        };
    }
}
