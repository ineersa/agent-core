<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\DTO\PendingToolInvocationDTO;

/** Read current canonical identity through the existing disposable location index. */
final readonly class CanonicalToolInvocationReader
{
    public function __construct(private RunHistorySourceProviderInterface $sources)
    {
    }

    public function find(string $runId, string $toolCallId): ?PendingToolInvocationDTO
    {
        $source = $this->sources->historySource($runId);

        return $source->log->pendingToolInvocation($source->path, $runId, $toolCallId);
    }
}
