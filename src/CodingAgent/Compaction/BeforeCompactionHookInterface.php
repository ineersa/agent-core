<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

/**
 * Internal first-party before-compaction hook contract.
 *
 * Public external extensions implement
 * {@see \Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface}
 * and register via ExtensionApi. This internal interface remains for first-party
 * tagged services only (`coding_agent.before_compaction_hook`).
 *
 * Hooks registered via that tag are invoked by {@see CompactionHookDispatcher}
 * between compaction preparation and model invocation. A hook can cancel
 * compaction, provide a replacement summary (skipping the LLM call), append
 * additional summarization instructions, or attach opaque metadata to lifecycle
 * events.
 *
 * Hooks run in registration order (controlled by the !tagged_iterator priority
 * attribute when needed). Exceptions from a single hook are caught, logged,
 * and do not stop later hooks.
 *
 * CompactRun also dispatches public ExtensionApi hooks after internal ones;
 * snapshot/fork paths use only this internal surface (no public watermark).
 */
interface BeforeCompactionHookInterface
{
    /**
     * Inspect compaction context and optionally alter the compaction path.
     *
     * @param CompactionHookContextDTO $context Safe compaction context
     *
     * @return CompactionHookResultDTO Result specifying whether to cancel,
     *                                 replace, extend, or attach metadata
     */
    public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO;
}
