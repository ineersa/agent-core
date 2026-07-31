<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Compaction;

/**
 * Public before-compaction hook for extensions.
 *
 * Invoked synchronously after safe partition preparation for both:
 * - CompactRun (canonical coverage watermark present on the context)
 * - Snapshot/fork in-memory compaction via CompactionService::compactMessages
 *   (coverage watermark null/null)
 *
 * Hooks may cancel, provide a replacement summary, append instructions, or attach
 * JSON-safe metadata. They must not mutate the complete prompt message list.
 */
interface BeforeCompactionHookInterface
{
    public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO;
}
