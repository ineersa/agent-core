<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Edit\PatchApplier;
use Ineersa\CodingAgent\Tool\Edit\PatchFailureFormatter;
use Symfony\Component\Lock\LockFactory;

/**
 * Edit an existing file by applying Codex-style @@ hunks.
 */
final class EditFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    private readonly PatchApplier $applier;

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly LockFactory $lockFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly PatchFailureFormatter $failureFormatter = new PatchFailureFormatter(),
        ?PatchApplier $applier = null,
    ) {
        $this->applier = $applier ?? new PatchApplier(
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
            $result = $this->applier->apply($targetPath, $arguments['patch']);

            if ($result['patchedContent'] === $result['originalContent']) {
                return 'No changes (patch produced identical content)';
            }

            return $this->formatSuccess(
                $targetPath,
                $result['additions'],
                $result['deletions'],
                $result['patchedContent'],
                $result['changedLineNumbers'],
            );
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'edit',
            description: 'Apply @@ hunks to an existing file. Every hunk body line must start with a diff prefix: a leading space for unchanged context, `-` for removal, or `+` for addition. The target file must exist; use the write tool for new files.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path to edit (absolute, or relative to the working directory)',
                    ],
                    'patch' => [
                        'type' => 'string',
                        'description' => 'Hunk body only: starts with @@ [optional seek hint], then body lines each prefixed with one leading space (unchanged context), `-` (removal), or `+` (addition). Unchanged source or documentation lines are context and still need the leading space. Empty physical lines inside a hunk are accepted as unchanged blank context lines (a line with only a leading space is equivalent). Seek hints are literal source-text anchors, not line numbers; use nearby unique source text or leave the hint blank and include exact context lines. Optional stacked @@ hints and *** End of File.',
                    ],
                ],
                'required' => ['path', 'patch'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'edit path patch — apply @@ hunks to an existing file',
            promptGuidelines: [
                'Use the latest exact file context you already have. For a first edit on a file, or when your context is missing/stale, use a targeted `read` with both `offset` and `limit` for the relevant region.',
                'Patches are hunk bodies only — no ---/+++ headers, no numbered @@ -N,M +N,M @@ headers, and no *** Begin Patch envelope.',
                'Each hunk starts with `@@` or `@@ <literal source-text anchor>` as a seek hint (not a line number). Stacked `@@` lines narrow ambiguous locations.',
                'Seek hints are literal source-text anchors, not line numbers. Do not use `@@ line N`; use nearby unique source text or omit the hint and rely on exact context lines.',
                'Use 3 lines above and 3 lines below unchanged context by default. Share context between adjacent edits in one patch.',
                'Every hunk body line after `@@` must start with one diff prefix: leading space for unchanged context, `-` to remove, `+` to add. Unchanged source or documentation lines are still context and need the leading space. Empty physical lines inside a hunk are unchanged blank context; a single leading-space line is equivalent.',
                'Compact example: `@@\n unchanged context\n-old line\n+new line` — the first character of each body line must be space, `-`, or `+`.',
                'Use `*** End of File` inside a hunk when anchoring an append-to-end edit.',
                'The target file must already exist — use the write tool to create new files.',
                'Make ONE edit call at a time per file and wait for the result before another edit on the same file.',
                'On success, the tool returns stats and bounded updated-file context around changed lines.',
                'If an edit fails as stale or ambiguous, use the error context or a targeted `read` with `offset`/`limit`, then regenerate the patch.',
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
            throw new ToolCallException('The "patch" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a patch with @@ hunks; each body line must begin with a diff prefix (space, `-`, or `+`).');
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
     * @param int[] $changedLineNumbers
     */
    private function formatSuccess(
        string $targetPath,
        int $additions,
        int $deletions,
        string $patchedContent,
        array $changedLineNumbers,
    ): string {
        $addWord = 1 === $additions ? 'addition' : 'additions';
        $delWord = 1 === $deletions ? 'deletion' : 'deletions';

        $statsLine = \sprintf(
            'Applied patch to %s (%d %s, %d %s)',
            $targetPath,
            $additions, $addWord,
            $deletions, $delWord,
        );

        $changedContext = $this->failureFormatter->buildChangedContextsFromLineNumbers(
            $patchedContent,
            $changedLineNumbers,
        );

        if ('' !== $changedContext) {
            return $statsLine."\n\nUpdated file context:\n".$changedContext;
        }

        return $statsLine;
    }
}
