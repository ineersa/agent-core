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
 * PATH-AWARE CAPPING: The hook extracts the original tool call
 * arguments (persisted by ExecuteToolCallWorker as
 * details['arguments']) and checks the raw tool output length
 * against the correct cap for that path (docCap for .md/.txt/.toon,
 * defaultCap for everything else). This avoids false capping when
 * a doc-like file's output is above defaultCap but below docCap.
 * For tools with no path context in their arguments, the defaultCap
 * is used as defense-in-depth.
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

        // ── Path-aware capping ──
        // Extract path context from tool arguments (if available) so
        // doc-like files use docCap instead of the smaller defaultCap.
        // Skip central capping entirely when raw tool output already
        // fits under the path-specific cap or was already capped by
        // the per-tool OutputCap call.
        if ($this->shouldSkipCentralCap($message, $combinedText)) {
            return $this->buildPassthroughMessage($message, $combinedText, $nonTextParts);
        }

        $path = $this->extractPath($message);
        $cappedText = $this->outputCap->process($combinedText, $path);

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
     * Determine whether central capping should be skipped.
     *
     * Returns true (skip central cap) when:
     * 1. The combined text already contains a capped-notice marker
     *    (per-tool cap already applied).
     * 2. Raw tool output fits under the path-specific cap
     *    (per-tool cap correctly handled it or output was never
     *    above the threshold for this file type).
     *
     * Returns false when:
     * - Raw output exceeds the path-specific cap (cap with correct cap).
     * - No raw_result available (apply defaultCap defense-in-depth).
     */
    private function shouldSkipCentralCap(AgentMessage $message, string $combinedText): bool
    {
        // Already has a capped-notice marker → per-tool cap already applied.
        if (str_contains($combinedText, '[Output capped')) {
            return true;
        }

        // Extract raw tool output for length comparison against the
        // correct cap. This avoids false-capping doc-like output that
        // is above defaultCap but below docCap.
        $rawResult = $this->extractRawResult($message);

        if (null === $rawResult || '' === $rawResult) {
            // No raw_result to inspect → can't prove output fits;
            // apply central cap as defense-in-depth.
            return false;
        }

        // Extract path context (may be null, which resolves to defaultCap).
        $path = $this->extractPath($message);
        $applicableCap = $this->outputCap->capForPath($path);

        // Raw output fits under the applicable cap → per-tool cap was
        // either already applied with the correct path context, or
        // output was never above the threshold. Skip central capping.
        if (mb_strlen($rawResult) <= $applicableCap) {
            return true;
        }

        // Raw output exceeds the applicable cap → central cap needed.
        return false;
    }

    /**
     * Extract the original tool call arguments path from the message
     * details. Tools like read and bg_status(log) carry a 'path'
     * argument that determines the correct cap (docCap vs defaultCap).
     *
     * @return string|null the file path from tool arguments, or null
     *                     if no path context is available
     */
    private function extractPath(AgentMessage $message): ?string
    {
        $arguments = \is_array($message->details['arguments'] ?? null)
            ? $message->details['arguments']
            : null;

        if (null === $arguments) {
            return null;
        }

        $path = $arguments['path'] ?? null;

        return \is_string($path) && '' !== $path ? $path : null;
    }

    /**
     * Extract the raw tool output from the message details.
     *
     * The raw_result is the return value of the tool handler's
     * __invoke() before JSON wrapping. It is stored by ToolExecutor
     * in details['details']['raw_result'].
     *
     * @return string|null the raw tool output, or null if unavailable
     */
    private function extractRawResult(AgentMessage $message): ?string
    {
        $rawResult = \is_array($message->details)
            && isset($message->details['details']['raw_result'])
            && \is_string($message->details['details']['raw_result'])
                ? $message->details['details']['raw_result']
                : null;

        return $rawResult;
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
