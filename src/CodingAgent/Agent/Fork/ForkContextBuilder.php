<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
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
    public const int DEFAULT_KEEP_RECENT_TOKENS = 20000;

    public function __construct(
        private ForkSnapshotSanitizer $sanitizer,
        private ForkSnapshotCompactorInterface $compactor,
        private ForkTaskPromptBuilder $promptBuilder,
        private ForkConfigResolver $configResolver,
    ) {
    }

    /**
     * Build a fork session snapshot from parent messages.
     *
     * @param list<AgentMessage> $parentMessages   The parent message list (NOT mutated)
     * @param string             $task             Fork task description
     * @param ForkLevelEnum|null $requestedLevel   Requested fork level (null = default)
     * @param int                $keepRecentTokens Token budget for the retained tail
     *
     * @return ForkSessionSnapshotDTO The assembled fork snapshot
     */
    public function build(
        array $parentMessages,
        string $task,
        ?ForkLevelEnum $requestedLevel = null,
        int $keepRecentTokens = self::DEFAULT_KEEP_RECENT_TOKENS,
    ): ForkSessionSnapshotDTO {
        // Step 1: Sanitize.
        $sanitized = $this->sanitizer->sanitize($parentMessages);

        // Step 2: Virtually compact.
        $compacted = $this->compactor->compact($sanitized, $keepRecentTokens);

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
