<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\ForkLevelEnum;

/**
 * Builds a full fork session snapshot from parent messages.
 *
 * Ties sanitization, virtual compaction, level/model resolution, and
 * prompt building into a single pipeline.
 *
 * Steps:
 *   1. Sanitize (trim launch messages).
 *   2. Virtually compact (cut-point selection + summary reuse).
 *   3. Resolve level and model.
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
     * @param ForkLevelEnum|null $requestedLevel Requested fork level (null = default)
     *
     * @return ForkSessionSnapshotDTO The assembled fork snapshot
     */
    public function build(
        array $parentMessages,
        string $task,
        ?ForkLevelEnum $requestedLevel = null,
    ): ForkSessionSnapshotDTO {
        // Step 1: Sanitize.
        $sanitized = $this->sanitizer->sanitize($parentMessages);

        // Step 2: Virtually compact (budget from compaction.keep_recent_tokens).
        $compacted = $this->compactor->compact($sanitized, $this->compactionConfig);

        // Step 3: Resolve level and model.
        $resolved = $this->configResolver->resolve($requestedLevel);

        // Step 4: Build prompts.
        $taskUserMessage = $this->promptBuilder->buildTaskUserMessage($task);
        $systemAppend = $this->promptBuilder->forkChildSystemPromptAppend();

        // Step 5: Assemble snapshot.
        return new ForkSessionSnapshotDTO(
            messages: $compacted->messages,
            forkSystemPromptAppend: $systemAppend,
            forkTaskUserMessage: $taskUserMessage,
            level: $resolved->level,
            resolvedModel: $resolved->resolvedModel,
        );
    }
}
