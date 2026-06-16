<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Symfony\Component\Process\Process;

/**
 * Read a text file with `cat -n` styled line numbers.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * Features:
 * - Output uses `cat -n` style with original file line numbers.
 * - Offset and limit are 1-indexed and validated.
 * - Binary, non-UTF-8, image, and device files are rejected.
 * - Output passes through OutputCap for character-based capping.
 * - Large output is truncated at 2000 lines by default (via head).
 * - Continuation hint appended when truncation occurs.
 * - Cancellation checks before and after process execution.
 */
final class ReadFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    /** Default maximum lines for an unrestricted read. */
    private const int DEFAULT_LINE_LIMIT = 2000;

    /** @var list<string> Path prefixes that are obviously non-file resources. */
    private const array BLOCKED_PATH_PREFIXES = [
        '/dev/',
    ];

    /** @var list<string> Patterns for path segments that should be rejected. */
    private const array BLOCKED_PATH_PATTERNS = [
        '#^/proc/\d+/fd/#',
    ];

    /** @var list<string> Extensions that are likely image files. */
    private const array IMAGE_EXTENSIONS = [
        '.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp',
        '.svg', '.ico', '.tiff', '.tif', '.avif', '.heic', '.heif',
    ];

    /** @var list<string> Extensions that are non-text documents. */
    private const array BINARY_DOC_EXTENSIONS = [
        '.pdf', '.ipynb', '.xcf', '.psd', '.ai', '.eps',
        '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
        '.odt', '.ods', '.odp', '.zip', '.tar', '.gz',
    ];

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly OutputCap $outputCap,
    ) {
    }

    /**
     * Execute the read tool.
     *
     * @param array<string, mixed> $arguments Must contain 'path' (string).
     *                                        Optional 'offset' (int|null) and
     *                                        'limit' (int|null).
     *
     * @return string File content with cat -n line numbering,
     *                optionally capped or with continuation hints
     *
     * @throws ToolCallException on validation failures or tool-level errors
     * @throws \RuntimeException on cancellation or timeout (runtime concerns)
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            // Validate and extract arguments
            $path = $this->validatePath($arguments);
            $offset = $this->validateOffset($arguments);
            $limit = $this->validateLimit($arguments);

            // Resolve the path to an absolute, normalized form
            $resolvedPath = PathResolver::resolve($path);

            // Pre-flight validation
            $this->validateTarget($resolvedPath);

            // Read the file content via Unix pipeline
            $content = $this->readContent($resolvedPath, $offset, $limit);

            // Detect offset past EOF for non-empty files
            if ('' === $content && null !== $offset) {
                $totalLines = $this->countTotalLines($resolvedPath);
                if (null !== $totalLines && $offset > $totalLines) {
                    throw new ToolCallException(\sprintf('Cannot read "%s": offset %d exceeds file length (%d lines).', $resolvedPath, $offset, $totalLines), retryable: false, hint: \sprintf('The file has %d lines. Use an offset between 1 and %d, or omit offset to read from the beginning.', $totalLines, $totalLines));
                }
            }

            // Check if the output was truncated and append continuation hint
            $content = $this->appendContinuationHint($content, $resolvedPath, $offset, $limit);

            // Pass through output capping (character-based)
            return $this->outputCap->process($content, $resolvedPath);
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'read',
            description: 'Read a text file and display its content with original line numbers. Supports offset (starting line) and limit (max lines) for reading specific sections. Binary files, image files, PDFs, and device paths are rejected.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path to read (absolute, or relative to the working directory)',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Starting line number (1-indexed). Omit to read from the beginning.',
                        'minimum' => 1,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of lines to return. Omit to use the default cap (2000 lines).',
                        'minimum' => 1,
                    ],
                ],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'read path [offset=N] [limit=N] — read a text file with cat -n line numbers; supports offset and limit for partial reads; use view_image for images',
            promptGuidelines: [
                'Output uses cat -n line numbering with original file line numbers.',
                'Use offset (starting line, 1-indexed) and limit (max lines) to read specific sections of large files.',
                'Reading without offset/limit returns up to 2000 lines from the beginning.',
                'Binary files, image files, and PDFs are rejected — use view_image for images.',
                'Output is capped by character limit. Very large output may be saved to a file for inspection.',
                'Device paths (/dev/*) and /proc/*/fd/* paths are rejected for safety.',
            ],
        );
    }

    /**
     * Validate the path argument.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string The validated path
     *
     * @throws ToolCallException when the path argument is missing or invalid
     */
    private function validatePath(array $arguments): string
    {
        $path = $arguments['path'] ?? null;

        if (!\is_string($path) || '' === $path) {
            throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a valid file path to read.');
        }

        return $path;
    }

    /**
     * Validate the optional offset argument.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int|null The validated offset (1-indexed, positive), or null if not provided
     *
     * @throws ToolCallException when offset is invalid
     */
    private function validateOffset(array $arguments): ?int
    {
        $offset = $arguments['offset'] ?? null;

        if (null === $offset) {
            return null;
        }

        if (!\is_int($offset)) {
            throw new ToolCallException('The "offset" argument must be an integer.', retryable: false, hint: 'Provide offset as an integer (e.g., offset=10).');
        }

        if ($offset < 1) {
            throw new ToolCallException('The "offset" argument must be a positive integer (1-indexed).', retryable: false, hint: 'Line numbers start at 1. Use offset=1 to read from the beginning.');
        }

        return $offset;
    }

    /**
     * Validate the optional limit argument.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int|null The validated limit (positive), or null if not provided
     *
     * @throws ToolCallException when limit is invalid
     */
    private function validateLimit(array $arguments): ?int
    {
        $limit = $arguments['limit'] ?? null;

        if (null === $limit) {
            return null;
        }

        if (!\is_int($limit)) {
            throw new ToolCallException('The "limit" argument must be an integer.', retryable: false, hint: 'Provide limit as an integer (e.g., limit=100).');
        }

        if ($limit < 1) {
            throw new ToolCallException('The "limit" argument must be a positive integer.', retryable: false, hint: 'Provide a positive limit of at least 1 line.');
        }

        return $limit;
    }

    /**
     * Validate the resolved target path.
     *
     * Checks for device paths, existence, readable, regular file,
     * binary content, UTF-8 validity, and non-text MIME types.
     *
     * @throws ToolCallException when the target is invalid
     */
    private function validateTarget(string $resolvedPath): void
    {
        // Block obvious device/fd paths
        foreach (self::BLOCKED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($resolvedPath, $prefix)) {
                throw new ToolCallException(\sprintf('Cannot read "%s": device paths are rejected for safety.', $resolvedPath), retryable: false, hint: 'Specify a regular file path.');
            }
        }

        foreach (self::BLOCKED_PATH_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $resolvedPath)) {
                throw new ToolCallException(\sprintf('Cannot read "%s": this special path is rejected for safety.', $resolvedPath), retryable: false, hint: 'Specify a regular file path.');
            }
        }

        // Check file existence
        if (!file_exists($resolvedPath)) {
            throw new ToolCallException(\sprintf('File "%s" does not exist.', $resolvedPath), retryable: false, hint: 'Check the file path and try again.');
        }

        // Check if it's a regular file (not a directory, socket, etc.)
        if (!is_file($resolvedPath)) {
            throw new ToolCallException(\sprintf('"%s" is not a regular file.', $resolvedPath), retryable: false, hint: 'Use the read tool only for regular files.');
        }

        // Check readability
        if (!is_readable($resolvedPath)) {
            throw new ToolCallException(\sprintf('File "%s" is not readable.', $resolvedPath), retryable: true, hint: 'Check file permissions and try again.');
        }

        // Check file is non-empty (empty files are fine for cat -n, skip expensive checks)
        $fileSize = @filesize($resolvedPath);
        if (false !== $fileSize && 0 === $fileSize) {
            return; // Empty file is valid text content
        }

        // Binary, UTF-8, and MIME type check from sample buffer
        $sample = $this->readSample($resolvedPath);

        // Reject images and other non-text MIME types FIRST.
        // Checked early so images get a helpful "use view_image" hint instead of
        // a generic "binary" or "non-UTF-8" error, since image magic bytes often
        // contain null bytes and non-UTF-8 sequences.
        $this->rejectNonTextMime($sample, $resolvedPath);

        // Reject binary files (containing null bytes)
        if (str_contains($sample, "\0")) {
            throw new ToolCallException(\sprintf('Cannot read "%s": file appears to be binary (contains null bytes).', $resolvedPath), retryable: false, hint: 'Use the view_image tool for image files. Binary code files (.so, .dll, etc.) are not supported by the read tool.');
        }

        // Reject non-UTF-8 content
        if (!mb_check_encoding($sample, 'UTF-8')) {
            throw new ToolCallException(\sprintf('Cannot read "%s": file contains non-UTF-8 encoded content.', $resolvedPath), retryable: false, hint: 'Convert the file to UTF-8 encoding first, or use a binary-safe tool.');
        }

        // Reject by extension as a secondary check for files finfo might not catch
        $this->rejectByExtension($resolvedPath);
    }

    /**
     * Read an 8KB sample from the file for content inspection.
     *
     * @return string Up to 8192 bytes from the start of the file
     *
     * @throws ToolCallException when the file cannot be read
     */
    private function readSample(string $resolvedPath): string
    {
        $fh = @fopen($resolvedPath, 'r');
        if (false === $fh) {
            $lastError = error_get_last();
            $diagnostic = $lastError['message'] ?? 'Failed to open file for inspection';
            throw new ToolCallException(\sprintf('Unable to inspect file "%s": %s', $resolvedPath, $diagnostic), retryable: true, hint: 'Check file permissions and disk health.');
        }

        $sample = @fread($fh, 8192);
        if (false === $sample) {
            $lastError = error_get_last();
            $diagnostic = $lastError['message'] ?? 'Failed to read sample from file';
            @fclose($fh);
            throw new ToolCallException(\sprintf('Unable to inspect file "%s": %s', $resolvedPath, $diagnostic), retryable: true, hint: 'Check disk health and file integrity.');
        }

        @fclose($fh);

        return $sample;
    }

    /**
     * Reject non-text MIME types (images, PDFs, archives, etc.).
     *
     * @throws ToolCallException when MIME type is not text-safe
     */
    private function rejectNonTextMime(string $sample, string $resolvedPath): void
    {
        $detector = new FinfoMimeTypeDetector();
        $mimeType = $detector->detectMimeTypeFromBuffer($sample);

        if (null === $mimeType || '' === $mimeType) {
            return; // Cannot determine MIME type, proceed
        }

        // Reject images with a specific instruction to use view_image
        if (str_starts_with($mimeType, 'image/')) {
            throw new ToolCallException(\sprintf('Cannot read "%s": file type "%s" is an image. Use the view_image tool instead.', $resolvedPath, $mimeType), retryable: false, hint: 'Use the view_image tool to view image files.');
        }

        // Reject other non-text binary types
        $nonTextPrefixes = [
            'video/',
            'audio/',
            'application/zip',
            'application/gzip',
            'application/x-rar',
            'application/x-7z',
            'application/x-bzip',
            'application/pdf',
            'application/vnd.',
            'application/msword',
            'application/x-ms',
            'application/x-dosexec',
            'application/x-sharedlib',
            'application/x-executable',
            'application/x-object',
            'application/x-archive',
            'application/x-tar',
            'application/x-compress',
            'application/octet-stream',
        ];

        foreach ($nonTextPrefixes as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                throw new ToolCallException(\sprintf('Cannot read "%s": file type "%s" is not a readable text format.', $resolvedPath, $mimeType), retryable: false, hint: 'This file type is not supported by the read tool. Use the view_image tool for images.');
            }
        }
    }

    /**
     * Reject files with non-text extensions as a secondary check.
     *
     * @throws ToolCallException when the extension indicates a non-text file
     */
    private function rejectByExtension(string $resolvedPath): void
    {
        $lowerPath = strtolower($resolvedPath);

        foreach (self::IMAGE_EXTENSIONS as $ext) {
            if (str_ends_with($lowerPath, $ext)) {
                throw new ToolCallException(\sprintf('Cannot read "%s": this looks like an image file. Use the view_image tool instead.', $resolvedPath), retryable: false, hint: 'Use the view_image tool to view image files.');
            }
        }

        foreach (self::BINARY_DOC_EXTENSIONS as $ext) {
            if (str_ends_with($lowerPath, $ext)) {
                throw new ToolCallException(\sprintf('Cannot read "%s": this looks like a binary document format. Use the view_image tool for images, or check if the file can be converted to text.', $resolvedPath), retryable: false, hint: 'Binary document formats (.pdf, .docx, .ipynb, etc.) are not supported by the read tool.');
            }
        }
    }

    /**
     * Read file content using a Unix pipeline with cat -n line numbering.
     *
     * @return string File content with cat -n line numbers
     *
     * @throws ToolCallException when the process fails
     * @throws \RuntimeException on cancellation or timeout
     */
    private function readContent(string $resolvedPath, ?int $offset, ?int $limit): string
    {
        $pathArg = escapeshellarg($resolvedPath);
        $effectiveLimit = $limit ?? self::DEFAULT_LINE_LIMIT;

        if (null !== $offset && null !== $limit) {
            $end = $offset + $limit - 1;
            $cmd = \sprintf('cat -n %s | sed -n \'%d,%dp\'', $pathArg, $offset, $end);
        } elseif (null !== $offset) {
            $cmd = \sprintf('cat -n %s | sed -n \'%d,$p\'', $pathArg, $offset);
        } else {
            $cmd = \sprintf('cat -n %s | head -n %d', $pathArg, $effectiveLimit);
        }

        $process = new Process(['bash', '-c', $cmd]);
        $result = $this->toolRuntime->runCancellableProcess($process);

        if ($result->cancelled) {
            throw new \RuntimeException('Tool execution was cancelled during file read.');
        }

        if ($result->timedOut) {
            throw new \RuntimeException('File read timed out.');
        }

        if (0 !== $result->exitCode) {
            $errorOutput = '' !== $result->stderr ? $result->stderr : $result->stdout;
            throw new ToolCallException(\sprintf('Failed to read file "%s": %s', $resolvedPath, trim($errorOutput)), retryable: true, hint: 'The file may be too large or unreadable. Try reading a specific section with offset and limit.');
        }

        return $result->stdout;
    }

    /**
     * Append a continuation hint when the output was truncated.
     *
     * Checks the total line count of the file and adds a hint when there
     * is more content available beyond what was returned.
     *
     * @return string The original content, optionally with a continuation hint appended
     */
    private function appendContinuationHint(string $content, string $resolvedPath, ?int $offset, ?int $limit): string
    {
        // If the content is already empty, no hint needed
        if ('' === $content) {
            return $content;
        }

        // Count lines in the cat -n output
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

        // Check total file lines (gracefully handles cancellation/timeout/failure)
        $totalLines = $this->countTotalLines($resolvedPath);
        if (null === $totalLines) {
            return $content; // Cannot determine total, skip hint
        }

        // Calculate the last line we returned
        $lastReturnedLine = null !== $offset ? $offset + $outputLines - 1 : $outputLines;

        if ($totalLines > $lastReturnedLine) {
            $nextOffset = $lastReturnedLine + 1;
            $hint = \sprintf("\n--- %d more lines (use `read` with offset=%d to continue) ---\n", $totalLines - $lastReturnedLine, $nextOffset);

            return $content.$hint;
        }

        return $content;
    }

    /**
     * Count total lines in a file matching cat -n numbering.
     *
     * Uses awk which correctly counts the last line even without a
     * trailing newline (unlike wc -l).
     *
     * Returns null when the count cannot be determined (cancelled, timed out,
     * process failure, or empty output).
     *
     * @return int|null Total line count, or null on failure
     */
    private function countTotalLines(string $resolvedPath): ?int
    {
        $wcProcess = new Process(['bash', '-c', \sprintf("awk 'END {print NR}' %s", escapeshellarg($resolvedPath))]);
        $wcResult = $this->toolRuntime->runCancellableProcess($wcProcess);

        if ($wcResult->cancelled || $wcResult->timedOut || 0 !== $wcResult->exitCode || '' === trim($wcResult->stdout)) {
            return null; // Cannot determine total, graceful degradation
        }

        return (int) trim($wcResult->stdout);
    }
}
