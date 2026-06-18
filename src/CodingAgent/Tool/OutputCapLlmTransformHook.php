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
 * details['arguments']) and resolves the path-specific cap via
 * OutputCap::capForPath() (docCap for .md/.txt/.toon, defaultCap
 * for everything else). The actual model-facing combined text
 * (including JSON wrapping from AgentMessageNormalizer) is
 * compared against that cap. This avoids false capping when
 * a doc-like file's output is above defaultCap but below docCap,
 * while still catching oversized JSON-wrapped text that would
 * slip past a raw-length check.
 * For tools with no path context in their arguments, the defaultCap
 * is used as defense-in-depth.
 *
 * STRUCTURED METADATA: When central capping is applied, the hook
 * attaches structured output_cap metadata (output_cap, output_cap_limit,
 * output_cap_char_count, output_cap_saved_path) to the AgentMessage
 * metadata. This flows through AgentMessageConverter to Symfony AI
 * message metadata, and from there to LlmPlatformAdapter::extractModelInputMessages()
 * for projection — no text parsing required.
 *
 * Per-tool output caps are detected via structured metadata
 * ($message->details['details']['output_cap'] === true) rather than
 * scanning tool output text.
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

        // ── Per-tool cap already applied (structured metadata) ──
        // If the tool handler returned ToolHandlerResultDTO with
        // output_cap metadata, the cap was already applied at the
        // tool level.  Skip central capping — the model-facing text
        // already contains the cap notice, and no re-processing is
        // needed.
        if ($this->isPerToolCapped($message)) {
            return $this->buildPassthroughMessage($message, $combinedText, $nonTextParts);
        }

        // ── Path-aware capping ──
        $path = $this->extractPath($message);
        $capResult = $this->outputCap->processDetailed($combinedText, $path);

        if (!$capResult->capped) {
            // Combined text fits under the path-specific cap →
            // no central capping needed.
            return $this->buildPassthroughMessage($message, $combinedText, $nonTextParts);
        }

        // ── Central cap applied ──
        // Build new content with the capped notice and preserved
        // non-text parts (image_refs).
        $newContent = [['type' => 'text', 'text' => $capResult->text]];
        foreach ($nonTextParts as $part) {
            $newContent[] = $part;
        }

        // Attach structured cap metadata so downstream consumers
        // (extractModelInputMessages, ToolProjectionSubscriber)
        // can read it without parsing the notice text.
        $metadata = $message->metadata;
        $metadata['output_cap'] = true;
        $metadata['output_cap_limit'] = $capResult->limit;
        $metadata['output_cap_char_count'] = $capResult->charCount;
        $metadata['output_cap_saved_path'] = $capResult->savedPath;

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

    /**
     * Check whether a per-tool output cap was already applied.
     *
     * Uses structured metadata from ToolHandlerResultDTO (stored
     * in details['details']['output_cap']) rather than scanning
     * the tool output text for cap markers.
     */
    private function isPerToolCapped(AgentMessage $message): bool
    {
        $detailsDetails = \is_array($message->details['details'] ?? null)
            ? $message->details['details']
            : null;

        if (null === $detailsDetails) {
            return false;
        }

        return true === ($detailsDetails['output_cap'] ?? false);
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
