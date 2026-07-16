<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

/**
 * Signals fork deferred launch entered durable pre-launch compaction; coordinator must return DeferredToolCompletionOutcome.
 */
final class ForkDeferredPrelaunchPendingException extends \RuntimeException
{
}
