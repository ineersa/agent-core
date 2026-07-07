<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tool\CancellableProcessResult;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

/**
 * Applies a normalized unified-diff patch to a file using GNU patch.
 *
 * Phases:
 * 1. Dry-run validation — never touches the target.
 * 2. Apply to temp output (patch -o <temp>) — generates patched bytes
 *    without modifying the target.
 * 3. In-place byte write through the original target path — preserves
 *    symlink target semantics and hardlink inode identity.
 *
 * GNU patch is invoked with fuzz factor 5 ({@see runPatchDryRun}, {@see runPatchApply}).
 * LLM plain-@@ hunks often carry unbalanced context (many leading context lines,
 * few trailing, including blank context lines). After PatchNormalizer resolves
 * relaxed headers, GNU patch 2.7.6 may still fail to anchor such hunks at fuzz 3;
 * fuzz 5 provides headroom while plain-@@ safety (stale, duplicate, ambiguous
 * blocks) remains enforced upstream in PatchNormalizer::findExactBlockMatch().
 *
 * On dry-run or apply failure, throws a classified ToolCallException with
 * sanitized GNU patch output and bounded file-context windows.
 */
final class PatchApplier
{
    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly LockFactory $lockFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly PatchFailureFormatter $failureFormatter,
    ) {
    }

    /**
     * Apply a normalized patch inside a locked critical section.
     *
     * Reads the target file content under lock, normalizes the raw patch
     * against that locked snapshot, and applies it atomically.  The same
     * snapshot is used for normalization, GNU-patch dry-run, in-place
     * application, no-op detection, and rollback — no TOCTOU window.
     *
     * @param string          $targetPath absolute path to the target file
     * @param string          $rawPatch   raw LLM-generated patch (before normalization)
     * @param PatchNormalizer $normalizer the normalizer service
     *
     * @return array{patchedContent: string, originalContent: string, normalizedPatch: string}
     *
     * @throws ToolCallException on validation/patch/infrastructure failures
     */
    public function applyWithNormalizer(
        string $targetPath,
        string $rawPatch,
        PatchNormalizer $normalizer,
    ): array {
        $realPath = false !== ($r = realpath($targetPath)) ? $r : $targetPath;
        $lockKey = 'edit-file-'.hash('sha256', $realPath);
        $lock = $this->lockFactory->createLock($lockKey);

        try {
            $lock->acquire(true);

            // Read target content under lock — the same snapshot used
            // throughout: normalization, dry-run, apply, no-op, rollback.
            $originalContent = @file_get_contents($targetPath);
            if (false === $originalContent) {
                throw $this->infraError('Failed to read target file under lock.', $targetPath);
            }

            // Normalize the LLM-generated patch against locked file content
            $normalized = $normalizer->normalize($rawPatch, $originalContent);
            $patchContent = $normalized['content'];
            $detectedTruncation = $normalized['detectedTruncation'];
            $truncationDetails = $normalized['truncationDetails'] ?? '';

            $patchFile = $this->writePatchFile($patchContent);
            $tempOut = @tempnam(sys_get_temp_dir(), 'hatfield_out_');
            if (false === $tempOut) {
                @unlink($patchFile);
                throw $this->infraError('Failed to create temp output file.', $targetPath);
            }

            try {
                // Phase 1: dry-run
                $drResult = $this->runPatchDryRun($realPath, $patchFile);

                if (0 !== $drResult->exitCode) {
                    $noTrailingNewline = $this->targetLacksTrailingNewline($targetPath);
                    $failure = $this->failureFormatter->buildFailureMessage(
                        $targetPath,
                        $drResult->stdout, $drResult->stderr,
                        $originalContent, $noTrailingNewline, $detectedTruncation,
                        $truncationDetails,
                    );

                    throw new ToolCallException(\sprintf("This edit attempt failed. No changes from this attempt were applied; the target file is untouched.\n\n%s", $failure['message']), retryable: $failure['retryable'], hint: $failure['hint']);
                }

                // Phase 2: apply to temp output
                $applyResult = $this->runPatchApply($realPath, $patchFile, $tempOut);

                if (0 !== $applyResult->exitCode) {
                    $noTrailingNewline = $this->targetLacksTrailingNewline($targetPath);
                    $failure = $this->failureFormatter->buildFailureMessage(
                        $targetPath,
                        $applyResult->stdout, $applyResult->stderr,
                        $originalContent, $noTrailingNewline, $detectedTruncation,
                        $truncationDetails,
                    );

                    throw new ToolCallException(\sprintf("This edit attempt failed. No changes from this attempt were applied; the target file is untouched.\n\n%s", $failure['message']), retryable: $failure['retryable'], hint: $failure['hint']);
                }

                // Phase 3: read patched bytes
                $patchedContent = @file_get_contents($tempOut);
                if (false === $patchedContent) {
                    throw $this->infraError('Failed to read patched output.', $targetPath);
                }

                // No-op: patched content equals original
                if ($patchedContent === $originalContent) {
                    return [
                        'patchedContent' => $originalContent,
                        'originalContent' => $originalContent,
                        'normalizedPatch' => $patchContent,
                    ];
                }

                // Phase 4: in-place write through target path
                $this->writeBytesInPlace($targetPath, $patchedContent, $originalContent);

                return [
                    'patchedContent' => $patchedContent,
                    'originalContent' => $originalContent,
                    'normalizedPatch' => $patchContent,
                ];
            } finally {
                if (is_file($patchFile)) {
                    @unlink($patchFile);
                }
                if (is_file($tempOut)) {
                    @unlink($tempOut);
                }
            }
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $this->logger->warning('Lock release failed during edit tool cleanup', [
                    'component' => 'edit_tool',
                    'event_type' => 'edit_tool.lock_release_failed',
                    'exception' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function writeBytesInPlace(string $targetPath, string $patchedContent, string $originalContent): void
    {
        $written = @file_put_contents($targetPath, $patchedContent, \LOCK_EX);

        if (false !== $written && $written === \strlen($patchedContent)) {
            return;
        }

        if (false === $written) {
            throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to write patched content to "%s": write returned false (permission/disk error).', $targetPath), retryable: true, hint: 'Check file permissions and disk space, then retry.');
        }

        $restored = @file_put_contents($targetPath, $originalContent, \LOCK_EX);
        $rollbackOk = false !== $restored && $restored === \strlen($originalContent);
        $rollbackStatus = $rollbackOk
            ? 'Original content restored.'
            : 'Rollback may be incomplete (write failure or short write) — verify file integrity with read or git diff.';

        throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to write patched content to "%s": short write (%d of %d bytes). %s', $targetPath, $written, \strlen($patchedContent), $rollbackStatus), retryable: true, hint: 'Check file permissions and disk space, then retry.');
    }

    private function infraError(string $context, string $targetPath): ToolCallException
    {
        return new ToolCallException(
            \sprintf('[E_PATCH_INFRA] %s for "%s".', $context, $targetPath),
            retryable: true,
            hint: 'Check filesystem availability, permissions, and disk space.',
        );
    }

    private function runPatchDryRun(string $targetPath, string $patchFile): CancellableProcessResult
    {
        $process = new Process([
            'patch', '-u', '-F5', '-l', '-N',
            '--dry-run', '--posix',
            $targetPath, $patchFile,
        ]);

        return $this->toolRuntime->runCancellableProcess($process);
    }

    private function runPatchApply(string $targetPath, string $patchFile, string $tempOut): CancellableProcessResult
    {
        $process = new Process([
            'patch', '-u', '-F5', '-l', '-N',
            '-o', $tempOut,
            $targetPath, $patchFile,
        ]);

        return $this->toolRuntime->runCancellableProcess($process);
    }

    private function writePatchFile(string $patchContent): string
    {
        $patchFile = @tempnam(sys_get_temp_dir(), 'hatfield_patch_');

        if (false === $patchFile) {
            throw new ToolCallException('[E_PATCH_INFRA] Failed to create temp file for patch content.', retryable: true, hint: 'Check disk space and temp directory permissions.');
        }

        $written = @file_put_contents($patchFile, $patchContent);
        if (false === $written) {
            if (is_file($patchFile)) {
                @unlink($patchFile);
            }
            throw new ToolCallException('[E_PATCH_INFRA] Failed to write patch content to temp file.', retryable: true, hint: 'Check disk space and temp directory permissions.');
        }

        return $patchFile;
    }

    private function targetLacksTrailingNewline(string $targetPath): bool
    {
        if (!is_file($targetPath) || !is_readable($targetPath)) {
            return false;
        }

        $handle = @fopen($targetPath, 'rb');
        if (false === $handle) {
            return false;
        }

        if (-1 === fseek($handle, -1, \SEEK_END)) {
            fclose($handle);

            return false;
        }

        $lastByte = fread($handle, 1);
        fclose($handle);

        return "\n" !== $lastByte;
    }
}
