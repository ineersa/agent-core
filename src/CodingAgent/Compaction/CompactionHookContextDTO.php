<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

/**
 * Safe context passed to {@see BeforeCompactionHookInterface::beforeCompaction()}.
 *
 * Contains only scalar/meta information — no raw {@see AgentMessage} lists,
 * full prompts, session content, or API keys. Hooks receive enough safe
 * decision-making fields (token estimates, partition counts, trigger source,
 * resolved model, custom instructions) to cancel, replace, extend, or attach
 * metadata without accessing raw conversation content.
 *
 * If a future hook genuinely needs raw messages, add an explicitly documented
 * sensitive accessor rather than exposing the full preparation DTO.
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

        /** Token estimate before compaction (pre-partition count). */
        public int $tokenEstimateBefore,

        /** Number of messages that would be summarised away. */
        public int $messagesCompacted,

        /** Number of messages retained in the tail (not summarised). */
        public int $messagesRetained,

        /** Original index of the first retained message, or null if unknown. */
        public ?int $firstRetainedIndex,

        /** Whether the conversation already contains a prior compact-summary marker. */
        public bool $priorSummaryPresent,

        /** User-provided custom instructions (from /compact <instructions>). */
        public ?string $customInstructions,

        /** Resolved compaction model (provider/model string). */
        public ?string $resolvedModel,

        /** Thinking level resolved from compaction config, or null. */
        public ?string $thinkingLevel,
    ) {
    }
}
