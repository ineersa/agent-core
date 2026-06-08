<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ToolResultType;

/**
 * Central LLM-bound output capping for all tool-result text.
 *
 * Transforms tool-role AgentMessages before provider conversion so
 * oversized tool output never reaches the LLM, regardless of whether
 * the individual tool called OutputCap directly. Extension, MCP,
 * third-party, and future tools are all covered by this single hook
 * because it operates on the final AgentMessage list right before
 * LlmPlatformAdapter converts to Symfony AI message bags.
 *
 * This is defense-in-depth: raw persisted state (RunState messages,
 * session artifacts) remains uncapped; only the provider-facing copy
 * is truncated. Per-tool OutputCap calls in BashTool/ReadFileTool
 * remain in place because they produce useful persisted-output hints
 * near the tool result.
 *
 * Image content parts (image_ref) are preserved unchanged. The hook
 * trusts upstream image gating to strip image_refs for non-vision
 * models before reaching this point.
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

        // Cap the combined text. Null path uses defaultCap which is
        // appropriate for non-file tool output (logs, JSON blobs, etc.).
        $cappedText = $this->outputCap->process($combinedText, null);

        // Build new content: a text part with the capped content,
        // plus all preserved non-text parts (image_refs).
        $newContent = [['type' => 'text', 'text' => $cappedText]];
        foreach ($nonTextParts as $part) {
            $newContent[] = $part;
        }

        return new AgentMessage(
            role: $message->role,
            content: $newContent,
            timestamp: $message->timestamp,
            name: $message->name,
            toolCallId: $message->toolCallId,
            toolName: $message->toolName,
            details: $message->details,
            isError: $message->isError,
            metadata: $message->metadata,
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
