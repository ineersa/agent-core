<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;

/**
 * Deterministic hot relationship double for unit tests.
 *
 * Missing rows fail closed like production {@see \Ineersa\CodingAgent\Repository\RunRelationshipReader}.
 */
final class StubRunRelationshipReader implements RunRelationshipReaderInterface
{
    /**
     * @param array<string, ?string> $parentByRunId known run_id => parent_run_id (null = top-level)
     */
    public function __construct(
        private array $parentByRunId = [],
    ) {
    }

    public function isAgentChild(string $runId): bool
    {
        return null !== $this->requireKnownParent($runId);
    }

    public function readParentRunId(string $runId): ?string
    {
        return $this->requireKnownParent($runId);
    }

    public function requireKnownTopLevel(string $runId): void
    {
        if (null !== $this->requireKnownParent($runId)) {
            throw new \RuntimeException(\sprintf('Run "%s" is an agent child; nested launches are not supported.', $runId));
        }
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function topLevel(string $runId): self
    {
        return new self([$runId => null]);
    }

    public static function child(string $runId, string $parentRunId): self
    {
        return new self([
            $runId => $parentRunId,
            $parentRunId => null,
        ]);
    }

    private function requireKnownParent(string $runId): ?string
    {
        if (!\array_key_exists($runId, $this->parentByRunId)) {
            throw new \RuntimeException(\sprintf('Operational relationship for run "%s" is missing.', $runId));
        }

        return $this->parentByRunId[$runId];
    }
}
