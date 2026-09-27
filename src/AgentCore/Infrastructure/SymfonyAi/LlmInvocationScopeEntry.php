<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;

/** One invocation's cancel token and stable run ID. */
final readonly class LlmInvocationScopeEntry
{
    public function __construct(
        public CancellationTokenInterface $token,
        public ?string $runId,
    ) {
    }
}
