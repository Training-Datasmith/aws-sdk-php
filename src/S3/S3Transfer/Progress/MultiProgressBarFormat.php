<?php

namespace Aws\S3\S3Transfer\Progress;

final class MultiProgressBarFormat extends AbstractProgressBarFormat
{
    public const FORMAT_TEMPLATE = "[|progress_bar|] |percent|% "
    ."Completed: |completed|/|total|, Failed: |failed|/|total|";
    public const FORMAT_PARAMETERS = [
        'completed',
        'failed',
        'total',
        'percent',
        'progress_bar'
    ];

    public function getFormatTemplate(): string
    {
        return self::FORMAT_TEMPLATE;
    }

    public function getFormatParameters(): array
    {
        return self::FORMAT_PARAMETERS;
    }

    protected function getFormatDefaultParameterValues(): array
    {
        return [];
    }
}
