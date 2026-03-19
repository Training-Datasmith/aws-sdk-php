<?php

declare(strict_types=1);

namespace Aws\Arn\S3;

use Aws\Arn\ArnInterface;

/**
 * @internal
 */
interface OutpostsArnInterface extends ArnInterface
{
    public function getOutpostId();
}
