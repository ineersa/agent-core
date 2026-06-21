<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Edit\PatchApplier;
use Ineersa\CodingAgent\Tool\Edit\PatchFailureFormatter;
use Ineersa\CodingAgent\Tool\Edit\PatchNormalizer;
use Symfony\Component\Lock\LockFactory;

/**
 * Edit an existing file by applying a plain-@@ patch.
 *
 * Thin orchestration facade that delegates to focused Edit sub-namespace
 * services for patch normalization, application, failure formatting, and
 * success rendering.
 *
 * Key behaviors:
 * - Accepts plain @@ hunk headers (recommended default) — the tool resolves
 *   old/new line numbers and counts from the hunk body and current file.
 * - Numbered headers are accepted but not recommended.
 * - All-or-nothing: on failure, no changes are made to the file.
 * - On success, returns compact stats plus bounded post-apply changed chunks
 *   so the model can verify without extra reads.
 * - Whitespace-tolerant matching via patch -l flag.
 * - Symlink and hardlink identity preserved via in-place byte writes.
 * - Symfony Lock (flock) around the critical section.
 */
final class EditFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    private readonly PatchApplier $applier;

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly LockFactory $lockFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly PatchNormalizer $normalizer = new PatchNormalizer(),
        private readonly PatchFailureFormatter $failureFormatter = new PatchFailureFormatter(),
        ?PatchApplier $applier = null,
    ) {
        $this->applier = $applier ?? new PatchApplier(
            $this->toolRuntime,
            $this->lockFactory,
            $this->logger,
            $this->failureFormatter,
        );
    }

    public function __invoke(array $arguments): string
    {
        $this->validateArguments($arguments);

        return $this->toolRuntime->run(function () use ($arguments): string {
            $targetPath = $this->resolveAndVerifyTarget($arguments['path']);

            // Read current file content, normalize the patch, and apply
            // under one locked critical section so the snapshot used for
            // normalization, dry-run, apply, no-op, and rollback is the
            // same locked snapshot that GNU patch validates/applies against.
            $result = $this->applier->applyWithNormalizer(
                $targetPath,
                $arguments['patch'],
                $this->normalizer,
            );

            // Detect no-op: patched content equals original
            if ($result['patchedContent'] === $result['originalContent']) {
                return 'No changes (patch produced identical content)';
            }

            // Compute addition/deletion stats from the normalized patch
            $stats = $this->computeStats($result['normalizedPatch']);

            // Build success message with stats + bounded changed chunks
            return $this->formatSuccess(
                $targetPath,
                $stats['additions'],
                $stats['deletions'],
                $result['originalContent'],
                $result['patchedContent'],
                $result['normalizedPatch'],
            );
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'edit',
            description: 'Apply an all-or-nothing plain-@@ patch to an existing file. The tool resolves hunk positions/counts, applies no changes on failure, and returns stats plus bounded updated context on success. Use the write tool for new files.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path to edit (absolute, or relative to the working directory)',
                    ],
                    'patch' => [
                        'type' => 'string',
                        'description' => 'Plain-@@ patch content with ---/+++ file headers and @@ hunks. Use plain @@ hunks you write yourself; numbered hunks are only for literal external diff output.',
                    ],
                ],
                'required' => ['path', 'patch'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'edit path patch — apply a plain-@@ patch to an existing file',
            promptGuidelines: [
                'Quick path: use the latest exact context you already have; write a plain `@@` patch; copy unchanged context lines exactly; change existing lines with `-current line` + `+desired line`; after success, use the returned `→` updated context for follow-up edits instead of re-reading.',
                'Read only when needed: use prior read output and success `→` context first. If context is missing, stale, or outside the shown region, use targeted `read` with both `offset` and `limit`; do not re-read the full file just to verify.',
                'Use plain `@@` hunk headers without line numbers or counts as the default. The tool resolves and computes line numbers/counts automatically, so do not read just to find line numbers. Never calculate or write numbered headers yourself.',
                'Patch shape:
--- a/path
+++ b/path
@@
 unchanged context
-current line
+desired line
 unchanged context',
                'Context lines start with a leading space and must match the current file exactly — they are verification only and are never modified. Do not copy line-number prefixes from `read` output.',
                'If file content itself starts with `-` or `+`, keep the diff marker separate: unchanged ` -foo`, deletion `--foo`, addition `+-foo`; unchanged ` +foo`, deletion `-+foo`, addition `++foo`.',
                'Keep hunks tight but unique, usually 3–4 unchanged context lines. Multiple hunks are fine for different file regions.',
                'No markdown fences, prose, or trailer lines such as `--- End file ---`. Ensure the patch ends with a trailing newline.',
                'One edit call per file at a time. An edit error means that attempt applied no changes; follow the tool hint and retry with plain `@@` from exact current context.',
                'If success stats or returned context contradict your intent, inspect the returned `→` context first; use targeted `read` only if more surrounding context is needed.',
                'The target file must already exist; use `write` to create new files.',
            ],
        );
    }

    /**
     * @param array{path?: scalar|null, patch?: scalar|null} $arguments
     */
    private function validateArguments(array $arguments): void
    {
        $path = $arguments['path'] ?? null;
        $patch = $arguments['patch'] ?? null;

        if (!\is_string($path) || '' === $path) {
            throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a valid file path.');
        }

        if (!\is_string($patch) || '' === $patch) {
            throw new ToolCallException('The "patch" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a plain-@@ patch with ---/+++ file headers.');
        }
    }

    private function resolveAndVerifyTarget(string $path): string
    {
        $targetPath = PathResolver::resolve($path);

        if (!is_file($targetPath) || !is_readable($targetPath)) {
            throw new ToolCallException(\sprintf('File "%s" does not exist or is not readable.', $targetPath), retryable: false, hint: 'Use the write tool to create new files.');
        }

        return $targetPath;
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
     * Format the success message with stats and bounded post-apply changed chunks.
     */
    private function formatSuccess(
        string $targetPath,
        int $additions,
        int $deletions,
        string $originalContent,
        string $patchedContent,
        string $normalizedPatch,
    ): string {
        $addWord = 1 === $additions ? 'addition' : 'additions';
        $delWord = 1 === $deletions ? 'deletion' : 'deletions';

        $statsLine = \sprintf(
            'Applied patch to %s (%d %s, %d %s)',
            $targetPath,
            $additions, $addWord,
            $deletions, $delWord,
        );

        // Build bounded post-apply changed chunks
        $changedContext = $this->failureFormatter->buildChangedContexts(
            $originalContent,
            $patchedContent,
            $normalizedPatch,
        );

        if ('' !== $changedContext) {
            return $statsLine."\n\nUpdated file context:\n".$changedContext;
        }

        return $statsLine;
    }
}
