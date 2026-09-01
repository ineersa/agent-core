<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\EditFileArgumentsDTO;
use Ineersa\CodingAgent\Tool\Edit\PatchApplier;
use Ineersa\CodingAgent\Tool\Edit\PatchFailureFormatter;
use Symfony\Component\Lock\LockFactory;

/**
 * Edit an existing file by applying @@ hunks.
 */
final class EditFileTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'edit';

    public const string DESCRIPTION = 'Apply @@ hunks to an existing file. Every hunk body line must start with a diff prefix: a leading space for unchanged context, `-` for removal, or `+` for addition. The target file must exist; use the write tool for new files.';

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

    public function __invoke(EditFileArgumentsDTO $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $path = $arguments->path;
            $patch = $arguments->patch;

            // Target existence/readability is validated by the EditFileTarget
            // DTO constraint before execution. Patch applicability (stale,
            // ambiguous, or malformed hunks) and write failures are
            // execution-time, state-dependent results under the applier's
            // lock and stay in PatchApplier.
            $targetPath = PathResolver::resolve($path);
            $result = $this->applier->apply($targetPath, $patch);

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
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'edit path patch — apply @@ hunks to an existing file',
            promptGuidelines: [
                'Use the latest exact file context you already have. For a first edit on a file, or when your context is missing/stale, use a targeted `read` with both `offset` and `limit` for the relevant region.',
                'Patches are hunk bodies only: no ---/+++ headers, no numbered @@ -N,M +N,M @@ headers, and no *** Begin Patch or *** Update File markers.',
                'Each plain or seek-hinted `@@` begins a new sequential, non-overlapping hunk applied after earlier hunks. Stacked `@@` headers (multiple headers before body lines) narrow one hunk; a later `@@` after body lines starts a separate hunk. Overlapping changes must be combined into one hunk.',
                'Seek hints are literal source-text anchors, not line numbers. Do not use `@@ line N`; use nearby unique source text or omit the hint and rely on exact context lines.',
                'Use 3 lines above and 3 lines below unchanged context by default. Share context between adjacent edits in one patch.',
                'Every hunk body line after `@@` must start with one diff prefix: leading space for unchanged context, `-` to remove, `+` to add. Unchanged source or documentation lines are still context and need the leading space. Empty physical lines inside a hunk are unchanged blank context; a single leading-space line is equivalent.',
                'JSON example: `{"path":"example.txt","patch":"@@\\n unchanged context\\n-old line\\n+new line"}`. The patch string ends after the final hunk; do not append `*** End Patch`.',
                'Optional `*** End of File` prefers matching the old block at the physical end of the file; if no EOF match exists, falls back to a unique forward match from the current hunk cursor (ambiguous non-EOF matches still fail).',
                'Make ONE edit call at a time per file and wait for the result before another edit on the same file.',
                'On success, the tool returns stats and bounded updated-file context around changed lines.',
                'If an edit fails as stale or ambiguous, use the error context or a targeted `read` with `offset`/`limit`, then regenerate the patch.',
            ],
        );
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
