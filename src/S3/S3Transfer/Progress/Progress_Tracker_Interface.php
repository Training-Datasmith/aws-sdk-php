<?php

namespace Aws\S3\S3Transfer\Progress;

interface ProgressTrackerInterface
{
    /**
     * To show the progress being tracked.
     */
    public function showProgress(): void;
}
