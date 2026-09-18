<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Post-execution output capping via the generic ModelNotification system.
 *
 * Centralizes the OutputCap call that was previously scattered across
 * individual tools (ReadFileTool, BashTool, BgStatusTool).  Runs after
 * every tool execution, determines the applicable cap from tool arguments
 * when a file-path argument is present, and when the output exceeds the
 * cap emits a generic ModelNotificationDTO with delivery=tool_result_replace.
 *
 * The notification text is exactly what the model will receive.
 * The visible ToolResult content is replaced with a compact status label
 * so the TUI ToolResult block does not leak raw/full output.
 */
final readonly class OutputCapToolResultProcessor implements ToolResultProcessorInterface
{
    public function __construct(
        private OutputCap $outputCap,
        private NormalizerInterface $normalizer,
    ) {
    }

    public function process(ToolResult $result, ToolCall $toolCall): ToolResult
    {
        $text = $this->extractTextFromContent($result->content);
        if ('' === $text) {
            return $result;
        }

        $path = $this->outputCap->resolveCapPath(
            $toolCall->toolName,
            $toolCall->arguments,
            $result->isError,
        );

        $runId = $toolCall->runId ?? ($toolCall->context['run_id'] ?? null);
        $capResult = $this->outputCap->capIfNeeded($text, \is_string($runId) ? $runId : null, $path);
        if (null === $capResult) {
            return $result;
        }

        // Build context-aware notice: read tools get original-path guidance,
        // generic tools use the default saved-artifact inspection notice.
        $noticeText = $this->outputCap->buildContextualNotice(
            $toolCall->toolName,
            $toolCall->arguments,
            $capResult,
        );

        $notificationId = hash('sha256', implode('|', [
            $toolCall->toolCallId,
            'output_cap',
            $capResult->savedPath,
        ]));

        $notification = new ModelNotificationDTO(
            id: $notificationId,
            source: 'output_cap',
            kind: 'output_capped',
            severity: 'warning',
            delivery: 'tool_result_replace',
            text: $noticeText,
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            orderIndex: $toolCall->orderIndex,
            metadata: [
                'cap' => $capResult->cap,
                'char_count' => $capResult->charCount,
                'token_estimate' => $capResult->tokenEstimate,
                'saved_path' => $capResult->savedPath,
                'path' => $path,
            ],
        );

        // Replace visible content with compact status label.
        $isError = $result->isError;
        $compactLabel = $isError
            ? $toolCall->toolName.' failed'
            : $toolCall->toolName.' completed';
        $compactContent = [[
            'type' => 'text',
            'text' => $compactLabel,
        ]];

        // Sanitize details: strip raw_result (full output) to prevent leakage
        // through canonical ToolResult details, AgentMessage history, and TUI.
        // Preserve attachment_refs if the original result carried any, and keep
        // only safe structured metadata (mode, timeout_seconds, max_parallelism, etc.).
        $originalDetails = \is_array($result->details) ? $result->details : [];
        $safeDetails = $this->safeDetailsFromOriginal($originalDetails);

        // Collect existing notifications and append the new one.
        $existingNotifications = \is_array($originalDetails['model_notifications'] ?? null)
            ? $originalDetails['model_notifications']
            : [];
        /** @var array<string, mixed> $notificationArray */
        $notificationArray = $this->normalizer->normalize($notification, null, [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);
        $existingNotifications[] = $notificationArray;
        $safeDetails['model_notifications'] = $existingNotifications;
        $safeDetails['output_cap'] = [
            'capped' => true,
            'cap' => $capResult->cap,
            'char_count' => $capResult->charCount,
            'token_estimate' => $capResult->tokenEstimate,
            'saved_path' => $capResult->savedPath,
            'path' => $path,
        ];

        return new ToolResult(
            toolCallId: $result->toolCallId,
            toolName: $result->toolName,
            content: $compactContent,
            details: $safeDetails,
            isError: $isError,
        );
    }

    /**
     * Extract concatenated text from ToolResult content parts.
     *
     * @param array<int, array<string, mixed>> $content
     */
    private function extractTextFromContent(array $content): string
    {
        $parts = [];
        foreach ($content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            if (($part['type'] ?? null) !== 'text') {
                continue;
            }
            $text = $part['text'] ?? null;
            if (\is_string($text) && '' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Build safe details from original result details when output was capped.
     *
     * Strips raw_result (full output) to prevent leakage through canonical
     * ToolResult details, AgentMessage history, and TUI projection.  Preserves
     * only attachment_refs (e.g. for image tools, though those don't typically
     * hit the cap) and explicitly whitelisted non-sensitive operational metadata.
     *
     * Execution-level metadata added later by {@see ToolExecutor::withExecutionMetadata()}
     * (beyond processor output) is not affected by this stripping.
     *
     * @param array<string, mixed> $original
     *
     * @return array<string, mixed>
     */
    private function safeDetailsFromOriginal(array $original): array
    {
        $safe = [];

        // Preserve attachment references if the tool produced them
        // (e.g. image tools — but those don't typically hit the cap).
        $rawResult = $original['raw_result'] ?? null;
        if (\is_array($rawResult)) {
            $attachmentRefs = $rawResult['attachment_refs'] ?? null;
            if (\is_array($attachmentRefs)) {
                $safe['raw_result'] = ['attachment_refs' => $attachmentRefs];
            }
        }

        // Forward only explicitly whitelisted non-sensitive operational metadata.
        // New keys must be reviewed before addition — raw output, error bodies,
        // and environment data must never appear here.
        foreach (['mode', 'duration_ms', 'sources'] as $key) {
            if (\array_key_exists($key, $original)) {
                $safe[$key] = $original[$key];
            }
        }

        return $safe;
    }
}
