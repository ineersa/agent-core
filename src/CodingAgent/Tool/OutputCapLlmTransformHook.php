<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ToolResultType;

/**
 * Defense-in-depth LLM-bound output capping for tool-result text.
 *
 * The primary output capping now lives in OutputCapToolResultProcessor,
 * which runs immediately after tool execution and before canonical
 * ToolResult/AgentMessage construction. Per-tool OutputCap calls have
 * been removed from ReadFileTool, BashTool, and BgStatusTool.
 *
 * This hook remains as a safety net for any tool-role AgentMessage
 * that reaches the LLM boundary with oversized text — for example
 * extension tools, MCP tools, third-party tools, or messages that
 * bypass the normal ToolExecutor→tool result processor pipeline.
 *
 * The hook uses structured model_notifications metadata for skip
 * detection instead of text-marker detection to avoid double-capping
 * messages that the primary processor already handled. When it does
 * cap a message, it attaches a generic model_notification so downstream
 * consumers can detect the cap without parsing text.
 *
 * Image content parts (image_ref) are preserved unchanged.
 */
final readonly class OutputCapLlmTransformHook implements TransformContextHookInterface
{
    public function __construct(
        private OutputCap $outputCap,
    ) {
    }

    public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array
    {
        $transformed = [];

        foreach ($messages as $message) {
            $transformed[] = $this->transformMessage($message);
        }

        return $transformed;
    }

    private function transformMessage(AgentMessage $message): AgentMessage
    {
        if ('tool' !== $message->role) {
            return $message;
        }

        // Skip messages already capped by the primary tool-result processor.
        // Detection uses structured model_notifications in details instead of text markers.
        $alreadyCapped = $this->hasDeliveryToolResultReplace(
            \is_array($message->details['model_notifications'] ?? null)
                ? $message->details['model_notifications']
                : null,
        );

        if ($alreadyCapped) {
            return $message;
        }

        // Collect all text content and non-text parts.
        $textParts = [];
        $nonTextParts = [];

        foreach ($message->content as $part) {
            if (!\is_array($part)) {
                continue;
            }

            // Preserve image_ref parts unchanged.
            if (ToolResultType::IMAGE_REF === ($part['type'] ?? null)) {
                $nonTextParts[] = $part;

                continue;
            }

            $text = $part['text'] ?? null;
            if (\is_string($text) && '' !== $text) {
                $textParts[] = $text;
            }
        }

        $combinedText = implode("\n", $textParts);

        // If there is no text content but details exists, the converter
        // will fall back to stringifying details (buildToolMessages).
        // Cap that fallback so no raw details reach the LLM.
        if ('' === $combinedText && null !== $message->details) {
            $detailsText = $this->stringify($message->details);
            if ('' !== $detailsText) {
                $combinedText = $detailsText;
            }
        }

        // No text or details to cap — pass through unchanged.
        if ('' === $combinedText) {
            return $message;
        }

        // Extract a file path from the tool call arguments when available
        // so the late hook applies the same docCap/defaultCap decision as the
        // primary OutputCapToolResultProcessor.  Without path context, read results
        // for .md/.txt files under docCap (50k) but over defaultCap (20k) are
        // incorrectly capped at the lower default cap.
        $path = $this->extractPathFromArguments(
            \is_array($message->details['arguments'] ?? null)
                ? $message->details['arguments']
                : [],
        );

        // Apply capping with a structured result.
        $capResult = $this->outputCap->capIfNeeded($combinedText, $path);

        if (null === $capResult) {
            // Text fits within the applicable cap — pass through unchanged.
            return $message;
        }

        // Build context-aware notice: read tools get original-path guidance,
        // generic tools use the default saved-artifact inspection notice.
        $readArgs = \is_array($message->details['arguments'] ?? null)
            ? $message->details['arguments']
            : [];
        $noticeText = $this->buildContextualNotice($message->toolName, $readArgs, $capResult);

        // Build new content: a text part with the capped notice,
        // plus all preserved non-text parts (image_refs).
        $newContent = [['type' => 'text', 'text' => $noticeText]];
        foreach ($nonTextParts as $part) {
            $newContent[] = $part;
        }

        // Build a generic model notification for downstream consumers
        // (agent message history preserves exact model-facing text).
        // Stable ID from tool_call_id + cap + original content hash so repeated
        // transforms of the same oversized message generate the same notification
        // ID and do not produce duplicate TUI blocks/events.  The ID does NOT
        // include savedPath or noticeText, both of which vary per invocation.
        $notificationId = hash('sha256', implode('|', [
            $message->toolCallId ?? 'none',
            'output_cap',
            (string) $capResult->cap,
            hash('sha256', $combinedText),
        ]));

        $notification = [
            'id' => $notificationId,
            'source' => 'output_cap',
            'kind' => 'output_capped',
            'severity' => 'warning',
            'delivery' => 'tool_result_replace',
            'text' => $noticeText,
            'metadata' => [
                'cap' => $capResult->cap,
                'char_count' => $capResult->charCount,
                'token_estimate' => $capResult->tokenEstimate,
                'saved_path' => $capResult->savedPath,
            ],
        ];
        if (null !== $message->toolCallId) {
            $notification['tool_call_id'] = $message->toolCallId;
        }
        if (null !== $message->toolName) {
            $notification['tool_name'] = $message->toolName;
        }

        // Attach the generic notification to both metadata (for the model history)
        // and details (so downstream skip detection works on re-capping).
        $metadata = $message->metadata;
        $metadata['model_notifications'] = [$notification];

        $details = \is_array($message->details) ? $message->details : [];
        $existing = \is_array($details['model_notifications'] ?? null)
            ? $details['model_notifications']
            : [];
        $existing[] = $notification;
        $details['model_notifications'] = $existing;

        return new AgentMessage(
            role: $message->role,
            content: $newContent,
            timestamp: $message->timestamp,
            name: $message->name,
            toolCallId: $message->toolCallId,
            toolName: $message->toolName,
            details: $details,
            isError: $message->isError,
            metadata: $metadata,
        );
    }

    private function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
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
        foreach (['path', 'file_path', 'file'] as $key) {
            $value = $arguments[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Check whether any notification in the list uses delivery=tool_result_replace.
     *
     * @param list<array<string, mixed>>|null $notifications
     */
    private function hasDeliveryToolResultReplace(?array $notifications): bool
    {
        if (null === $notifications) {
            return false;
        }

        foreach ($notifications as $notif) {
            if (!\is_array($notif)) {
                continue;
            }
            if (($notif['delivery'] ?? null) === 'tool_result_replace') {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a context-aware capping notice for the late defense-in-depth hook.
     *
     * For read tools: guides follow-up reads to the original file path with
     * offset+limit, avoiding double line numbers from reading the saved
     * rendered outcapac artifact.  For all other tools: uses the generic
     * saved-output inspection notice from OutputCap.
     *
     * @param array<string, mixed> $arguments
     */
    private function buildContextualNotice(?string $toolName, array $arguments, OutputCapResult $capResult): string
    {
        if ('read' !== $toolName) {
            return $capResult->noticeText;
        }

        $originalPath = $this->extractPathFromArguments($arguments);
        $originalOffset = $this->extractOffsetFromArguments($arguments);
        $offset = (\is_int($originalOffset) && $originalOffset > 0) ? $originalOffset : 1;
        $readPath = $originalPath ?? $capResult->savedPath;
        $escapedGrepPath = escapeshellarg($readPath);

        return <<<STRING
[Output capped: {$capResult->charCount} chars (~{$capResult->tokenEstimate} tokens) > {$capResult->cap}-char cap]
Saved full output: {$capResult->savedPath}

Next: use a focused follow-up, e.g.
- read(path: "{$readPath}", offset: {$offset}, limit: 200)
- bash(command: "grep -n -- 'PATTERN' {$escapedGrepPath} | head -50")
Do not rerun the original command or read the saved output with read.
STRING;
    }

    /**
     * Extract a numeric offset from tool call arguments.
     *
     * The read tool accepts an 'offset' argument (positive integer)
     * indicating the starting line for file read operations.  When
     * available, it is used in the read-tool cap notice to suggest
     * a reasonable follow-up starting point.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int|null the offset value, or null when absent or non-integer
     */
    private function extractOffsetFromArguments(array $arguments): ?int
    {
        $offset = $arguments['offset'] ?? null;

        if (\is_int($offset) && $offset > 0) {
            return $offset;
        }

        return null;
    }
}
