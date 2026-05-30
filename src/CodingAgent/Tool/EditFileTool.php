<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

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
     * @throws \RuntimeException         on validation failures, patch failures, or cancellation
     * @throws \InvalidArgumentException on missing or invalid arguments
     */
    public function __invoke(array $arguments): string
    {
        // Validate required arguments
        $path = $arguments['path'] ?? null;
        $patch = $arguments['patch'] ?? null;

        if (!\is_string($path) || '' === $path) {
            throw new \InvalidArgumentException('The "path" argument is required and must be a non-empty string.');
        }

        if (!\is_string($patch) || '' === $patch) {
            throw new \InvalidArgumentException('The "patch" argument is required and must be a non-empty string.');
        }

        // Wrap in cancellation checkpoints
        return $this->toolRuntime->run(function () use ($path, $patch): string {
            // Resolve to absolute normalized path
            $targetPath = PathResolver::resolve($path);

            // Target must exist — creation belongs to the write tool
            if (!is_file($targetPath) || !is_readable($targetPath)) {
                throw new \RuntimeException(\sprintf('File "%s" does not exist or is not readable. Use the write tool to create new files.', $targetPath));
            }

            // Create temp file paths
            $patchFile = tempnam(sys_get_temp_dir(), 'hatfield_patch_');
            $tempOut = tempnam(sys_get_temp_dir(), 'hatfield_out_');

            try {
                // Write patch content to temp file (never interpolate into shell args)
                $written = @file_put_contents($patchFile, $patch);
                if (false === $written) {
                    throw new \RuntimeException('Failed to write patch content to temp file.');
                }

                // ── Pass 1: Dry-run validation ──
                $dryRunProcess = new Process([
                    'patch', '-u', '-F3', '-l', '-N',
                    '--dry-run', '--posix',
                    '-o', '/dev/null',
                    $targetPath, $patchFile,
                ]);

                $dryRunResult = $this->toolRuntime->runCancellableProcess($dryRunProcess);

                if ($dryRunResult->cancelled) {
                    throw new \RuntimeException('Tool execution was cancelled during patch dry-run.');
                }

                if ($dryRunResult->timedOut) {
                    throw new \RuntimeException('Patch dry-run timed out.');
                }

                if (0 !== $dryRunResult->exitCode) {
                    $errorOutput = '' !== $dryRunResult->stderr ? $dryRunResult->stderr : $dryRunResult->stdout;
                    throw new \RuntimeException(\sprintf('Patch dry-run failed for "%s": %s', $targetPath, $errorOutput));
                }

                // ── Pass 2: Apply to temp output ──
                $applyProcess = new Process([
                    'patch', '-u', '-F3', '-l', '-N',
                    '-o', $tempOut,
                    $targetPath, $patchFile,
                ]);

                $applyResult = $this->toolRuntime->runCancellableProcess($applyProcess);

                if ($applyResult->cancelled) {
                    throw new \RuntimeException('Tool execution was cancelled during patch application.');
                }

                if ($applyResult->timedOut) {
                    throw new \RuntimeException('Patch application timed out.');
                }

                if (0 !== $applyResult->exitCode) {
                    $errorOutput = '' !== $applyResult->stderr ? $applyResult->stderr : $applyResult->stdout;
                    throw new \RuntimeException(\sprintf('Patch application failed for "%s": %s', $targetPath, $errorOutput));
                }

                // Read patched output
                $patchedContent = @file_get_contents($tempOut);
                if (false === $patchedContent) {
                    throw new \RuntimeException('Failed to read patched output file.');
                }

                // Check for no-op (patch produced identical content)
                $originalContent = @file_get_contents($targetPath);
                if (false !== $originalContent && $patchedContent === $originalContent) {
                    return 'No changes (patch produced identical content)';
                }

                // ── Atomic replace ──
                if (!@rename($tempOut, $targetPath)) {
                    throw new \RuntimeException(\sprintf('Failed to replace original file "%s" with patched version.', $targetPath));
                }

                // Mark as renamed so the finally block doesn't double-clean
                $tempOut = null;

                // ── Compute stats ──
                $additions = 0;
                $deletions = 0;

                foreach (explode("\n", $patch) as $line) {
                    $lineLen = \strlen($line);
                    if (0 === $lineLen) {
                        continue;
                    }

                    $firstChar = $line[0];

                    // Count + lines (but not +++ file headers)
                    if ('+' === $firstChar && !str_starts_with($line, '+++')) {
                        ++$additions;
                    }

                    // Count - lines (but not --- file headers)
                    if ('-' === $firstChar && !str_starts_with($line, '---')) {
                        ++$deletions;
                    }
                }

                return \sprintf(
                    'Applied patch to %s (%d additions, %d deletions)',
                    $targetPath,
                    $additions,
                    $deletions,
                );
            } finally {
                // Clean up temp files
                // phpstan does not track that tempnam returning false is
                // already handled above, so we use is_string as a guard.
                if (is_string($patchFile) && is_file($patchFile)) {
                    @unlink($patchFile);
                }

                if (null !== $tempOut && is_file($tempOut)) {
                    @unlink($tempOut);
                }
            }
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
}
