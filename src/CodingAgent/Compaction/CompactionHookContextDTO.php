<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

/**
 * Safe context passed to {@see BeforeCompactionHookInterface::beforeCompaction()}.
 *
 * Contains enough information for hooks to make cancellation/replacement/extension
 * decisions without exposing raw prompts, full session content, or API keys.
 */
final readonly class CompactionHookContextDTO
{
    public function __construct(
        /** Target run/session identifier. */
        public string $runId,

        /** Turn number at dispatch time. */
        public int $turnNo,

        /** 'manual' for /compact, 'auto' for threshold-based triggers. */
        public string $trigger,

        /** Preparation result with partition counts and token estimate. */
        public CompactionPreparationDTO $preparation,

        /** User-provided custom instructions (from /compact <instructions>). */
        public ?string $customInstructions,

        /** Resolved compaction model (provider/model string). */
        public ?string $resolvedModel,

        /** Thinking level resolved from compaction config, or null. */
        public ?string $thinkingLevel,
    ) {
    }
}
