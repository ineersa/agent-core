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
 * /BgStatusTool remain in place because they produce useful
 * persisted-output hints near the tool result.
 *
 * DOUBLE-CAP PROTECTION: Tools known to apply their own OutputCap
 * internally (read, bash, bg_status) are NOT re-capped by this hook.
 * The per-tool cap already uses the correct path context (e.g. docCap
 * for .txt/.md files), and the AgentMessageNormalizer JSON-encodes
 * the ToolCallResult into content[0]['text'], so the text seen here
 * includes JSON wrapping overhead. Re-capping would truncate the
 * JSON structure and either produce a second useless capped notice
 * or strip characters from the actual tool content. A regression
 * guard checks raw_result: if it exceeds defaultCap and lacks the
 * capped-notice marker, the tool-level cap may have been bypassed,
 * and the central cap re-engages.
 *
 * Image content parts (image_ref) are preserved unchanged. The hook
 * trusts upstream image gating to strip image_refs for non-vision
 * models before reaching this point.
 */
final readonly class OutputCapLlmTransformHook implements TransformContextHookInterface
{
    /**
     * Tools known to apply their own OutputCap internally.
     * Their output should not be re-capped by the central hook
     * because the per-tool cap already used correct path context
     * (e.g. docCap for file paths), and the central hook sees
     * JSON-wrapped text that includes overhead from the
     * AgentMessageNormalizer.
     */
    private const KNOWN_CAPPING_TOOLS = ['read', 'bash', 'bg_status'];

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

        // ── Double-cap protection ──
        // Tools known to apply their own output capping (read, bash, bg_status)
        // already passed through OutputCap::process() with correct path context.
        // The central hook must NOT re-cap their JSON-wrapped output because:
        //   1. Per-tool path-aware capping (docCap for .txt/.md) already applied.
        //   2. JSON wrapping overhead can push combined text over defaultCap
        //      even when real content is legitimately under the appropriate cap.
        //   3. Re-capping would truncate the JSON structure, making the message
        //      unusable by the model.
        // For all other tools (extensions, MCP, third-party), central capping
        // remains active as defense-in-depth.
        if ($this->isAlreadyCappedByTool($message, $combinedText)) {
            return $this->buildPassthroughMessage($message, $combinedText, $nonTextParts);
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

    /**
     * Check whether a tool message has already been capped by its own
     * tool-level OutputCap call. For known-capping tools, skip the
     * central cap to avoid the double-cap/false-cap problem.
     *
     * Regression guard: if the raw tool output (stored in details) exceeds
     * defaultCap AND lacks the capped-notice marker, the tool-level cap
     * may have been bypassed — apply the central cap as defense-in-depth.
     */
    private function isAlreadyCappedByTool(AgentMessage $message, string $combinedText): bool
    {
        if (!\in_array($message->toolName, self::KNOWN_CAPPING_TOOLS, true)) {
            return false;
        }

        // Output already contains the capped-notice pattern → already capped.
        if (str_contains($combinedText, '[Output capped')) {
            return true;
        }

        // Guard against regression: check raw tool output stored in
        // details['details']['raw_result'] (the string returned by the
        // tool handler's __invoke before JSON wrapping).
        $rawResult = \is_array($message->details)
            && isset($message->details['details']['raw_result'])
            && \is_string($message->details['details']['raw_result'])
                ? $message->details['details']['raw_result']
                : null;

        if (null !== $rawResult && '' !== $rawResult) {
            $rawLen = mb_strlen($rawResult);
            $defaultCap = $this->outputCap->config()->defaultCap;

            // Raw output exceeds defaultCap with no capped-notice marker —
            // the tool-level cap was not triggered (regression or edge case).
            // Apply central cap as defense-in-depth.
            if ($rawLen > $defaultCap) {
                return false;
            }
        }

        // Output was already capped, or raw content fits under defaultCap.
        return true;
    }

    /**
     * Build a pass-through message with the same text and non-text parts
     * but as a new AgentMessage, skipping central capping entirely.
     *
     * @param list<array<string, mixed>> $nonTextParts
     */
    private function buildPassthroughMessage(AgentMessage $message, string $combinedText, array $nonTextParts): AgentMessage
    {
        $newContent = [['type' => 'text', 'text' => $combinedText]];
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
