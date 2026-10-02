<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Psr\Log\LoggerInterface;

/**
 * Emits structured INFO memory checkpoints for lifecycle boundaries.
 *
 * Samples the calling process only. Never logs prompts, tool output, or raw arrays.
 * Checkpoint emission is best-effort: logger failures degrade to error_log and never
 * interrupt mount, tick, switch, reload, or shutdown control flow.
 */
final readonly class ProcessMemorySnapshotLogger
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, bool|int|float|string|null> $fields
     */
    public function checkpoint(string $eventType, string $component, array $fields = []): void
    {
        $context = [
            'component' => $component,
            'event_type' => $eventType,
        ];

        foreach ($fields as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                continue;
            }
            if (\is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value) || null === $value) {
                $context[$key] = $value;
            }
        }

        try {
            $this->logger->info('process.memory.checkpoint', $context);
        } catch (\Throwable $e) {
            // Intentional local degradation: memory telemetry must not break
            // mount/tick/switch/reload/shutdown. Do not recurse into $this->logger.
            $safeEvent = preg_replace('/[^a-zA-Z0-9._-]/', '_', $eventType) ?? 'unknown';
            $safeComponent = preg_replace('/[^a-zA-Z0-9._-]/', '_', $component) ?? 'unknown';
            $safeClass = preg_replace('/[^a-zA-Z0-9_\\\\]/', '_', $e::class) ?? 'Throwable';
            error_log(\sprintf(
                'process.memory.checkpoint_failed event_type=%s component=%s exception_class=%s',
                $safeEvent,
                $safeComponent,
                $safeClass,
            ));
        }
    }

    /**
     * Scalar counts from already-resident transcript blocks.
     *
     * @param list<TranscriptBlock> $blocks
     *
     * @return array{
     *     transcript_block_count: int,
     *     transcript_text_bytes: int,
     *     kind_system: int,
     *     kind_user_message: int,
     *     kind_assistant_message: int,
     *     kind_assistant_thinking: int,
     *     kind_tool_call: int,
     *     kind_tool_result: int,
     *     kind_error: int,
     *     kind_other: int
     * }
     */
    public static function transcriptScalars(array $blocks): array
    {
        $counts = [
            'kind_system' => 0,
            'kind_user_message' => 0,
            'kind_assistant_message' => 0,
            'kind_assistant_thinking' => 0,
            'kind_tool_call' => 0,
            'kind_tool_result' => 0,
            'kind_error' => 0,
            'kind_other' => 0,
        ];
        $textBytes = 0;

        foreach ($blocks as $block) {
            $textBytes += \strlen($block->text);
            match ($block->kind) {
                TranscriptBlockKindEnum::System => ++$counts['kind_system'],
                TranscriptBlockKindEnum::UserMessage => ++$counts['kind_user_message'],
                TranscriptBlockKindEnum::AssistantMessage => ++$counts['kind_assistant_message'],
                TranscriptBlockKindEnum::AssistantThinking => ++$counts['kind_assistant_thinking'],
                TranscriptBlockKindEnum::ToolCall => ++$counts['kind_tool_call'],
                TranscriptBlockKindEnum::ToolResult => ++$counts['kind_tool_result'],
                TranscriptBlockKindEnum::Error => ++$counts['kind_error'],
                default => ++$counts['kind_other'],
            };
        }

        return [
            'transcript_block_count' => \count($blocks),
            'transcript_text_bytes' => $textBytes,
            ...$counts,
        ];
    }

    /**
     * Parent retained-transcript labels for TUI checkpoints.
     *
     * `state.transcript` remains the parent projection even while a child live
     * view is visible. These fields make that scope explicit without counting
     * widgets or logging child transcript payloads.
     *
     * @return array{
     *     transcript_scope: 'parent',
     *     visible_run_id: string,
     *     live_child_view: bool
     * }
     */
    public static function parentTranscriptScope(string $visibleRunId, bool $liveChildView): array
    {
        return [
            'transcript_scope' => 'parent',
            'visible_run_id' => $visibleRunId,
            'live_child_view' => $liveChildView,
        ];
    }
}
