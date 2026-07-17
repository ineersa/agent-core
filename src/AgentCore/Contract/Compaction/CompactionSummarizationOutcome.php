<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

/**
 * Outcome of a no-tools compaction summarization model invocation.
 */
final readonly class CompactionSummarizationOutcome
{
    /**
     * @param array<string, mixed>|null $error Provider/model error payload when invocation failed
     */
    public function __construct(
        public ?string $summaryText,
        public ?array $error,
    ) {
    }

    public function isError(): bool
    {
        return null !== $this->error;
    }
}
