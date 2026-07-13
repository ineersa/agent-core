<?php

declare(strict_types=1);

namespace Ineersa\Platform\Result;

use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Raw provider result that can be aborted while streaming is in progress.
 *
 * Transport-neutral contract for LlmPlatformAdapter cancellation cleanup.
 */
interface CancellableRawResultInterface extends RawResultInterface
{
    public function abort(): void;
}
