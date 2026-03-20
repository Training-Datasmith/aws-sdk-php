<?php

namespace Aws\S3\S3Transfer\Progress;

/**
 * Progress bar base implementation.
 */
interface ProgressBarInterface
{
    public function render(): string;

    public function setPercentCompleted(int $percent): void;

    public function getPercentCompleted(): int;

    public function getProgressBarFormat(): AbstractProgressBarFormat;
}
