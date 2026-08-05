<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Public cooperative cancellation signal for one tool invocation.
 *
 * Extension tools must poll this during long-running work. Hatfield cannot
 * preempt arbitrary PHP handlers; only cooperative checks and tool-owned
 * process/deadline paths stop in-flight work.
 */
interface ToolCancellationTokenInterface
{
    public function isCancellationRequested(): bool;
}
