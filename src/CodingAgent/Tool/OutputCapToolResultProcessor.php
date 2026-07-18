<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

use function Symfony\Component\String\u;

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
    /**
     * Conventional tool argument keys used to determine path-specific caps.
     *
     * When a tool call carries one of these argument keys, its value is used
     * to select the applicable cap: doc-like extensions (.md, .txt, .toon)
     * get the higher docCap; everything else gets defaultCap.
     *
     * New tools with a different path argument name should either adopt one
     * of these conventional keys or extend this list in the processor.
     *
     * @var list<string>
     */
    private const array PATH_ARGUMENT_KEYS = ['path', 'file_path', 'file'];

    /**
     * Tools whose successful result is a dense document-style report/handoff.
     *
     * These tools have no path argument, so path-based cap selection would
     * otherwise fall back to defaultCap (20k). Classify them as doc-like so
     * they use docCap (50k) without raising the global default for code/tool
     * output. Keep this list narrow: handoff/report tools only.
     *
     * @var list<string>
     */
    private const array DOCUMENT_REPORT_TOOL_NAMES = ['fork', 'subagent'];

    public function __construct(
        private OutputCap $outputCap,
    ) {
    }

    public function process(ToolResult $result, ToolCall $toolCall): ToolResult
    {
        $text = $this->extractTextFromContent($result->content);
        if ('' === $text) {
            return $result;
        }

        $path = $this->resolveCapPath($toolCall, $result);
        $cap = $this->outputCap->capForPath($path);
        $charCount = u($text)->length();

        if ($charCount <= $cap) {
            // Text fits within the cap — return unchanged.
            return $result;
        }

        $capResult = $this->outputCap->capIfNeeded($text, $path);
        // capIfNeeded returns null when under cap, so this path always has a result.
        if (null === $capResult) {
            return $result;
        }

        // Build context-aware notice: read tools get original-path guidance,
        // generic tools use the default saved-artifact inspection notice.
        $noticeText = $this->buildContextualNotice($toolCall, $capResult);

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
        $existingNotifications[] = $notification->toArray();
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
     * Build a context-aware capping notice.
     *
     * For read tools: guides follow-up reads to the original file path with
     * offset+limit, avoiding double line numbers from reading the saved
     * rendered artifact.  For all other tools: uses the generic saved-output
     * inspection notice from OutputCap.
     */
    private function buildContextualNotice(ToolCall $toolCall, OutputCapResult $capResult): string
    {
        if ('read' !== $toolCall->toolName) {
            return $capResult->noticeText;
        }

        $originalPath = $this->extractPathFromArguments($toolCall->arguments);

        // Only produce read-specific notice when we have the original path.
        // Without it, fall back to the generic saved-artifact notice (head/grep).
        if (null === $originalPath) {
            return $capResult->noticeText;
        }

        $originalOffset = $this->extractOffsetFromArguments($toolCall->arguments);
        $offset = (\is_int($originalOffset) && $originalOffset > 0) ? $originalOffset : 1;
        $escapedGrepPath = escapeshellarg($originalPath);

        return <<<STRING
[Output capped: {$capResult->charCount} chars (~{$capResult->tokenEstimate} tokens) > {$capResult->cap}-char cap]
Saved full output: {$capResult->savedPath}

Next: use a focused follow-up, e.g.
- read(path: "{$originalPath}", offset: {$offset}, limit: 200)
- bash(command: "grep -n -- 'PATTERN' {$escapedGrepPath} | head -50")
Do not repeat the original full read or read the saved output with read.
STRING;
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
     * Resolve the path used for cap selection.
     *
     * Preference order:
     * 1. Explicit path-like tool argument (read/bash file context).
     * 2. Synthetic .md path for successful document-report tools (fork/subagent)
     *    so OutputCap::capForPath applies docCap without changing defaultCap.
     * 3. null → defaultCap.
     *
     * Error results from report tools keep defaultCap (null path): failed
     * envelopes are short status text, not handoff documents.
     */
    private function resolveCapPath(ToolCall $toolCall, ToolResult $result): ?string
    {
        $path = $this->extractPathFromArguments($toolCall->arguments);
        if (null !== $path) {
            return $path;
        }

        if (!$result->isError && \in_array($toolCall->toolName, self::DOCUMENT_REPORT_TOOL_NAMES, true)) {
            // Virtual doc path: only used for extension-based docCap selection.
            return 'handoff-report.md';
        }

        return null;
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
