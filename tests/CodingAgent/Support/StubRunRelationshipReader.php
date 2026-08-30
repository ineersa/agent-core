<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Dto\RunRelationshipDTO;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;

/** Deterministic hot relationship double for unit tests. */
final class StubRunRelationshipReader implements RunRelationshipReaderInterface
{
    /** @param array<string, RunRelationshipDTO> $rows */
    public function __construct(
        private array $rows = [],
    ) {
    }

    public function find(string $runId): ?RunRelationshipDTO
    {
        return $this->rows[$runId] ?? null;
    }

    public function isAgentChild(string $runId): bool
    {
        $row = $this->find($runId);

        return null !== $row && $row->isAgentChild();
    }

    public function readParentRunId(string $runId): ?string
    {
        return $this->find($runId)?->parentRunId;
    }

    public function requireKnownTopLevel(string $runId): void
    {
        $row = $this->find($runId);
        if (null === $row) {
            throw new \RuntimeException(\sprintf('Operational relationship for run "%s" is missing; nested launch is blocked.', $runId));
        }
        if ($row->isAgentChild()) {
            throw new \RuntimeException(\sprintf('Run "%s" is an agent child; nested launches are not supported.', $runId));
        }
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function topLevel(string $runId): self
    {
        return new self([$runId => new RunRelationshipDTO($runId, null, $runId)]);
    }

    public static function child(string $runId, string $parentRunId): self
    {
        return new self([
            $runId => new RunRelationshipDTO($runId, $parentRunId, $parentRunId),
            $parentRunId => new RunRelationshipDTO($parentRunId, null, $parentRunId),
        ]);
    }
}
