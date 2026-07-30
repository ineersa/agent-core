<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Compaction;

/**
 * Public before-snapshot-compaction hook for extensions.
 *
 * Invoked synchronously by CompactionService::compactMessages after safe
 * partition preparation for in-memory snapshot compaction (fork, resume
 * snapshot, and other non-CompactRun callers). Not invoked by CompactRun.
 *
 * CompactRun uses {@see BeforeCompactionHookInterface} with a canonical
 * coverage watermark. Snapshot hooks deliberately omit watermark fields:
 * they only receive scalar preparation metrics for the message list being
 * compacted.
 *
 * Hooks may cancel, provide a replacement summary, append instructions, or
 * attach JSON-safe metadata. They must not mutate the message list.
 */
interface BeforeSnapshotCompactionHookInterface
{
    public function beforeSnapshotCompaction(BeforeSnapshotCompactionHookContextDTO $context): BeforeCompactionHookResultDTO;
}
