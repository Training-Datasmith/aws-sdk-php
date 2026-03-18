<?php

namespace Aws\S3\S3Transfer\Models;

use Aws\S3\S3ClientInterface;
use Aws\S3\S3Transfer\Progress\AbstractTransferListener;
use InvalidArgumentException;

abstract class AbstractTransferRequest
{
    public static array $configKeys = [
        'track_progress' => 'bool',
    ];

    public function __construct(protected array $listeners, protected ?AbstractTransferListener $progressTracker, protected array $config, private readonly ?S3ClientInterface $s3Client = null)
    {
    }

    /**
     * Get current listeners.
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }

    /**
     * Get the progress tracker.
     */
    public function getProgressTracker(): ?AbstractTransferListener
    {
        return $this->progressTracker;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getS3Client(): ?S3ClientInterface
    {
        return $this->s3Client;
    }

    public function updateConfigWithDefaults(array $defaultConfig): void
    {
        foreach (static::$configKeys as $key => $_) {
            if (isset($defaultConfig[$key]) && empty($this->config[$key])) {
                $this->config[$key] = $defaultConfig[$key];
            }
        }
    }

    /**
     * For validating config. By default, it provides an empty
     * implementation.
     */
    public function validateConfig(): void {
        foreach (static::$configKeys as $key => $type) {
            if (isset($this->config[$key])
                && !call_user_func('is_' . $type, $this->config[$key])) {
                throw new InvalidArgumentException(
                    "The provided config `$key` must be $type."
                );
            }
        }
    }
}
