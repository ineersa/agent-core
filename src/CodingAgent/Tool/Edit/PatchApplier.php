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
     * Apply a normalized patch to a file.
     *
     * @param string $targetPath         absolute path to the target file
     * @param string $patchContent       normalized unified-diff patch
     * @param string $originalContent    snapshot of original file content before locking
     * @param bool   $detectedTruncation whether the normalizer detected a truncated hunk
     *
     * @return array{additions: int, deletions: int, patchedContent: string, originalContent: string}
     *
     * @throws ToolCallException on validation/patch/infrastructure failures
     */
    public function apply(
        string $targetPath,
        string $patchContent,
        string $originalContent,
        bool $detectedTruncation,
    ): array {
        $patchFile = $this->writePatchFile($patchContent);
        $tempOut = @tempnam(sys_get_temp_dir(), 'hatfield_out_');
        if (false === $tempOut) {
            @unlink($patchFile);
            throw $this->infraError('Failed to create temp output file.', $targetPath);
        }

        $realPath = false !== ($r = realpath($targetPath)) ? $r : $targetPath;
        $lockKey = 'edit-file-'.hash('sha256', $realPath);
        $lock = $this->lockFactory->createLock($lockKey);

        try {
            $lock->acquire(true);

            // Phase 1: dry-run
            $drResult = $this->runPatchDryRun($realPath, $patchFile);

            if (0 !== $drResult->exitCode) {
                $noTrailingNewline = $this->targetLacksTrailingNewline($targetPath);
                $failure = $this->failureFormatter->buildFailureMessage(
                    $targetPath,
                    $drResult->stdout, $drResult->stderr,
                    $originalContent, $noTrailingNewline, $detectedTruncation,
                );

                throw new ToolCallException(\sprintf("No changes were applied: the patch did not pass validation and the target file is untouched.\n\n%s", $failure['message']), retryable: $failure['retryable'], hint: $failure['hint']);
            }

            // Phase 2: apply to temp output (target untouched)
            $applyResult = $this->runPatchApply($realPath, $patchFile, $tempOut);

            if (0 !== $applyResult->exitCode) {
                $noTrailingNewline = $this->targetLacksTrailingNewline($targetPath);
                $failure = $this->failureFormatter->buildFailureMessage(
                    $targetPath,
                    $applyResult->stdout, $applyResult->stderr,
                    $originalContent, $noTrailingNewline, $detectedTruncation,
                );

                throw new ToolCallException($failure['message'], retryable: $failure['retryable'], hint: $failure['hint']);
            }

            // Phase 3: read patched bytes and no-op check
            $patchedContent = @file_get_contents($tempOut);
            if (false === $patchedContent) {
                throw $this->infraError('Failed to read patched output.', $targetPath);
            }

            if ($patchedContent === $originalContent) {
                return [
                    'additions' => 0,
                    'deletions' => 0,
                    'patchedContent' => $originalContent,
                    'originalContent' => $originalContent,
                ];
            }

            // Phase 4: in-place write through target path
            $this->writeBytesInPlace($targetPath, $patchedContent, $originalContent);

            return [
                'additions' => 0, // Caller computes stats separately
                'deletions' => 0,
                'patchedContent' => $patchedContent,
                'originalContent' => $originalContent,
            ];
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

            if (is_file($patchFile)) {
                @unlink($patchFile);
            }
            if (is_file($tempOut)) {
                @unlink($tempOut);
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
            'patch', '-u', '-F3', '-l', '-N',
            '--dry-run', '--posix',
            $targetPath, $patchFile,
        ]);

        return $this->toolRuntime->runCancellableProcess($process);
    }

    private function runPatchApply(string $targetPath, string $patchFile, string $tempOut): CancellableProcessResult
    {
        $process = new Process([
            'patch', '-u', '-F3', '-l', '-N',
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
