<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Path\PathResolver;
use Symfony\Component\Process\Process;

/**
 * Edit an existing file by applying a unified diff patch.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * Uses a two-pass approach around GNU patch for safety:
 * 1. Dry-run validation (--dry-run --posix) — never touches the target.
 * 2. Apply with -o temp output — patches a temp copy, then atomically
 *    renames it to the target on success.
 *
 * Features:
 * - Whitespace-tolerant matching via patch -l flag.
 * - 3-line fuzz tolerance via patch -F3 flag.
 * - Forward-only application via patch -N flag.
 * - Original file is never modified until a successful apply + rename.
 * - Cancellation before the final rename leaves the original untouched.
 */
final class EditFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    /**
     * Execute the edit tool.
     *
     * @param array<string, mixed> $arguments Must contain 'path' (string) and 'patch' (string)
     *
     * @return string Success message with addition/deletion stats, or a no-op message
     *
     * @throws ToolCallException on validation failures, patch failures, or tool-level errors
     * @throws \RuntimeException on cancellation or timeout (runtime concerns)
     */
    public function __invoke(array $arguments): string
    {
        $this->validateArguments($arguments);

        // Wrap core logic in cancellation checkpoints
        return $this->toolRuntime->run(function () use ($arguments): string {
            $targetPath = $this->resolveAndVerifyTarget($arguments['path']);
            $patchContent = $arguments['patch'];

            $stats = $this->applyPatch($targetPath, $patchContent);

            return $this->formatSuccess($targetPath, $stats['additions'], $stats['deletions']);
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'edit',
            description: 'Apply a unified diff patch to an existing file. The target file must exist; use the write tool for new files.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path to edit (absolute, or relative to the working directory)',
                    ],
                    'patch' => [
                        'type' => 'string',
                        'description' => 'Unified diff in standard format',
                    ],
                ],
                'required' => ['path', 'patch'],
                'additionalProperties' => false,
            ],
            handler: $this,
            promptLine: 'edit path patch — apply a unified diff patch to an existing file; file must already exist, use write for new files',
            promptGuidelines: [
                'Provide the patch in standard unified diff format (diff -u).',
                'The patch may contain multiple hunks to edit different parts of the file.',
                'Whitespace mismatches between the patch and the target file are handled automatically (tolerant matching).',
                'The target file must already exist — use the write tool to create new files.',
                'Returns a summary of additions and deletions applied.',
                'If the patch produces no changes, the tool reports "No changes" without modifying the file.',
            ],
        );
    }

    /**
     * Validate that required arguments exist and have correct types.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ToolCallException when arguments are missing or invalid
     */
    private function validateArguments(array $arguments): void
    {
        $path = $arguments['path'] ?? null;
        $patch = $arguments['patch'] ?? null;

        if (!\is_string($path) || '' === $path) {
            throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a valid file path.');
        }

        if (!\is_string($patch) || '' === $patch) {
            throw new ToolCallException('The "patch" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a unified diff in standard format.');
        }
    }

    /**
     * Resolve the path and verify the target file exists and is readable.
     *
     * @return string Absolute path to the target file
     *
     * @throws ToolCallException when the file does not exist or is not readable
     */
    private function resolveAndVerifyTarget(string $path): string
    {
        $targetPath = PathResolver::resolve($path);

        if (!is_file($targetPath) || !is_readable($targetPath)) {
            throw new ToolCallException(\sprintf('File "%s" does not exist or is not readable.', $targetPath), retryable: false, hint: 'Use the write tool to create new files.');
        }

        return $targetPath;
    }

    /**
     * Orchestrate the full patch lifecycle: write temp file, dry-run, apply,
     * check no-op, atomic rename, and stats.
     *
     * @return array{additions: int, deletions: int}
     *
     * @throws ToolCallException on patch failures
     * @throws \RuntimeException on cancellation or infrastructure errors
     */
    private function applyPatch(string $targetPath, string $patchContent): array
    {
        $patchFile = $this->writePatchFile($patchContent);
        $tempOut = tempnam(sys_get_temp_dir(), 'hatfield_out_');

        try {
            $this->dryRun($targetPath, $patchFile);
            $this->applyPatches($targetPath, $patchFile, $tempOut);

            $patchedContent = @file_get_contents($tempOut);
            if (false === $patchedContent) {
                throw new \RuntimeException('Failed to read patched output file.');
            }

            // Check for no-op before replacing the original
            $originalContent = @file_get_contents($targetPath);
            if (false !== $originalContent && $patchedContent === $originalContent) {
                return ['additions' => 0, 'deletions' => 0, 'noop' => true];
            }

            // Atomic replace
            if (!@rename($tempOut, $targetPath)) {
                throw new \RuntimeException(\sprintf('Failed to replace original file "%s" with patched version.', $targetPath));
            }
            $tempOut = null; // Prevent double-cleanup in finally

            return [
                'additions' => $this->computeStats($patchContent)['additions'],
                'deletions' => $this->computeStats($patchContent)['deletions'],
                'noop' => false,
            ];
        } finally {
            // Clean up temp files
            if (is_file($patchFile)) {
                @unlink($patchFile);
            }
            if (null !== $tempOut && is_file($tempOut)) {
                @unlink($tempOut);
            }
        }
    }

    /**
     * Write patch content to a temp file.
     *
     * @return string Path to the temp patch file
     *
     * @throws \RuntimeException when temp file creation or write fails
     */
    private function writePatchFile(string $patchContent): string
    {
        $patchFile = tempnam(sys_get_temp_dir(), 'hatfield_patch_');

        if (false === $patchFile) {
            throw new \RuntimeException('Failed to create temporary file for patch content.');
        }

        $written = @file_put_contents($patchFile, $patchContent);
        if (false === $written) {
            // Clean up on write failure
            if (is_file($patchFile)) {
                @unlink($patchFile);
            }
            throw new \RuntimeException('Failed to write patch content to temp file.');
        }

        return $patchFile;
    }

    /**
     * Run patch dry-run validation.
     *
     * @throws ToolCallException when the patch does not apply
     * @throws \RuntimeException on cancellation or timeout
     */
    private function dryRun(string $targetPath, string $patchFile): void
    {
        $process = new Process([
            'patch', '-u', '-F3', '-l', '-N',
            '--dry-run', '--posix',
            '-o', '/dev/null',
            $targetPath, $patchFile,
        ]);

        $result = $this->toolRuntime->runCancellableProcess($process);

        if ($result->cancelled) {
            throw new \RuntimeException('Tool execution was cancelled during patch dry-run.');
        }

        if ($result->timedOut) {
            throw new \RuntimeException('Patch dry-run timed out.');
        }

        if (0 !== $result->exitCode) {
            $errorOutput = '' !== $result->stderr ? $result->stderr : $result->stdout;
            throw new ToolCallException(\sprintf('Patch dry-run failed for "%s": %s', $targetPath, $errorOutput), retryable: true, hint: 'Check that the patch context matches the file content and the diff is in unified format.');
        }
    }

    /**
     * Apply the patch to a temp output file.
     *
     * @throws ToolCallException when the patch application fails
     * @throws \RuntimeException on cancellation or timeout
     */
    private function applyPatches(string $targetPath, string $patchFile, string $tempOut): void
    {
        $process = new Process([
            'patch', '-u', '-F3', '-l', '-N',
            '-o', $tempOut,
            $targetPath, $patchFile,
        ]);

        $result = $this->toolRuntime->runCancellableProcess($process);

        if ($result->cancelled) {
            throw new \RuntimeException('Tool execution was cancelled during patch application.');
        }

        if ($result->timedOut) {
            throw new \RuntimeException('Patch application timed out.');
        }

        if (0 !== $result->exitCode) {
            $errorOutput = '' !== $result->stderr ? $result->stderr : $result->stdout;
            throw new ToolCallException(\sprintf('Patch application failed for "%s": %s', $targetPath, $errorOutput), retryable: true, hint: 'The dry-run passed but apply failed. This may indicate a race condition or file system issue.');
        }
    }

    /**
     * Count additions (+) and deletions (-) from the patch content,
     * excluding the --- and +++ header lines.
     *
     * @return array{additions: int, deletions: int}
     */
    private function computeStats(string $patchContent): array
    {
        $additions = 0;
        $deletions = 0;

        foreach (explode("\n", $patchContent) as $line) {
            $lineLen = \strlen($line);
            if (0 === $lineLen) {
                continue;
            }

            $firstChar = $line[0];

            if ('+' === $firstChar && !str_starts_with($line, '+++')) {
                ++$additions;
            } elseif ('-' === $firstChar && !str_starts_with($line, '---')) {
                ++$deletions;
            }
        }

        return ['additions' => $additions, 'deletions' => $deletions];
    }

    /**
     * Format the success message. If no changes were made, return a no-op message.
     */
    private function formatSuccess(string $targetPath, int $additions, int $deletions): string
    {
        if (0 === $additions && 0 === $deletions) {
            return 'No changes (patch produced identical content)';
        }

        return \sprintf(
            'Applied patch to %s (%d additions, %d deletions)',
            $targetPath,
            $additions,
            $deletions,
        );
    }
}
