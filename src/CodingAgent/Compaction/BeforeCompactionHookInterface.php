<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

/**
 * Internal (non-ExtensionApi) before-compaction hook contract.
 *
 * Hooks registered via service tag {@see coding_agent.before_compaction_hook}
 * are invoked by {@see CompactionHookDispatcher} between compaction preparation
 * and model invocation. A hook can cancel compaction, provide a replacement
 * summary (skipping the LLM call), append additional summarization instructions,
 * or attach opaque metadata to lifecycle events.
 *
 * Hooks run in registration order (controlled by the !tagged_iterator priority
 * attribute when needed). Exceptions from a single hook are caught, logged,
 * and do not stop later hooks.
 *
 * Do NOT expose this interface through ExtensionApi until the contract stabilises
 * (per implementation-plan §16.3).
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
