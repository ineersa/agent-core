<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Command\TasksCommandHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\TaskListFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeCommandContext implements CommandContextInterface
{
    /** @var list<array{message: string, level: string}> */
    public array $notifications = [];

    public function notify(string $message, string $level = 'info'): void
    {
        $this->notifications[] = ['message' => $message, 'level' => $level];
    }
}

final class TasksCommandHandlerTest extends TestCase
{
    #[Test]
    public function notifyWithListText(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tw-cmd');
        try {
            putenv('HATFIELD_TASK_WORKFLOW_ROOT='.$dir);
            mkdir($dir.'/TODO', 0o755, true);
            file_put_contents($dir.'/TODO/x.md', TaskMarkdown::renderTask('Listed'));
            $store = new TaskBoardStore($dir, new TaskWorkflowSettings(taskRoot: $dir));
            $handler = new TasksCommandHandler(TaskStatusEnum::TODO, 'TODO', $store, new TaskListFormatter($store));
            $ctx = new FakeCommandContext();
            $handler->handle('', $ctx);
            $this->assertCount(1, $ctx->notifications);
            $this->assertStringContainsString('Listed', $ctx->notifications[0]['message']);
        } finally {
            putenv('HATFIELD_TASK_WORKFLOW_ROOT');
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
