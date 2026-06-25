<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskBoardStoreTest extends TestCase
{
    private string $boardRoot;
    private TaskBoardStore $store;

    protected function setUp(): void
    {
        $this->boardRoot = TestDirectoryIsolation::createProjectTempDir('tw-board');
        foreach (TaskStatusEnum::all() as $s) {
            mkdir($this->boardRoot.'/'.$s->value, 0o755, true);
        }
        $codeRoot = TestDirectoryIsolation::createProjectTempDir('tw-code');
        putenv('HATFIELD_TASK_WORKFLOW_ROOT='.$this->boardRoot);
        $this->store = new TaskBoardStore($codeRoot,
            new TaskWorkflowSettings(taskRoot: $this->boardRoot),
        );
    }

    protected function tearDown(): void
    {
        putenv('HATFIELD_TASK_WORKFLOW_ROOT');
        TestDirectoryIsolation::removeDirectory($this->boardRoot);
    }

    #[Test]
    public function listTasksReadsFields(): void
    {
        $path = $this->boardRoot.'/TODO/sample.md';
        file_put_contents($path, TaskMarkdown::renderTask('Hello'));
        $tasks = $this->store->listTasks($this->boardRoot, TaskStatusEnum::TODO);
        $this->assertCount(1, $tasks);
        $this->assertSame('Hello', $tasks[0]->title);
    }

    #[Test]
    public function findTaskNoMatchThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No task matched');
        $this->store->findTask($this->boardRoot, 'missing');
    }

    #[Test]
    public function findTaskAmbiguousThrows(): void
    {
        file_put_contents($this->boardRoot.'/TODO/a-demo.md', TaskMarkdown::renderTask('A demo'));
        file_put_contents($this->boardRoot.'/TODO/b-demo.md', TaskMarkdown::renderTask('B demo'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is ambiguous');
        $this->store->findTask($this->boardRoot, 'demo');
    }

    #[Test]
    public function moveFileWithMetadataMoves(): void
    {
        $path = $this->boardRoot.'/TODO/move-me.md';
        file_put_contents($path, TaskMarkdown::renderTask('Move'));
        $task = $this->store->findTask($this->boardRoot, 'move-me');
        $text = file_get_contents($path);
        $target = $this->store->moveFileWithMetadata($task, TaskStatusEnum::IN_PROGRESS, $text, $this->boardRoot);
        $this->assertFileExists($target);
        $this->assertFileDoesNotExist($path);
    }
}
