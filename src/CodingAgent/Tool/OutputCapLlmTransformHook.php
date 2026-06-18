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
 * The hook now uses structured metadata (details['output_cap'])
 * instead of text-marker detection to avoid double-capping messages
 * that the primary processor already handled. When it does cap a
 * message, it attaches structured output_cap metadata so downstream
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
        // Detection uses structured details['output_cap'] instead of text markers.
        $alreadyCapped = \is_array($message->details['output_cap'] ?? null)
            && true === ($message->details['output_cap']['capped'] ?? false);

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

        // Apply capping with a structured result.
        $capResult = $this->outputCap->capIfNeeded($combinedText, null);

        if (null === $capResult) {
            // Text fits within the default cap — pass through unchanged.
            return $message;
        }

        // Build new content: a text part with the capped notice,
        // plus all preserved non-text parts (image_refs).
        $newContent = [['type' => 'text', 'text' => $capResult->noticeText]];
        foreach ($nonTextParts as $part) {
            $newContent[] = $part;
        }

        // Attach structured output_cap metadata so downstream consumers
        // (e.g. LlmPlatformAdapter) can detect capping without text parsing.
        $metadata = $message->metadata;
        $metadata['output_cap'] = [
            'capped' => true,
            'cap' => $capResult->cap,
            'char_count' => $capResult->charCount,
            'token_estimate' => $capResult->tokenEstimate,
            'saved_path' => $capResult->savedPath,
        ];

        return new AgentMessage(
            role: $message->role,
            content: $newContent,
            timestamp: $message->timestamp,
            name: $message->name,
            toolCallId: $message->toolCallId,
            toolName: $message->toolName,
            details: $message->details,
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
}
