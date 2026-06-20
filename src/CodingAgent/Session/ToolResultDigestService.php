<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Symfony\Component\String\UnicodeString;

/**
 * Deterministic tool-result placeholder/digesting for summarization.
 *
 * Replaces raw tool output with a bounded digest before the summarization
 * LLM call so the model sees a concise summary of each tool result rather
 * than huge raw output.
 *
 * The digest is purely deterministic (no LLM calls). Fields:
 *   - tool name, tool_call_id
 *   - command (when detectable from details) and exit code
 *   - status (ERROR/ok/exit code N)
 *   - estimated tokens and character count
 *   - bounded preview_start / preview_end snippets
 *   - full_output blob path when detectable
 *   - important_lines_detected (FAIL, ERROR, Exception, Traceback, Fatal,
 *     and src/path:line patterns) with bounded line count
 */
final class ToolResultDigestService
{
    /** Maximum preview snippet length for each of preview_start/preview_end */
    private const int PREVIEW_LENGTH = 500;

    /** Maximum number of important lines included in the digest */
    private const int MAX_IMPORTANT_LINES = 15;

    public function __construct(
        private readonly CompactionTokenEstimator $tokenEstimator,
    ) {
    }

    /**
     * Transform tool messages into deterministic digest/placeholder content.
     *
     * Only tool results are replaced; all other messages pass through
     * unchanged. The original session messages are never mutated — this
     * operates on a copy of the summarize partition.
     *
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    public function digestToolResults(array $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            if ('tool' !== $message->role) {
                $result[] = $message;

                continue;
            }

            $result[] = $this->buildToolDigest($message);
        }

        return $result;
    }

    /**
     * Build a deterministic digest AgentMessage for a tool result.
     */
    private function buildToolDigest(AgentMessage $toolMessage): AgentMessage
    {
        $originalText = $this->tokenEstimator->messageToText($toolMessage);
        $text = new UnicodeString($originalText);
        $charCount = $text->length();

        // ── Status and exit code ──
        $status = $toolMessage->isError ? 'ERROR' : 'ok';
        $exitCode = null;

        if (\is_array($toolMessage->details) && isset($toolMessage->details['exit_code'])) {
            $exitCode = $toolMessage->details['exit_code'];
            if (0 !== $exitCode) {
                $status = 'exit code '.$exitCode;
            }
        }

        // ── Command detection ──
        $command = null;
        if (\is_array($toolMessage->details)) {
            $command = $toolMessage->details['command'] ?? null;
            if (!\is_string($command)) {
                $command = null;
            }
        }

        // ── Full output blob path ──
        $fullOutput = null;
        if (\is_array($toolMessage->details)) {
            $fullOutput = $toolMessage->details['blob_path']
                ?? $toolMessage->details['output_path']
                ?? $toolMessage->details['full_output']
                ?? null;
            if (!\is_string($fullOutput)) {
                $fullOutput = null;
            }
        }

        // ── Preview start / end ──
        $previewStart = '';
        $previewEnd = '';

        if ($charCount <= self::PREVIEW_LENGTH * 2) {
            $previewStart = $originalText;
            $previewEnd = '';
        } else {
            $previewStart = $text->slice(0, self::PREVIEW_LENGTH)->toString();
            $previewEnd = $text->slice(-self::PREVIEW_LENGTH)->toString();
        }

        // ── Important lines ──
        $importantLines = $this->extractImportantLines($originalText);

        // ── Assemble digest ──
        $lines = [
            '[tool output elided before summarization]',
            \sprintf('tool: %s', $toolMessage->toolName ?? 'unknown'),
            \sprintf('tool_call_id: %s', $toolMessage->toolCallId ?? '(none)'),
        ];

        if (null !== $command) {
            $lines[] = \sprintf('command: %s', $command);
        }

        $lines[] = \sprintf('exit_code: %s', $exitCode ?? 'unknown');
        $lines[] = \sprintf('status: %s', $status);
        $lines[] = \sprintf('estimated_tokens: ~%d', $this->tokenEstimator->estimateMessageTokens($toolMessage));
        $lines[] = \sprintf('char_count: %d', $charCount);

        if (null !== $fullOutput) {
            $lines[] = \sprintf('full_output: %s', $fullOutput);
        }

        if ([] !== $importantLines) {
            $lines[] = '';
            $lines[] = 'important_lines_detected:';
            foreach ($importantLines as $line) {
                $lines[] = \sprintf('- %s', $line);
            }
        }

        $lines[] = '';
        $lines[] = 'preview_start:';
        $lines[] = $previewStart;

        if ('' !== $previewEnd) {
            $lines[] = '';
            $lines[] = 'preview_end:';
            $lines[] = $previewEnd;
        }

        return new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => implode("\n", $lines)]],
            toolCallId: $toolMessage->toolCallId,
            toolName: $toolMessage->toolName,
        );
    }

    /**
     * Extract important lines from tool output using cheap deterministic
     * patterns. Bounded to MAX_IMPORTANT_LINES.
     *
     * @return list<string>
     */
    private function extractImportantLines(string $text): array
    {
        $important = [];
        $patterns = [
            '/\b(FAIL|FAILURE|FAILED)\b/',
            '/\b(ERROR)\b/',
            '/\b(Exception)\b/',
            '/\b(Traceback \(most recent call last\))/',
            '/\b(Fatal error)\b/',
            '/\b(src\/[^\s:]+:\d+)\b/', // file_path:line_number
        ];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $important[] = mb_substr($line, 0, 200);

                    break;
                }
            }

            if (\count($important) >= self::MAX_IMPORTANT_LINES) {
                break;
            }
        }

        return $important;
    }
}
