<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use PHPUnit\Framework\TestCase;

/**
 * Static source-contract proof that the Pi TypeScript task-workflow extension
 * stays aligned with Hatfield ARCHIVE/CANCELLED semantics.
 *
 * Pi has no repository-native TS test harness; these assertions protect the
 * user-visible parity surface without introducing a disconnected npm stack.
 */
final class PiTaskWorkflowParityContractTest extends TestCase
{
    private string $extDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extDir = \dirname(__DIR__, 3).'/.pi/extensions/task-workflow';
        self::assertDirectoryExists($this->extDir);
    }

    public function testStatusesIncludeArchiveAndCancelled(): void
    {
        $types = $this->read('types.ts');

        self::assertStringContainsString('"ARCHIVE"', $types);
        self::assertStringContainsString('"CANCELLED"', $types);
        self::assertStringContainsString('DEFAULT_LISTED_STATUSES', $types);
        // Default listing must omit ARCHIVE while keeping CANCELLED.
        self::assertMatchesRegularExpression(
            '/DEFAULT_LISTED_STATUSES\s*=\s*\[[^\]]*"CANCELLED"[^\]]*\]/s',
            $types,
        );
        self::assertDoesNotMatchRegularExpression(
            '/DEFAULT_LISTED_STATUSES\s*=\s*\[[^\]]*"ARCHIVE"[^\]]*\]/s',
            $types,
        );
    }

    public function testListTasksSupportsIncludeArchiveSemantics(): void
    {
        $store = $this->read('task-store.ts');
        $index = $this->read('index.ts');

        self::assertStringContainsString('export function resolveListStatuses', $store);
        self::assertStringContainsString('includeArchive', $store);
        self::assertStringContainsString('include_archive', $index);
        self::assertStringContainsString('listTasks(taskRoot, status, includeArchive)', $index);
        self::assertStringContainsString('normalizeStatus', $store);
        self::assertStringContainsString('if (upper === "ARCHIVE") return "ARCHIVE"', $store);
        self::assertStringContainsString('if (upper === "CANCELLED" || upper === "CANCELED") return "CANCELLED"', $store);
    }

    public function testArchiveAndCancelledTransitionsMatchSafetyContract(): void
    {
        $index = $this->read('index.ts');
        $worktrees = $this->read('worktrees.ts');

        self::assertStringContainsString('ARCHIVE is only allowed from DONE', $index);
        self::assertStringContainsString('Archived task without git, worktree, PR, or branch side effects.', $index);
        self::assertStringContainsString('to === "CANCELLED"', $index);
        self::assertStringContainsString('removeTaskWorktreeSafely', $index);
        self::assertStringContainsString('Target task already exists', $index);

        self::assertStringContainsString('export async function removeTaskWorktreeSafely', $worktrees);
        self::assertStringContainsString('cleanupWorktreeAndIdeaExclusions', $worktrees);
        self::assertStringContainsString('Safe worktree cleanup failed; leaving task unmoved and IDEA exclusions intact.', $worktrees);
        self::assertStringContainsString('["worktree", "remove", worktree]', $worktrees);
        // Must not force-delete dirty worktrees.
        self::assertStringNotContainsString('worktree", "remove", "--force"', $worktrees);
        self::assertStringNotContainsString("worktree', 'remove', '--force'", $worktrees);

        // Cancellation path must not invoke merge/pull/branch deletion.
        $cancelledBlock = $this->extractBlock($index, 'to === "CANCELLED"', 'to === "DONE"');
        self::assertStringNotContainsString('mergeTaskBranch', $cancelledBlock);
        self::assertStringNotContainsString('pushTaskBranch', $cancelledBlock);
        self::assertStringNotContainsString('createPr', $cancelledBlock);
        self::assertStringNotContainsString('branch", "-d"', $cancelledBlock);
    }

    public function testDetailsRemainStructuredObjectsNotToonStrings(): void
    {
        $index = $this->read('index.ts');
        self::assertStringNotContainsString('Toon::encode', $index);
        self::assertStringNotContainsString('helgesverre/toon', $index);
        // Native structured details shapes remain objects.
        self::assertStringContainsString('details: { tasks, include_archive: includeArchive }', $index);
        self::assertStringContainsString('details: { from: task.status, to, path: target, notes }', $index);
    }

    private function read(string $file): string
    {
        $path = $this->extDir.'/'.$file;
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return $contents;
    }

    private function extractBlock(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        self::assertNotFalse($start, 'Missing start needle: '.$startNeedle);
        $end = strpos($source, $endNeedle, $start);
        self::assertNotFalse($end, 'Missing end needle: '.$endNeedle);

        return substr($source, $start, $end - $start);
    }
}
