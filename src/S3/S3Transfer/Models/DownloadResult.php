<?php

namespace Aws\S3\S3Transfer\Models;

use Aws\Result;

final class DownloadResult extends Result
{
    public function __construct(
        private readonly mixed $downloadDataResult,
        array $data = []
    ) {
        parent::__construct($data);
    }

    public function getDownloadDataResult(): mixed
    {
        return $this->downloadDataResult;
    }
}
