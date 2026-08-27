<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\MoveTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoveTaskHandlerTest extends TestCase
{
    private string $repoRoot;
    private string $boardRoot;
    private string $worktreesBase;

    protected function setUp(): void
    {
        $this->repoRoot = TestDirectoryIsolation::createProjectTempDir('tw-git');
        $this->initGitRepo($this->repoRoot);
        $this->runGit($this->repoRoot, ['remote', 'add', 'origin', 'https://example.com/repo.git']);
        // The real project has .hatfield/extensions/ tracked in git.
        // Simulate it so installExtensionsVendor() finds composer.json in the worktree.
        $extDir = $this->repoRoot.'/.hatfield/extensions';
        mkdir($extDir, 0o755, true);
        file_put_contents($extDir.'/composer.json', '{"name": "test/extensions"}');
        $this->runGit($this->repoRoot, ['add', '.hatfield/']);
        $this->runGit($this->repoRoot, ['commit', '-m', 'add extensions']);
        $this->boardRoot = TestDirectoryIsolation::createProjectTempDir('tw-board');
        foreach (TaskStatusEnum::all() as $s) {
            mkdir($this->boardRoot.'/'.$s->value, 0o755, true);
        }
        $this->worktreesBase = \dirname($this->repoRoot).'/'.basename($this->repoRoot).'-worktrees';
        putenv('HATFIELD_TASK_WORKFLOW_ROOT='.$this->boardRoot);
    }

    protected function tearDown(): void
    {
        putenv('HATFIELD_TASK_WORKFLOW_ROOT');
        // worktreesBase sits beside the temp repo and is not under board/repo roots.
        if (isset($this->worktreesBase) && is_dir($this->worktreesBase)) {
            TestDirectoryIsolation::removeDirectory($this->worktreesBase);
        }
        TestDirectoryIsolation::removeDirectory($this->boardRoot);
        TestDirectoryIsolation::removeDirectory($this->repoRoot);
    }

    #[Test]
    public function happyPathTodoInProgressDone(): void
    {
        $exec = new StubExec($this->gitStub(...));
        $git = new GitExecutor($exec);
        $store = new TaskBoardStore($this->repoRoot, new TaskWorkflowSettings(taskRoot: $this->boardRoot));
        $handler = new MoveTaskHandler(
            $store,
            $git,
            new WorktreeManager($git, $exec),
            new PrManager($exec),
            $exec,
            new TaskWorkflowSettings(taskRoot: $this->boardRoot),
            $this->repoRoot,
        );

        $slug = '2026-01-01-test-task';
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Test task'));

        $r1 = ($handler)(
            ['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase],
            $this->ctx(),
        );
        $this->assertStringContainsString('Moved task', $r1);
        $branch = 'task/'.$slug;
        $this->assertTrue($this->branchExists($branch));
        $this->assertDirectoryExists($this->worktreesBase.'/'.$slug);

        ($handler)(
            ['task' => $slug, 'from' => 'IN-PROGRESS', 'to' => 'DONE', 'cleanupWorktree' => true],
            $this->ctx(),
        );
        $this->assertFileExists($this->boardRoot.'/DONE/'.$slug.'.md');
    }

    #[Test]
    public function moveTaskToCodeReviewRunsCastorCheckPushesAndCreatesPr(): void
    {
        // Thesis: without this test, IN-PROGRESS→CODE-REVIEW could skip castor check, push, or PR creation and still move the task.
        $slug = '2026-01-01-cr-happy';
        $branch = 'task/'.$slug;
        $worktree = $this->worktreesBase.'/'.$slug;

        $inner = new StubExec($this->gitStubForCodeReview(timeoutExitCode: 0));
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);

        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('CR happy'));
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());

        $r = ($handler)([
            'task' => $slug,
            'from' => 'IN-PROGRESS',
            'to' => 'CODE-REVIEW',
            'castorCheckTimeoutSeconds' => 60,
        ], $this->ctx());

        $this->assertStringContainsString('Moved task', $r);
        $this->assertFileExists($this->boardRoot.'/CODE-REVIEW/'.$slug.'.md');
        $moved = file_get_contents($this->boardRoot.'/CODE-REVIEW/'.$slug.'.md');
        $this->assertIsString($moved);
        $this->assertStringContainsString('Status: CODE-REVIEW', $moved);
        $this->assertStringContainsString('https://github.com/example/pr/1', $moved);

        $timeoutCalls = $this->findCallsByCommand($recording, 'timeout');
        $this->assertNotEmpty($timeoutCalls, 'castor check gate must invoke timeout wrapper');
        $args = $timeoutCalls[0]['args'];
        $this->assertSame('--kill-after=30s', $args[0] ?? '');
        $this->assertSame('60s', $args[1] ?? '');
        $this->assertContains('castor', $args);
        $this->assertContains('check', $args);
        $this->assertSame($worktree, $timeoutCalls[0]['cwd']);

        $gitPush = array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'git' === $c['command'] && \in_array('push', $c['args'], true) && \in_array('-u', $c['args'], true),
        );
        $this->assertNotEmpty($gitPush, 'branch must be pushed before PR');

        $ghCreate = array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'gh' === $c['command'] && \in_array('pr', $c['args'], true) && \in_array('create', $c['args'], true),
        );
        $this->assertNotEmpty($ghCreate, 'gh pr create must run on happy path');
    }

    #[Test]
    public function moveTaskToCodeReviewRefusesWhenCastorCheckFails(): void
    {
        // Thesis: without this test, a failing castor check could still push, open a PR, or move the task off IN-PROGRESS.
        $slug = '2026-01-01-cr-fail';
        $branch = 'task/'.$slug;
        $worktree = $this->worktreesBase.'/'.$slug;

        $inner = new StubExec(function (string $command, array $args, ?ExecOptionsDTO $options) use ($worktree): ExecResultDTO {
            if ('timeout' === $command) {
                $reports = $worktree.'/var/reports/qa-123';
                mkdir($reports, 0o755, true);
                file_put_contents($reports.'/check-test:llm-real.log', 'LLM lane failure: useful first error');

                return new ExecResultDTO('QA run: qa-123\nquality failed: test:llm-real (exit code 1)', 'Castor summary is on stdout.', 1);
            }

            return ($this->gitStubForCodeReview(timeoutExitCode: 0))($command, $args, $options);
        });
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);

        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('CR fail'));
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());

        try {
            ($handler)([
                'task' => $slug,
                'from' => 'IN-PROGRESS',
                'to' => 'CODE-REVIEW',
                'castorCheckTimeoutSeconds' => 60,
            ], $this->ctx());
            $this->fail('Expected RuntimeException when castor check fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Castor check FAILED', $e->getMessage());
            $this->assertStringContainsString('Failing lane: test:llm-real', $e->getMessage());
            $this->assertStringContainsString('var/reports/qa-123/check-test:llm-real.log', $e->getMessage());
            $this->assertStringContainsString('LLM lane failure: useful first error', $e->getMessage());
        }

        $this->assertFileExists($this->boardRoot.'/IN-PROGRESS/'.$slug.'.md');
        $this->assertFileDoesNotExist($this->boardRoot.'/CODE-REVIEW/'.$slug.'.md');

        $gitPush = array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'git' === $c['command'] && \in_array('push', $c['args'], true),
        );
        $this->assertEmpty($gitPush, 'must not push when castor check fails');

        $ghCreate = array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'gh' === $c['command'] && \in_array('create', $c['args'], true),
        );
        $this->assertEmpty($ghCreate, 'must not create PR when castor check fails');
    }

    #[Test]
    public function moveTaskToCodeReviewReportsSetupFailureWithoutInventingLaneOrLog(): void
    {
        $slug = '2026-01-01-cr-setup-fail';
        $inner = new StubExec(function (string $command, array $args, ?ExecOptionsDTO $options): ExecResultDTO {
            if ('timeout' === $command) {
                return new ExecResultDTO('QA run: qa-setup\npreflight failed: lock unavailable', 'Unable to acquire check lock', 1);
            }

            return ($this->gitStubForCodeReview(timeoutExitCode: 0))($command, $args, $options);
        });
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('CR setup fail'));
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());

        try {
            ($handler)(['task' => $slug, 'from' => 'IN-PROGRESS', 'to' => 'CODE-REVIEW', 'castorCheckTimeoutSeconds' => 60], $this->ctx());
            $this->fail('Expected RuntimeException when setup fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('QA reports: var/reports/qa-setup', $e->getMessage());
            $this->assertStringContainsString('preflight failed: lock unavailable', $e->getMessage());
            $this->assertStringNotContainsString('Failing lane:', $e->getMessage());
            $this->assertStringNotContainsString('check-preflight', $e->getMessage());
        }

        $this->assertFileExists($this->boardRoot.'/IN-PROGRESS/'.$slug.'.md');
        $this->assertFileDoesNotExist($this->boardRoot.'/CODE-REVIEW/'.$slug.'.md');
        $this->assertEmpty(array_filter($recording->calls(), static fn (array $call): bool => 'git' === $call['command'] && \in_array('push', $call['args'], true)));
        $this->assertEmpty(array_filter($recording->calls(), static fn (array $call): bool => 'gh' === $call['command'] && \in_array('create', $call['args'], true)));
    }

    #[Test]
    public function refusesDirtyIntegrationCheckout(): void
    {
        file_put_contents($this->repoRoot.'/dirty.txt', 'x');
        $exec = new StubExec($this->gitStub(...));
        $git = new GitExecutor($exec);
        $store = new TaskBoardStore($this->repoRoot, new TaskWorkflowSettings(taskRoot: $this->boardRoot));
        $slug = 'dirty-claim';
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Dirty'));

        $handler = new MoveTaskHandler($store, $git, new WorktreeManager($git, $exec), new PrManager($exec), $exec, new TaskWorkflowSettings(), $this->repoRoot);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integration checkout is not clean');
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());
    }

    #[Test]
    public function cancellationBeforePushSkipsPushAndPrAndLeavesTaskInProgress(): void
    {
        // Thesis: move_task must stop between castor check and push when cancelled, without
        // rewriting status metadata or opening a PR.
        $slug = '2026-01-01-cr-cancel';
        $token = new class implements ToolCancellationTokenInterface {
            public int $checks = 0;

            public function isCancellationRequested(): bool
            {
                // Flip after castor check path has begun polling control.
                return ++$this->checks >= 8;
            }
        };

        $inner = new StubExec(function (string $command, array $args, ?ExecOptionsDTO $options) use ($token): ExecResultDTO {
            if ('timeout' === $command) {
                // Force cancel after check succeeds but before push phase.
                $token->checks = 100;

                return new ExecResultDTO('check ok', '', 0);
            }

            return ($this->gitStubForCodeReview(timeoutExitCode: 0))($command, $args, $options);
        });
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);

        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('CR cancel'));
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());

        $result = ($handler)([
            'task' => $slug,
            'from' => 'IN-PROGRESS',
            'to' => 'CODE-REVIEW',
            'castorCheckTimeoutSeconds' => 60,
        ], $this->ctx(cancellationToken: $token));

        $this->assertIsArray($result);
        $this->assertTrue($result['cancelled'] ?? false);
        $this->assertFileExists($this->boardRoot.'/IN-PROGRESS/'.$slug.'.md');
        $this->assertFileDoesNotExist($this->boardRoot.'/CODE-REVIEW/'.$slug.'.md');

        $gitPush = array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'git' === $c['command'] && \in_array('push', $c['args'], true),
        );
        $this->assertEmpty($gitPush, 'must not push after cancellation');

        $ghCreate = array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'gh' === $c['command'] && \in_array('create', $c['args'], true),
        );
        $this->assertEmpty($ghCreate, 'must not create PR after cancellation');
    }

    #[Test]
    public function composerInstallRunsOnWorktreeCreation(): void
    {
        // Thesis: without this test, move_task → IN-PROGRESS could skip running
        // composer install -d .hatfield/extensions in the new worktree.
        $slug = '2026-01-01-ext-vendor';

        $inner = new StubExec($this->gitStub(...));
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);

        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Ext vendor'));
        $r = ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());

        $this->assertStringContainsString('Installed extensions vendor', $r);

        $composerCalls = $this->findCallsByCommand($recording, 'composer');
        $this->assertNotEmpty($composerCalls, 'composer install must run on worktree creation');

        $call = $composerCalls[0];
        $this->assertContains('install', $call['args']);
        $dashDIndex = array_search('-d', $call['args'], true);
        $this->assertNotFalse($dashDIndex);
        $this->assertStringEndsWith('/.hatfield/extensions', $call['args'][$dashDIndex + 1]);
        $this->assertNotNull($call['cwd']);
        $this->assertStringContainsString($slug, $call['cwd']);
    }

    #[Test]
    public function composerInstallFailureIsNotFatal(): void
    {
        // Thesis: without this test, a failing composer install could block
        // the task from moving to IN-PROGRESS.
        $slug = '2026-01-01-ext-fail';

        $inner = new StubExec(function (string $command, array $args, ?ExecOptionsDTO $options): ExecResultDTO {
            if ('composer' === $command) {
                return new ExecResultDTO('', 'composer failed', 1);
            }

            return $this->gitStub($command, $args, $options);
        });
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);

        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Ext fail'));
        $r = ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());

        $this->assertStringContainsString('Moved task', $r);
        $this->assertFileExists($this->boardRoot.'/IN-PROGRESS/'.$slug.'.md');
        $this->assertDirectoryExists($this->worktreesBase.'/'.$slug);
    }

    #[Test]
    public function doneToArchiveMovesFileWithoutGitSideEffects(): void
    {
        // Thesis: without this test, ARCHIVE could run merge/cleanup side effects
        // or accept non-DONE sources, violating the metadata-only archive contract.
        $slug = '2026-01-01-archive-me';
        $branch = 'task/'.$slug;
        $this->runGit($this->repoRoot, ['branch', $branch]);
        file_put_contents(
            $this->boardRoot.'/DONE/'.$slug.'.md',
            TaskMarkdown::updateField(
                TaskMarkdown::updateField(TaskMarkdown::renderTask('Archive me'), 'Status', 'DONE'),
                'Branch',
                $branch,
            ),
        );

        $inner = new StubExec($this->gitStub(...));
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);

        $result = ($handler)(['task' => $slug, 'from' => 'DONE', 'to' => 'ARCHIVE'], $this->ctx());

        $this->assertFileExists($this->boardRoot.'/ARCHIVE/'.$slug.'.md');
        $this->assertFileDoesNotExist($this->boardRoot.'/DONE/'.$slug.'.md');
        $archived = file_get_contents($this->boardRoot.'/ARCHIVE/'.$slug.'.md');
        $this->assertIsString($archived);
        $this->assertStringContainsString('Status: ARCHIVE', $archived);
        $this->assertStringContainsString('Branch: '.$branch, $archived);
        $this->assertTrue($this->branchExists($branch), 'archive must leave the git branch');

        $gitCalls = $this->findCallsByCommand($recording, 'git');
        $this->assertEmpty($gitCalls, 'DONE→ARCHIVE must not invoke git');

        $this->assertIsString($result);
        $this->assertNull(json_decode($result, true), 'move_task ARCHIVE output must not be a JSON envelope');
        $decoded = Toon::decode($result);
        $this->assertIsArray($decoded);
        $this->assertSame('DONE', $decoded['from'] ?? null);
        $this->assertSame('ARCHIVE', $decoded['to'] ?? null);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertStringContainsString('Moved task', (string) $decoded['message']);
    }

    #[Test]
    public function archiveRejectsNonDoneSource(): void
    {
        $slug = '2026-01-01-archive-bad';
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Not done'));
        $handler = $this->makeHandler(new StubExec($this->gitStub(...)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ARCHIVE is only allowed from DONE');
        ($handler)(['task' => $slug, 'to' => 'ARCHIVE'], $this->ctx());
    }

    #[Test]
    public function cancelWithoutWorktreeMovesTaskOnly(): void
    {
        $slug = '2026-01-01-cancel-plain';
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Cancel plain'));
        $handler = $this->makeHandler(new StubExec($this->gitStub(...)));

        $result = ($handler)(['task' => $slug, 'to' => 'CANCELLED'], $this->ctx());

        $this->assertFileExists($this->boardRoot.'/CANCELLED/'.$slug.'.md');
        $this->assertFileDoesNotExist($this->boardRoot.'/TODO/'.$slug.'.md');
        $this->assertStringContainsString('No Worktree metadata', $result);
    }

    #[Test]
    public function cancelCleanInProgressRemovesWorktreeAndIdeaExclusionButKeepsBranch(): void
    {
        // Thesis: without this test, CANCELLED could leave worktrees/IDEA markers
        // or delete the branch, or move the task before cleanup succeeds.
        $slug = '2026-01-01-cancel-clean';
        $branch = 'task/'.$slug;
        $worktree = $this->worktreesBase.'/'.$slug;

        mkdir($this->worktreesBase.'/.idea', 0o755, true);
        $iml = $this->worktreesBase.'/.idea/'.basename($this->worktreesBase).'.iml';
        file_put_contents($iml, "<?xml version=\"1.0\"?>\n<module>\n  <component name=\"NewModuleRootManager\">\n    <content url=\"file://\$MODULE_DIR$\">\n    </content>\n  </component>\n</module>\n");

        $handler = $this->makeHandler(new StubExec($this->gitStub(...)));
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Cancel clean'));
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());
        $this->assertDirectoryExists($worktree);
        $this->assertStringContainsString('pi-task-workflow:start '.$slug, (string) file_get_contents($iml));

        $result = ($handler)(['task' => $slug, 'from' => 'IN-PROGRESS', 'to' => 'CANCELLED'], $this->ctx());

        $this->assertFileExists($this->boardRoot.'/CANCELLED/'.$slug.'.md');
        $this->assertFileDoesNotExist($this->boardRoot.'/IN-PROGRESS/'.$slug.'.md');
        $this->assertDirectoryDoesNotExist($worktree);
        $this->assertTrue($this->branchExists($branch), 'cancellation must leave the branch');
        $cancelled = file_get_contents($this->boardRoot.'/CANCELLED/'.$slug.'.md');
        $this->assertIsString($cancelled);
        $this->assertStringContainsString('Status: CANCELLED', $cancelled);
        $this->assertStringContainsString('Branch: '.$branch, $cancelled);
        $this->assertStringContainsString('Worktree: '.$worktree, $cancelled);
        $this->assertStringNotContainsString('pi-task-workflow:start '.$slug, (string) file_get_contents($iml));
        $this->assertStringContainsString('Removed worktree', $result);
    }

    #[Test]
    public function cancelDirtyWorktreeFailsClosedWithoutMovingTask(): void
    {
        // Thesis: without this test, dirty-worktree cancellation could force-delete
        // trees, strip IDEA exclusions, or still move the task to CANCELLED.
        $slug = '2026-01-01-cancel-dirty';
        $branch = 'task/'.$slug;
        $worktree = $this->worktreesBase.'/'.$slug;

        mkdir($this->worktreesBase.'/.idea', 0o755, true);
        $iml = $this->worktreesBase.'/.idea/'.basename($this->worktreesBase).'.iml';
        file_put_contents($iml, "<?xml version=\"1.0\"?>\n<module>\n  <component name=\"NewModuleRootManager\">\n    <content url=\"file://\$MODULE_DIR$\">\n    </content>\n  </component>\n</module>\n");

        $handler = $this->makeHandler(new StubExec($this->gitStub(...)));
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Cancel dirty'));
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());
        file_put_contents($worktree.'/dirty.txt', 'dirty');

        try {
            ($handler)(['task' => $slug, 'from' => 'IN-PROGRESS', 'to' => 'CANCELLED'], $this->ctx());
            $this->fail('Expected RuntimeException for dirty worktree cancellation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Safe worktree cleanup failed', $e->getMessage());
        }

        $this->assertFileExists($this->boardRoot.'/IN-PROGRESS/'.$slug.'.md');
        $this->assertFileDoesNotExist($this->boardRoot.'/CANCELLED/'.$slug.'.md');
        $this->assertDirectoryExists($worktree);
        $this->assertTrue($this->branchExists($branch));
        $this->assertStringContainsString('pi-task-workflow:start '.$slug, (string) file_get_contents($iml));
    }

    #[Test]
    public function cancelDestinationCollisionFailsBeforeWorktreeCleanup(): void
    {
        // Thesis: without this test, CANCELLED could remove a clean worktree and IDEA
        // markers, then fail when CANCELLED/<same-file>.md already exists — leaving the
        // board task stranded without its worktree.
        $slug = '2026-01-01-cancel-collision';
        $branch = 'task/'.$slug;
        $worktree = $this->worktreesBase.'/'.$slug;
        $destination = $this->boardRoot.'/CANCELLED/'.$slug.'.md';
        $destinationBody = "# Pre-existing cancelled\n\n## Workflow metadata\nStatus: CANCELLED\n\nDo not overwrite me.\n";

        mkdir($this->worktreesBase.'/.idea', 0o755, true);
        $iml = $this->worktreesBase.'/.idea/'.basename($this->worktreesBase).'.iml';
        file_put_contents($iml, "<?xml version=\"1.0\"?>\n<module>\n  <component name=\"NewModuleRootManager\">\n    <content url=\"file://\$MODULE_DIR$\">\n    </content>\n  </component>\n</module>\n");

        $inner = new StubExec($this->gitStub(...));
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Cancel collision'));
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());
        $this->assertDirectoryExists($worktree);
        $this->assertStringContainsString('pi-task-workflow:start '.$slug, (string) file_get_contents($iml));

        // Existing destination with the same filename must block cancellation preflight.
        file_put_contents($destination, $destinationBody);

        try {
            ($handler)(['task' => $slug, 'from' => 'IN-PROGRESS', 'to' => 'CANCELLED'], $this->ctx());
            $this->fail('Expected RuntimeException for CANCELLED destination collision');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Target task already exists', $e->getMessage());
            $this->assertStringContainsString('CANCELLED/'.$slug.'.md', $e->getMessage());
        }

        $this->assertFileExists($this->boardRoot.'/IN-PROGRESS/'.$slug.'.md');
        $this->assertFileExists($destination);
        $this->assertSame($destinationBody, file_get_contents($destination));
        $this->assertDirectoryExists($worktree);
        $this->assertTrue($this->branchExists($branch), 'collision must leave the branch');
        $this->assertStringContainsString('pi-task-workflow:start '.$slug, (string) file_get_contents($iml));

        $gitCalls = $this->findCallsByCommand($recording, 'git');
        foreach ($gitCalls as $call) {
            $args = $call['args'] ?? [];
            $this->assertFalse(
                ($args[0] ?? null) === 'worktree' && ($args[1] ?? null) === 'remove',
                'destination collision must not run git worktree remove',
            );
        }
    }

    #[Test]
    public function skipsComposerInstallWhenExtensionsDirMissing(): void
    {
        // Thesis: branches without .hatfield/extensions/ should not fail
        // or attempt a phantom composer install on worktree creation.
        $slug = '2026-01-01-ext-missing';
        $branch = 'task/'.$slug;
        $worktree = $this->worktreesBase.'/'.$slug;

        // Create the worktree branch FROM the initial commit (before
        // setUp committed .hatfield/extensions/), so the worktree has no
        // extensions directory. We can't use the default repo HEAD since
        // setUp already committed .hatfield/extensions/.
        $initHash = trim($this->runGitCapture($this->repoRoot, ['rev-parse', 'HEAD~1']));
        $this->runGit($this->repoRoot, ['branch', $branch, $initHash]);

        $inner = new StubExec($this->gitStub(...));
        $recording = new RecordingExec($inner);
        $handler = $this->makeHandler($recording);

        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Ext missing'));
        $r = ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase], $this->ctx());

        $this->assertStringContainsString('Moved task', $r);
        $composerCalls = $this->findCallsByCommand($recording, 'composer');
        $this->assertEmpty($composerCalls, 'must not call composer when extensions dir is missing');
    }

    #[Test]
    public function cleanupPartialPreservesIdeaExclusionsWhenWorktreeRemoveFails(): void
    {
        // Thesis: interrupted worktree create must not strip IDEA exclusions unless
        // `git worktree remove` succeeds (same fail-closed ordering as safe cleanup).
        $slug = '2026-01-01-partial-cleanup';
        $worktree = $this->worktreesBase.'/'.$slug;

        mkdir($this->worktreesBase.'/.idea', 0o755, true);
        $iml = $this->worktreesBase.'/.idea/'.basename($this->worktreesBase).'.iml';
        file_put_contents($iml, "<?xml version=\"1.0\"?>\n<module>\n  <component name=\"NewModuleRootManager\">\n    <content url=\"file://\$MODULE_DIR$\">\n    </content>\n  </component>\n</module>\n");

        $token = new class($iml, $slug) implements ToolCancellationTokenInterface {
            public function __construct(
                private string $iml,
                private string $slug,
            ) {
            }

            public function isCancellationRequested(): bool
            {
                return is_file($this->iml)
                    && str_contains((string) file_get_contents($this->iml), 'pi-task-workflow:start '.$this->slug);
            }
        };

        $inner = new StubExec(function (string $command, array $args, ?ExecOptionsDTO $options): ExecResultDTO {
            if ('git' === $command && ($args[0] ?? null) === 'worktree' && ($args[1] ?? null) === 'remove') {
                return new ExecResultDTO('', 'simulated worktree remove failure', 1);
            }

            return $this->gitStub($command, $args, $options);
        });
        $git = new GitExecutor($inner);
        $manager = new WorktreeManager($git, $inner);

        $task = new TaskInfo(
            status: TaskStatusEnum::TODO,
            file: $slug.'.md',
            path: $this->boardRoot.'/TODO/'.$slug.'.md',
            title: 'Partial cleanup',
        );
        $control = InvocationControl::fromContext($this->ctx(cancellationToken: $token));

        $result = $manager->createWorktreeForTask($this->repoRoot, $task, $this->worktreesBase, $control);

        $this->assertInstanceOf(ExecResultDTO::class, $result);
        $this->assertTrue($result->cancelled);
        $this->assertDirectoryExists($worktree);
        $this->assertStringContainsString(
            'pi-task-workflow:start '.$slug,
            (string) file_get_contents($iml),
            'IDEA exclusions must remain when git worktree remove fails',
        );
    }

    public function gitStubForCodeReview(int $timeoutExitCode): callable
    {
        return function (string $command, array $args, ?ExecOptionsDTO $options) use ($timeoutExitCode): ExecResultDTO {
            if ('timeout' === $command) {
                return new ExecResultDTO('check ok', '', $timeoutExitCode);
            }
            if ('gh' === $command) {
                if (\in_array('auth', $args, true)) {
                    return new ExecResultDTO('', '', 0);
                }
                if (\in_array('pr', $args, true) && \in_array('list', $args, true)) {
                    return new ExecResultDTO('', '', 0);
                }
                if (\in_array('pr', $args, true) && \in_array('create', $args, true)) {
                    return new ExecResultDTO('https://github.com/example/pr/1', '', 0);
                }

                return new ExecResultDTO('', '', 0);
            }
            if ('git' === $command) {
                if (\in_array('remote', $args, true) && \in_array('get-url', $args, true)) {
                    return new ExecResultDTO('https://example.com/repo.git', '', 0);
                }
                if (\in_array('push', $args, true)) {
                    return new ExecResultDTO('pushed', '', 0);
                }
            }

            return $this->gitStub($command, $args, $options);
        };
    }

    public function gitStub(string $command, array $args, ?ExecOptionsDTO $options): ExecResultDTO
    {
        $cwd = $options?->cwd ?? $this->repoRoot;
        if ('timeout' === $command) {
            return new ExecResultDTO('', '', 0);
        }
        if ('git' === $command) {
            $full = 'git '.implode(' ', array_map('escapeshellarg', $args));
            $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($full, $descriptor, $pipes, $cwd);
            if (!\is_resource($proc)) {
                return new ExecResultDTO('', 'proc failed', 1);
            }
            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            return new ExecResultDTO($stdout, $stderr, $code);
        }
        if ('gh' === $command) {
            return new ExecResultDTO('https://github.com/example/pr/1', '', 0);
        }

        return new ExecResultDTO('', '', 0);
    }

    private function ctx(?ToolCancellationTokenInterface $cancellationToken = null, ?int $timeoutSeconds = null): ToolInvocationContextDTO
    {
        return new ToolInvocationContextDTO(
            runId: 'run-move-test',
            cancellationToken: $cancellationToken,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    private function makeHandler(ExecInterface $exec): MoveTaskHandler
    {
        $git = new GitExecutor($exec);

        return new MoveTaskHandler(
            new TaskBoardStore($this->repoRoot, new TaskWorkflowSettings(taskRoot: $this->boardRoot)),
            $git,
            new WorktreeManager($git, $exec),
            new PrManager($exec),
            $exec,
            new TaskWorkflowSettings(taskRoot: $this->boardRoot, castorCheckTimeoutSeconds: 480),
            $this->repoRoot,
        );
    }

    /**
     * @return list<array{command: string, args: list<string>, cwd: ?string}>
     */
    private function findCallsByCommand(RecordingExec $recording, string $command): array
    {
        return array_values(array_filter(
            $recording->calls(),
            static fn (array $c): bool => $command === $c['command'],
        ));
    }

    private function initGitRepo(string $root): void
    {
        $this->runGit($root, ['init', '-b', 'main']);
        $this->runGit($root, ['config', 'user.email', 'test@example.com']);
        $this->runGit($root, ['config', 'user.name', 'Test']);
        $this->runGit($root, ['config', 'commit.gpgsign', 'false']);
        file_put_contents($root.'/README', 'init');
        $this->runGit($root, ['add', 'README']);
        $this->runGit($root, ['commit', '-m', 'init']);
    }

    /**
     * @param list<string> $args
     */
    private function runGit(string $cwd, array $args): void
    {
        $cmd = 'git '.implode(' ', array_map('escapeshellarg', $args));
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptor, $pipes, $cwd);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('proc_open failed');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (0 !== $code) {
            throw new \RuntimeException('git failed: '.$cmd.' code='.$code);
        }
    }

    /**
     * @param list<string> $args
     */
    private function runGitCapture(string $cwd, array $args): string
    {
        $cmd = 'git '.implode(' ', array_map('escapeshellarg', $args));
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptor, $pipes, $cwd);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('proc_open failed');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (0 !== $code) {
            throw new \RuntimeException('git failed: '.$cmd.' code='.$code);
        }

        return $stdout;
    }

    private function branchExists(string $branch): bool
    {
        $proc = proc_open('git show-ref --verify --quiet refs/heads/'.$branch, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        if (!\is_resource($proc)) {
            return false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }
}
