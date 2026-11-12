<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\WorkspaceFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\HookRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectTaskHooksTest extends TestCase
{
    public function testSetupAndCleanupAreRetryableIncludingMissingWorkspace(): void
    {
        $fixture = new WorkspaceFixture();
        try {
            $hooks = $fixture->root.'/.hatfield/task-hooks';
            (new Filesystem())->mirror(\dirname(__DIR__, 3).'/task-hooks', $hooks);
            mkdir($fixture->root.'/vendor');
            file_put_contents($fixture->root.'/vendor/autoload.php', '<?php');
            mkdir($fixture->root.'/.vera');
            file_put_contents($fixture->root.'/.vera/index', 'index');
            mkdir($fixture->root.'/.idea');
            file_put_contents($fixture->root.'/.idea/primary.iml', '<module><content url="file://$MODULE_DIR$" /></module>');
            $worktree = $fixture->directory.'/task';
            mkdir($worktree);
            mkdir($fixture->directory.'/.idea');
            $parentIml = $fixture->directory.'/.idea/'.basename($fixture->directory).'.iml';
            file_put_contents($parentIml, "<module>\n<content>\n</content>\n</module>");
            $context = ['HATFIELD_TASK_REPOSITORY' => 'owner/repo', 'HATFIELD_TASK_NUMBER' => '1', 'HATFIELD_TASK_URL' => 'url', 'HATFIELD_TASK_BRANCH' => 'task/1', 'HATFIELD_TASK_BASE_BRANCH' => 'main', 'HATFIELD_TASK_WORKTREE' => $worktree, 'HATFIELD_TASK_SETUP_ATTEMPTED' => '1', 'HATFIELD_TASK_REASON' => 'start', 'HATFIELD_TASK_RESOURCE_ID' => 'stable', 'HATFIELD_TASK_DATA_DIR' => $fixture->directory.'/data'];
            $runner = new HookRunner($fixture);
            foreach ([1, 2] as $attempt) {
                $result = $runner->run($fixture->root, '.hatfield/task-hooks/setup.sh', 3, $context);
                $this->assertSame(0, $result->exitCode, $result->stderr);
            }
            $this->assertFileEquals($fixture->root.'/vendor/autoload.php', $worktree.'/vendor/autoload.php');
            $this->assertFileEquals($fixture->root.'/.vera/index', $worktree.'/.vera/index');
            $this->assertFileExists($worktree.'/.idea/task.iml');
            $this->assertStringContainsString('pi-task-workflow:start task', file_get_contents($parentIml));
            TestDirectoryIsolation::removeDirectory($worktree);
            $context['HATFIELD_TASK_REASON'] = 'cancel';
            foreach ([1, 2] as $attempt) {
                $result = $runner->run($fixture->root, '.hatfield/task-hooks/cleanup.sh', 3, $context);
                $this->assertSame(0, $result->exitCode, $result->stderr);
            }
            $this->assertStringNotContainsString('pi-task-workflow:start task', file_get_contents($parentIml));
            $context['HATFIELD_TASK_WORKTREE'] = '';
            $context['HATFIELD_TASK_SETUP_ATTEMPTED'] = '0';
            $this->assertSame(0, $runner->run($fixture->root, '.hatfield/task-hooks/cleanup.sh', 3, $context)->exitCode);
        } finally {
            $fixture->close();
        }
    }
}
