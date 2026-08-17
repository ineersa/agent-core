<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Presentation-only skill-read classification for transcript ToolCall blocks.
 *
 * Annotates completed `read` tool calls that target an exact winning discovered
 * skill file with meta['skill_name']. Does not create blocks or change protocol.
 */
final readonly class SkillReadProjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SkillDiscovery $skillDiscovery,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Negative priority: ToolProjectionSubscriber / AssistantStreamProjectionSubscriber
        // create/reconstruct the ToolCall block first; this subscriber only annotates.
        return [
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value => ['onToolCallArgumentsCompleted', -10],
            RuntimeEventTypeEnum::AssistantMessageCompleted->value => ['onAssistantMessageCompleted', -10],
        ];
    }

    public function onToolCallArgumentsCompleted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        if ('' === $toolCallId) {
            return;
        }

        $blockId = 'tool_call_'.$toolCallId;
        $block = $event->state->getBlock($blockId);
        if (null === $block) {
            // Empty-args suppression removes the block before annotation.
            return;
        }

        $toolName = (string) ($block->meta['tool_name'] ?? $p['tool_name'] ?? '');
        $arguments = $block->meta['arguments'] ?? $p['arguments'] ?? [];
        if (!\is_array($arguments)) {
            $arguments = [];
        }

        $this->annotateSkillRead($event->state, $blockId, $block, $toolName, $arguments);
    }

    public function onAssistantMessageCompleted(TranscriptProjectionEvent $event): void
    {
        $toolCalls = $event->payload()['tool_calls'] ?? [];
        if (!\is_array($toolCalls)) {
            return;
        }

        foreach ($toolCalls as $tc) {
            if (!\is_array($tc)) {
                continue;
            }

            $callId = (string) ($tc['id'] ?? '');
            if ('' === $callId) {
                continue;
            }

            $blockId = 'tool_call_'.$callId;
            $block = $event->state->getBlock($blockId);
            if (null === $block) {
                continue;
            }

            $toolName = (string) ($tc['name'] ?? $block->meta['tool_name'] ?? '');
            $arguments = $tc['arguments'] ?? $block->meta['arguments'] ?? [];
            if (!\is_array($arguments)) {
                $arguments = [];
            }

            $this->annotateSkillRead($event->state, $blockId, $block, $toolName, $arguments);
        }
    }

    /**
     * @param array<mixed> $arguments
     */
    private function annotateSkillRead(
        TranscriptProjectionState $state,
        string $blockId,
        TranscriptBlock $block,
        string $toolName,
        array $arguments,
    ): void {
        if ('read' !== $toolName) {
            return;
        }

        $path = $arguments['path'] ?? null;
        if (!\is_string($path) || '' === $path) {
            return;
        }

        $skill = $this->skillDiscovery->findBySkillFilePath($path);
        if (null === $skill) {
            return;
        }

        $meta = $block->meta;
        $meta['skill_name'] = $skill->name;
        $state->updateBlock($blockId, $block->with(meta: $meta));
    }
}
