<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

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
    /** @var list<string> Tool argument keys that carry a file path. */
    private const array PATH_ARGUMENT_KEYS = ['path', 'file_path', 'file'];

    public function __construct(
        private OutputCap $outputCap,
    ) {
    }

    public function process(ToolResult $result, ToolCall $toolCall): ToolResult
    {
        // Only process non-error results with text content.
        if ($result->isError) {
            return $result;
        }

        $text = $this->extractTextFromContent($result->content);
        if ('' === $text) {
            return $result;
        }

        $path = $this->extractPathFromArguments($toolCall->arguments);
        $cap = $this->outputCap->capForPath($path);
        $charCount = mb_strlen($text);

        if ($charCount <= $cap) {
            // Text fits within the cap — passthrough unchanged.
            // Still record server-side cap metrics for auditing.
            $result = $this->addCapMetadata($result, [
                'capped' => false,
                'cap' => $cap,
                'char_count' => $charCount,
                'path' => $path,
            ]);

            return $result;
        }

        $capResult = $this->outputCap->capIfNeeded($text, $path);
        // capIfNeeded returns null when under cap, so this path always has a result.
        if (null === $capResult) {
            // Defensive: if somehow under cap despite length check, add metadata.
            return $this->addCapMetadata($result, [
                'capped' => false,
                'cap' => $cap,
                'char_count' => $charCount,
                'path' => $path,
            ]);
        }

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
            text: $capResult->noticeText,
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
        $compactContent = [[
            'type' => 'text',
            'text' => $toolCall->toolName.' completed',
        ]];

        // Collect existing notifications and append the new one.
        $details = \is_array($result->details) ? $result->details : [];
        $existingNotifications = \is_array($details['model_notifications'] ?? null)
            ? $details['model_notifications']
            : [];
        $existingNotifications[] = $notification->toArray();
        $details['model_notifications'] = $existingNotifications;
        $details['output_cap'] = [
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
            details: $details,
            isError: $result->isError,
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
     * Find a file-path value from tool call arguments.
     *
     * Checks known path-carrying argument keys and returns the first
     * string value found.  Returns null when no path argument exists.
     *
     * @param array<string, mixed> $arguments
     */
    private function extractPathFromArguments(array $arguments): ?string
    {
        foreach (self::PATH_ARGUMENT_KEYS as $key) {
            $value = $arguments[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Add output-cap metrics to tool result details without capping.
     *
     * @param array<string, mixed> $capMeta
     */
    private function addCapMetadata(ToolResult $result, array $capMeta): ToolResult
    {
        $details = \is_array($result->details) ? $result->details : [];
        $details['output_cap'] = $capMeta;

        return new ToolResult(
            toolCallId: $result->toolCallId,
            toolName: $result->toolName,
            content: $result->content,
            details: $details,
            isError: $result->isError,
        );
    }
}
