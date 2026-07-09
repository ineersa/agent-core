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
            description: 'Apply Codex-style @@ hunks to an existing file. The target file must exist; use the write tool for new files.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path to edit (absolute, or relative to the working directory)',
                    ],
                    'patch' => [
                        'type' => 'string',
                        'description' => 'Hunk body only: @@ [seek hint], then space/-/+ lines. Optional stacked @@ hints and *** End of File.',
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
                'Each hunk starts with `@@` or `@@ <actual file line>` as a seek hint. Stacked `@@` lines narrow ambiguous locations.',
                'Use 3 lines above and 3 lines below unchanged context by default. Share context between adjacent edits in one patch.',
                'Body lines use diff prefixes: leading space for context, `-` to remove, `+` to add. Context lines must start with a space.',
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
            throw new ToolCallException('The "patch" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a patch with @@ hunks and space/-/+ body lines.');
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