<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\ReadFileArgumentsDTO;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

/**
 * Read a text file as plain UTF-8 content.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and the Symfony AI native tool contract (typed DTO arguments).
 * The provider schema is generated natively from ReadFileArgumentsDTO.
 *
 * Target preconditions (safety blocks, existence, readability, MIME/binary/
 * UTF-8/extension inspection, offset-past-EOF) are enforced before execution
 * by the {@see ReadFileTarget} class-level DTO constraint via
 * ValidateToolCallArgumentsListener; this handler only resolves, reads,
 * slices, and appends the continuation hint. An open/read failure here is an
 * operational/race error, not a validation result.
 *
 * Features:
 * - Output is plain file text (no line-number prefix).
 * - Offset and limit are 1-indexed (DTO-validated).
 * - Output passes through OutputCap for character-based capping.
 * - Large output is truncated at 2000 lines by default.
 * - Continuation hint appended when truncation occurs.
 * - Cancellation checkpoints wrap the read path.
 * - Unselected prefix/suffix lines are skipped in bounded chunks so a small
 *   selected range does not retain the rest of the file.
 */
final class ReadFileTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'read';

    public const string DESCRIPTION = 'Read a text file and return plain content. Supports offset (starting line) and limit (max lines) for reading specific sections. Binary files, image files, PDFs, and device paths are rejected.';

    /** Default maximum lines for an unrestricted read. */
    private const int DEFAULT_LINE_LIMIT = 2000;

    /** Max bytes retained while discarding one unselected line. */
    private const int SKIP_CHUNK_BYTES = 8192;

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    /**
     * Execute the read tool.
     *
     * Target validation (blocked paths, existence, readability, MIME/binary/
     * UTF-8/extension, offset-past-EOF) is enforced by the ReadFileTarget
     * DTO constraint before this handler runs; failures surface as
     * deterministic fault-tolerant results.
     *
     * @return string Plain file content, optionally capped or with continuation hints
     *
     * @throws ToolCallException on operational file read failures
     * @throws \RuntimeException on cancellation or timeout (runtime concerns)
     */
    public function __invoke(
        #[MapToolArguments]
        ReadFileArgumentsDTO $arguments,
    ): string {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $path = $arguments->path;
            $offset = $arguments->offset;
            $limit = $arguments->limit;

            // Resolve the path to an absolute, normalized form
            $resolvedPath = PathResolver::resolve($path);

            // Pre-execution target validation moved to the ReadFileTarget
            // DTO constraint (ValidateToolCallArgumentsListener).
            [$content, $totalLines] = $this->readSelectedContent($resolvedPath, $offset, $limit);

            // Check if the output was truncated and append continuation hint
            return $this->appendContinuationHint($content, $offset, $limit, $totalLines);
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'read path [offset=N] [limit=N] — read all or part of a text file as plain content; use view_image for images',
            promptGuidelines: [
                'Use offset and limit together for follow-up reads after large or capped output — avoid reading huge files wholesale.',
                'Binary files, image files, and PDFs are rejected — use view_image for images.',
                'Output may be capped by character limit and saved to a temporary file. Use read with offset and limit to inspect saved output in smaller chunks.',
                'Device paths (/dev/*) and /proc/*/fd/* paths are rejected for safety.',
            ],
        );
    }

    /**
     * Stream only the requested line range and count the remainder.
     *
     * Unselected lines are discarded with bounded fgets chunks so their bodies
     * are not retained. Selected lines keep file(..., FILE_IGNORE_NEW_LINES)
     * semantics, including multiple CR bytes before LF and a final CR without LF.
     *
     * @return array{0: string, 1: int}
     */
    private function readSelectedContent(string $resolvedPath, ?int $offset, ?int $limit): array
    {
        $handle = @fopen($resolvedPath, 'rb');
        if (false === $handle) {
            throw new ToolCallException(\sprintf('Failed to read file "%s".', $resolvedPath), retryable: true, hint: 'Check file permissions and disk health.');
        }

        try {
            $start = null !== $offset ? max(0, $offset - 1) : 0;
            $effectiveLimit = $limit ?? self::DEFAULT_LINE_LIMIT;

            for ($skipped = 0; $skipped < $start; ++$skipped) {
                if (!$this->advancePastLine($handle, $resolvedPath)) {
                    return ['', $skipped];
                }
            }

            $selected = [];
            while (\count($selected) < $effectiveLimit) {
                $line = fgets($handle);
                if (false === $line) {
                    if (!feof($handle)) {
                        throw new ToolCallException(\sprintf('Failed to read file "%s".', $resolvedPath), retryable: true, hint: 'Check file permissions and disk health.');
                    }

                    break;
                }

                $selected[] = $this->stripFileIgnoreNewlines($line);
            }

            $totalLines = $start + \count($selected);
            while ($this->advancePastLine($handle, $resolvedPath)) {
                ++$totalLines;
            }

            if ([] === $selected) {
                return ['', $totalLines];
            }

            // Output capping is handled centrally by OutputCapToolResultProcessor
            // after ToolExecutor converts the Symfony result to a domain ToolResult.
            return [implode("\n", $selected)."\n", $totalLines];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Discard one logical line without retaining its body.
     *
     * Uses length-limited fgets so a giant unselected line is released in
     * bounded chunks without seek-back rereads.
     *
     * @param resource $handle
     */
    private function advancePastLine($handle, string $resolvedPath): bool
    {
        $consumed = false;

        while (true) {
            $chunk = fgets($handle, self::SKIP_CHUNK_BYTES + 1);
            if (false === $chunk) {
                if (!feof($handle)) {
                    throw new ToolCallException(\sprintf('Failed to read file "%s".', $resolvedPath), retryable: true, hint: 'Check file permissions and disk health.');
                }

                return $consumed;
            }

            $consumed = true;
            if (str_ends_with($chunk, "\n") || feof($handle)) {
                return true;
            }
        }
    }

    /**
     * Match PHP file(..., FILE_IGNORE_NEW_LINES): strip a trailing LF and the
     * single CR immediately before it. Other CR bytes stay in the line body.
     */
    private function stripFileIgnoreNewlines(string $line): string
    {
        if (!str_ends_with($line, "\n")) {
            return $line;
        }

        $line = substr($line, 0, -1);
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }

        return $line;
    }

    /**
     * Append a continuation hint when the output was truncated.
     *
     * Checks the total line count of the file and adds a hint when there
     * is more content available beyond what was returned.
     *
     * @return string The original content, optionally with a continuation hint appended
     */
    private function appendContinuationHint(string $content, ?int $offset, ?int $limit, int $totalLines): string
    {
        // If the content is already empty, no hint needed
        if ('' === $content) {
            return $content;
        }

        // Count lines in the returned output
        $outputLines = substr_count($content, "\n");

        // Account for trailing newline
        if (!str_ends_with($content, "\n")) {
            ++$outputLines;
        }

        if (0 === $outputLines) {
            return $content;
        }

        // Determine the effective limit
        $effectiveLimit = $limit ?? self::DEFAULT_LINE_LIMIT;

        // If output lines are less than the limit, we didn't truncate
        if ($outputLines < $effectiveLimit) {
            return $content;
        }

        // Calculate the last line we returned
        $lastReturnedLine = null !== $offset ? $offset + $outputLines - 1 : $outputLines;

        if ($totalLines > $lastReturnedLine) {
            $nextOffset = $lastReturnedLine + 1;
            $hint = \sprintf("\n--- %d more lines (use read with offset=%d limit=200 to continue) ---\n", $totalLines - $lastReturnedLine, $nextOffset);

            return $content.$hint;
        }

        return $content;
    }
}
