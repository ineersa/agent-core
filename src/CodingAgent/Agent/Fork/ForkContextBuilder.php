<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Config\CompactionConfig;

/**
 * Builds a full fork session snapshot from parent messages.
 *
 * Ties sanitization, virtual compaction, model resolution, and
 * prompt building into a single pipeline.
 *
 * Steps:
 *   1. Sanitize (trim launch messages).
 *   2. Virtually compact (cut-point selection + summary reuse or fork-local LLM summarization).
 *   3. Resolve optional forks.model and forks.thinking_level overrides.
 *   4. Build fork task user message and system append.
 *   5. Assemble ForkSessionSnapshotDTO.
 *
 * Does NOT mutate the parent message list.  Does NOT launch processes.
 */
final readonly class ForkContextBuilder
{
    public function __construct(
        private ForkSnapshotSanitizer $sanitizer,
        private ForkSnapshotCompactor $compactor,
        private ForkTaskPromptBuilder $promptBuilder,
        private ForkConfigResolver $configResolver,
        private CompactionConfig $compactionConfig,
    ) {
    }

    /**
     * Build a fork session snapshot from parent messages.
     *
     * Token budget for the retained tail is sourced from
     * compaction.keep_recent_tokens (CompactionConfig).
     *
     * @param list<AgentMessage> $parentMessages The parent message list (NOT mutated)
     * @param string             $task           Fork task description
     *
     * @return ForkSessionSnapshotDTO The assembled fork snapshot
     */
    public function build(
        array $parentMessages,
        string $task,
        ?string $activeSessionModel = null,
    ): ForkSessionSnapshotDTO {
        $sanitized = $this->sanitizer->sanitize($parentMessages);
        $compacted = $this->compactor->compact($sanitized, $this->compactionConfig, $activeSessionModel);
        $resolved = $this->configResolver->resolve();
        $taskUserMessage = $this->promptBuilder->buildTaskUserMessage($task);
        $systemAppend = $this->promptBuilder->forkChildSystemPromptAppend();

        return new ForkSessionSnapshotDTO(
            messages: $compacted->messages,
            forkSystemPromptAppend: $systemAppend,
            forkTaskUserMessage: $taskUserMessage,
            resolvedModel: $resolved->resolvedModel,
            resolvedThinkingLevel: $resolved->resolvedThinkingLevel,
        );
    }
}
