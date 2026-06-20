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
 * Edit an existing file by applying a unified diff patch.
 *
 * Thin orchestration facade that delegates to focused Edit sub-namespace
 * services for patch normalization, application, failure formatting, and
 * success rendering.
 *
 * Key behaviors:
 * - Accepts plain @@ hunk headers (recommended default) — the tool resolves
 *   old/new line numbers and counts from the hunk body and current file.
 * - Numbered headers (@@ -42,6 +42,8 @@) are accepted but not recommended.
 * - All-or-nothing: on failure, no changes are made to the file.
 * - On success, returns compact stats plus bounded post-apply changed chunks
 *   so the model can verify without extra reads.
 * - Whitespace-tolerant matching via patch -l flag.
 * - Symlink and hardlink identity preserved via in-place byte writes.
 * - Symfony Lock (flock) around the critical section.
 */
final class EditFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly LockFactory $lockFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly PatchNormalizer $normalizer = new PatchNormalizer(),
        private readonly PatchFailureFormatter $failureFormatter = new PatchFailureFormatter(),
        private ?PatchApplier $applier = null,
    ) {
    }

    public function __invoke(array $arguments): string
    {
        $this->validateArguments($arguments);

        return $this->toolRuntime->run(function () use ($arguments): string {
            $targetPath = $this->resolveAndVerifyTarget($arguments['path']);

            // Read current file content once for relaxed-hunk resolution.
            $targetContent = @file_get_contents($targetPath);
            if (false === $targetContent) {
                throw $this->infraError('Failed to read target file for patch normalization.', $targetPath);
            }

            // Normalize the LLM-generated patch
            $normalized = $this->normalizer->normalize($arguments['patch'], $targetContent);
            $patchContent = $normalized['content'];
            $detectedTruncation = $normalized['detectedTruncation'];

            // Apply the patch
            $result = $this->getApplier()->apply(
                $targetPath,
                $patchContent,
                $targetContent,
                $detectedTruncation,
            );

            // Detect no-op: patched content equals original
            if ($result['patchedContent'] === $result['originalContent']) {
                return 'No changes (patch produced identical content)';
            }

            // Compute addition/deletion stats from the normalized patch
            $stats = $this->computeStats($patchContent);

            // Build success message with stats + bounded changed chunks
            return $this->formatSuccess(
                $targetPath,
                $stats['additions'],
                $stats['deletions'],
                $result['originalContent'],
                $result['patchedContent'],
                $patchContent,
            );
        });
    }

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
                        'description' => 'Unified diff. Use plain @@ hunk headers by default — the tool resolves line numbers/counts automatically.',
                    ],
                ],
                'required' => ['path', 'patch'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'edit path patch — apply a unified diff patch to an existing file',
            promptGuidelines: [
                'Read the current file contents with the `read` tool before generating a unified diff patch — never guess line numbers or context.',
                'Use exact unchanged context lines from the current file. Do not modify or reformat context lines; they must match byte-for-byte.',
                'Use plain `@@` hunk headers without line numbers or counts as the default. The edit tool resolves and computes old/new line numbers, counts, and positions from the hunk body and the current file automatically. Do not calculate @@ header line numbers or counts yourself.',
                'Numbered unified-diff headers (e.g. `@@ -42,6 +42,8 @@`) are accepted but not recommended; prefer plain `@@`. Only use numbered headers when copying from actual `diff -u` output.',
                'Keep hunks tight: include enough unchanged context (typically 3–4 lines) for the tool to locate the hunk uniquely in the file. If a plain `@@` hunk matches multiple locations, add more context lines.',
                'The patch may contain multiple hunks to edit different parts of the file.',
                'The patch must have `--- a/path` and `+++ b/path` headers, then hunk(s). Do NOT wrap the patch in markdown code fences (```diff, ```patch, ```).',
                'Do NOT include non-diff trailer lines such as `--- End new file ---` or `--- End file ---`.',
                'Unified-diff body lines: the first character is the diff marker (` ` space, `-`, or `+`). The actual file content starts after that marker. Context lines start with a leading space — do not strip it. When file content itself starts with `-` or `+`, the patch line will show two adjacent marker-looking characters: unchanged ` -foo`, deletion `--foo`, addition `+-foo`. Similarly for `+`: unchanged ` +foo`, deletion `-+foo`, addition `++foo`.',
                'Do NOT copy the line-number prefix or whitespace/tab separator from `read` output into patch lines. Use only the raw file text after the line-number separator, with the correct diff prefix.',
                'Ensure the patch ends with a trailing newline. The tool adds one if missing, but including it avoids unexpected-EOF failures.',
                'The target file must already exist — use the write tool to create new files.',
                'Whitespace mismatches between the patch and the target file are handled automatically (tolerant matching).',
                'On success, the tool returns stats and bounded updated-file chunks around the changed lines. No extra read is needed to verify the result.',
                'If the patch produces no changes, the tool reports "No changes" without modifying the file.',
                'If an edit fails with a stale-hunk error, the error includes a current-file context window with exact line numbers from the original file. Re-read the file with `read` and retry with a plain `@@` patch using the exact current context.',
                'If an edit fails with a format error, check that the patch has ---/+++ headers, plain `@@` hunk headers, a trailing newline, and no markdown fences or non-diff trailers. Do not try to fix malformed patches — regenerate with plain `@@` and exact context.',
                'If the target file lacks a trailing newline, the error hint will mention it. Add a trailing newline with the write tool or include "\\ No newline at end of file" markers in the patch.',
                'If the edit succeeds but the addition/deletion stats contradict your intent (e.g. you intended only deletions but the result says additions > 0), immediately re-read the file with `read` and repair — do not assume the edit was correct.',
            ],
        );
    }

    private function validateArguments(array $arguments): void
    {
        $path = $arguments['path'] ?? null;
        $patch = $arguments['patch'] ?? null;

        if (!\is_string($path) || '' === $path) {
            throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a valid file path.');
        }

        if (!\is_string($patch) || '' === $patch) {
            throw new ToolCallException('The "patch" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a unified diff with plain @@ hunk headers.');
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
     * Lazy-initialize the PatchApplier to avoid circular dependency issues
     * (PatchApplier needs PatchFailureFormatter which is co-located in the facade).
     */
    private function getApplier(): PatchApplier
    {
        return $this->applier ??= new PatchApplier(
            $this->toolRuntime,
            $this->lockFactory,
            $this->logger,
            $this->failureFormatter,
        );
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

    private function infraError(string $context, string $targetPath): ToolCallException
    {
        return new ToolCallException(
            \sprintf('[E_PATCH_INFRA] %s for "%s".', $context, $targetPath),
            retryable: true,
            hint: 'Check filesystem availability, permissions, and disk space.',
        );
    }
}
