<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Compaction;

/**
 * Public before-snapshot-compaction context for extension hooks.
 *
 * Scalar / JSON-safe only. No RunState, AgentCore messages, Symfony AI types,
 * Messenger, Doctrine, mutable prompt lists, or canonical event watermarks.
 *
 * Snapshot compaction operates on an in-memory message list (e.g. a sanitized
 * fork parent snapshot). It has no CompactRun coverage range; use
 * {@see BeforeCompactionHookContextDTO} for the canonical CompactRun path.
 */
final readonly class BeforeSnapshotCompactionHookContextDTO
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $trigger,
        public int $tokenEstimateBefore,
        public int $messagesCompacted,
        public int $messagesRetained,
        public ?int $firstRetainedIndex,
        public bool $priorSummaryPresent,
        public ?string $customInstructions,
        public ?string $resolvedModel,
        public ?string $thinkingLevel,
    ) {
        if ('' === trim($this->runId)) {
            throw new \InvalidArgumentException('BeforeSnapshotCompactionHookContextDTO.runId must be non-empty.');
        }
        if ($this->turnNo < 0) {
            throw new \InvalidArgumentException('BeforeSnapshotCompactionHookContextDTO.turnNo must be >= 0.');
        }
        if ('' === trim($this->trigger)) {
            throw new \InvalidArgumentException('BeforeSnapshotCompactionHookContextDTO.trigger must be non-empty.');
        }
        if ($this->tokenEstimateBefore < 0
            || $this->messagesCompacted < 0
            || $this->messagesRetained < 0) {
            throw new \InvalidArgumentException('BeforeSnapshotCompactionHookContextDTO token/message counts must be >= 0.');
        }
    }
}
