<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Symfony\Component\Lock\LockFactory;

use function Amp\delay;

/**
 * Applies Codex-style single-file hunks under a Symfony Lock critical section.
 */
final class PatchApplier
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly PatchFailureFormatter $failureFormatter,
        private readonly EditPatchParser $parser = new EditPatchParser(),
        private readonly EditPatchApplicator $patchApplicator = new EditPatchApplicator(),
    ) {
    }

    /**
     * @return array{
     *     patchedContent: string,
     *     originalContent: string,
     *     patchContent: string,
     *     replacements: list<EditReplacementDTO>,
     *     additions: int,
     *     deletions: int,
     *     changedLineNumbers: list<int>
     * }
     */
    public function apply(string $targetPath, string $rawPatch, \Closure $checkpoint): array
    {
        $realPath = false !== ($r = realpath($targetPath)) ? $r : $targetPath;
        $lockKey = 'edit-file-'.hash('sha256', $realPath);
        $lock = $this->lockFactory->createLock($lockKey);
        $acquired = false;
        $stream = false;
        $fileLocked = false;

        try {
            do {
                $checkpoint();
                $acquired = $lock->acquire(false);
                if (!$acquired) {
                    delay(0.02);
                }
            } while (!$acquired);
            $checkpoint();

            // Keep the inode (including hardlinks/symlinks) and do not truncate
            // before both locks and the final cancellation checkpoint succeed.
            $stream = @fopen($targetPath, 'r+b');
            if (false === $stream) {
                throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to open "%s" for writing.', $targetPath), retryable: true);
            }
            do {
                $checkpoint();
                $fileLocked = flock($stream, \LOCK_EX | \LOCK_NB, $wouldBlock);
                if (!$fileLocked) {
                    if (0 === $wouldBlock) {
                        throw $this->infraError('Failed to lock target file.', $targetPath);
                    }
                    delay(0.02);
                }
            } while (!$fileLocked);
            $checkpoint();

            $originalContent = stream_get_contents($stream);
            if (false === $originalContent) {
                throw $this->infraError('Failed to read target file under lock.', $targetPath);
            }

            try {
                $chunks = $this->parser->parse($rawPatch);
                $replacements = $this->patchApplicator->computeReplacements($chunks, $originalContent);
            } catch (ToolCallException $e) {
                throw $this->wrapApplyFailure($e, $targetPath, $originalContent);
            }

            [$lines, $hadTrailingNewline] = $this->patchApplicator->splitFileLines($originalContent);
            $stats = $this->countPatchLineStatsFromChunks($chunks);
            $changedLineNumbers = $this->computeChangedLineNumbers($replacements);
            $patchedContent = $this->patchApplicator->applyReplacements($lines, $replacements, $hadTrailingNewline);

            if ($patchedContent === $originalContent) {
                return [
                    'patchedContent' => $originalContent,
                    'originalContent' => $originalContent,
                    'patchContent' => $rawPatch,
                    'replacements' => $replacements,
                    'additions' => 0,
                    'deletions' => 0,
                    'changedLineNumbers' => [],
                ];
            }

            $this->writeBytesInPlace($stream, $targetPath, $patchedContent, $originalContent);

            return [
                'patchedContent' => $patchedContent,
                'originalContent' => $originalContent,
                'patchContent' => $rawPatch,
                'replacements' => $replacements,
                'additions' => $stats['additions'],
                'deletions' => $stats['deletions'],
                'changedLineNumbers' => $changedLineNumbers,
            ];
        } finally {
            if (false !== $stream) {
                if ($fileLocked) {
                    flock($stream, \LOCK_UN);
                }
                fclose($stream);
            }
            try {
                // acquire() can throw after the store has granted ownership.
                // Symfony release() also safely handles an unacquired key.
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

    /**
     * @param list<EditPatchChunkDTO> $chunks
     *
     * @return array{additions: int, deletions: int}
     */
    private function countPatchLineStatsFromChunks(array $chunks): array
    {
        $additions = 0;
        $deletions = 0;

        foreach ($chunks as $chunk) {
            $oldCount = \count($chunk->oldLines);
            $newCount = \count($chunk->newLines);
            $shared = min($oldCount, $newCount);
            for ($i = 0; $i < $shared; ++$i) {
                if ($chunk->oldLines[$i] !== $chunk->newLines[$i]) {
                    ++$deletions;
                    ++$additions;
                }
            }
            if ($oldCount > $shared) {
                $deletions += $oldCount - $shared;
            }
            if ($newCount > $shared) {
                $additions += $newCount - $shared;
            }
        }

        return ['additions' => $additions, 'deletions' => $deletions];
    }

    /**
     * @param list<EditReplacementDTO> $replacements
     *
     * @return list<int>
     */
    private function computeChangedLineNumbers(array $replacements): array
    {
        $changed = [];
        usort($replacements, static fn (EditReplacementDTO $a, EditReplacementDTO $b): int => $a->startIndex <=> $b->startIndex);

        $delta = 0;
        foreach ($replacements as $replacement) {
            $patchedStart = $replacement->startIndex + $delta;
            if ([] === $replacement->newLines) {
                // Pure deletion: still surface nearby context at the patched deletion site.
                $changed[] = max(1, $patchedStart + 1);
            } else {
                for ($i = 0; $i < \count($replacement->newLines); ++$i) {
                    $changed[] = $patchedStart + $i + 1;
                }
            }

            $delta += \count($replacement->newLines) - $replacement->oldLength;
        }

        sort($changed);

        return array_values(array_unique($changed));
    }

    private function wrapApplyFailure(ToolCallException $e, string $targetPath, string $originalContent): ToolCallException
    {
        $message = $e->getMessage();
        $hint = $e->hint() ?? '';

        if (str_contains($message, 'E_PATCH_STALE')) {
            $failedLine = $this->guessFailedLineFromHint($hint) ?? 1;
            $context = $this->failureFormatter->buildCurrentFileContext($originalContent, [$failedLine]);
            if ('' !== $context) {
                $message .= "\n\nCurrent file context:\n".$context;
            }
        }

        return new ToolCallException(
            \sprintf("This edit attempt failed. No changes from this attempt were applied; the target file is untouched.\n\n%s", $message),
            retryable: $e->retryable(),
            hint: $hint,
        );
    }

    private function guessFailedLineFromHint(string $hint): ?int
    {
        if (preg_match('/around line (\d+)/', $hint, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** @param resource $stream */
    private function writeBytesInPlace($stream, string $targetPath, string $patchedContent, string $originalContent): void
    {
        $written = $this->replaceStreamContents($stream, $patchedContent);

        if (false !== $written && $written === \strlen($patchedContent)) {
            return;
        }

        $restored = $this->replaceStreamContents($stream, $originalContent);
        $rollbackOk = false !== $restored && $restored === \strlen($originalContent);
        $rollbackStatus = $rollbackOk
            ? 'Original content restored.'
            : 'Rollback may be incomplete (write failure or short write) — verify file integrity with read or git diff.';

        if (false === $written) {
            throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to write patched content to "%s": write returned false (permission/disk error). %s', $targetPath, $rollbackStatus), retryable: true, hint: 'Check file permissions and disk space, then retry.');
        }

        throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to write patched content to "%s": short write (%d of %d bytes). %s', $targetPath, $written, \strlen($patchedContent), $rollbackStatus), retryable: true, hint: 'Check file permissions and disk space, then retry.');
    }

    /** @param resource $stream */
    private function replaceStreamContents($stream, string $content): int|false
    {
        if (!rewind($stream)) {
            return false;
        }
        $written = '' === $content ? 0 : @fwrite($stream, $content);
        if (false === $written || $written !== \strlen($content)) {
            return $written;
        }

        return @ftruncate($stream, $written) && fflush($stream) ? $written : false;
    }

    private function infraError(string $context, string $targetPath): ToolCallException
    {
        return new ToolCallException(
            \sprintf('[E_PATCH_INFRA] %s for "%s".', $context, $targetPath),
            retryable: true,
            hint: 'Check filesystem availability, permissions, and disk space.',
        );
    }
}
