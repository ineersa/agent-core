<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Compaction;

/**
 * Public before-compaction hook for extensions.
 *
 * Invoked synchronously by CompactRunHandler after safe partition preparation
 * and only for the canonical CompactRun path.
 *
 * Snapshot/fork in-memory compaction uses {@see BeforeSnapshotCompactionHookInterface}
 * via CompactionService::compactMessages instead.
 *
 * Hooks may cancel, provide a replacement summary, append instructions, or attach
 * JSON-safe metadata. They must not mutate the complete prompt message list.
 */
interface BeforeCompactionHookInterface
{
    public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO;
}
