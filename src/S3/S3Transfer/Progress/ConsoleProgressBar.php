<?php

namespace Aws\S3\S3Transfer\Progress;

final class ConsoleProgressBar implements ProgressBarInterface
{
    public const DEFAULT_PROGRESS_BAR_CHAR = '#';
    public const DEFAULT_PROGRESS_BAR_WIDTH = 50;
    public const MAX_PROGRESS_BAR_WIDTH = 50;

    private readonly int $progressBarWidth;

    public function __construct(
        private readonly string                    $progressBarChar = self::DEFAULT_PROGRESS_BAR_CHAR,
        int                       $progressBarWidth = self::DEFAULT_PROGRESS_BAR_WIDTH,
        private int                       $percentCompleted = 0,
        private readonly AbstractProgressBarFormat $progressBarFormat = new ColoredTransferProgressBarFormat(),
    ) {
        $this->progressBarWidth = min(
            $progressBarWidth,
            self::MAX_PROGRESS_BAR_WIDTH
        );
    }

    public function getProgressBarChar(): string
    {
        return $this->progressBarChar;
    }

    public function getProgressBarWidth(): int
    {
        return $this->progressBarWidth;
    }

    public function getPercentCompleted(): int
    {
        return $this->percentCompleted;
    }

    public function getProgressBarFormat(): AbstractProgressBarFormat
    {
        return $this->progressBarFormat;
    }

    /**
     * Set current progress percent.
     *
     *
     */
    public function setPercentCompleted(int $percent): void
    {
        $this->percentCompleted = max(0, min(100, $percent));
    }

    /**
     * @inheritDoc
     */
    public function render(): string
    {
        $filledWidth = (int) round(($this->progressBarWidth * $this->percentCompleted) / 100);
        $progressBar = str_repeat($this->progressBarChar, $filledWidth)
            . str_repeat(' ', $this->progressBarWidth - $filledWidth);

        // Common arguments
        $this->progressBarFormat->setArg('progress_bar', $progressBar);
        $this->progressBarFormat->setArg('percent', $this->percentCompleted);

        return $this->progressBarFormat->format();
    }
}
