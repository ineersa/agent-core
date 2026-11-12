<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Command\TasksCommandHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\RecordingCommandContext;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\TaskListFormatter;
use PHPUnit\Framework\TestCase;

final class TasksCommandHandlerTest extends TestCase
{
    public function testCommandShowsClosedActiveAndLocalUnfinishedTasks(): void
    {
        $fixture = new TransitionFixture();
        try {
            $fixture->status = 'CODE-REVIEW';
            $fixture->issueState = 'closed';
            $fixture->states->initialize('acme/project', 99, 'https://github.com/acme/project/issues/99', '', '');
            $fixture->states->beginCleanup('acme/project', 99, 'cancel');
            $context = new RecordingCommandContext();
            (new TasksCommandHandler(TaskStatusEnum::CODE_REVIEW, $fixture->store, $fixture->states, $fixture->settings, new TaskListFormatter()))->handle('', $context);
            $this->assertSame('info', $context->notifications[0]['level']);
            $this->assertStringContainsString('awaiting finalization', $context->notifications[0]['message']);
            $this->assertStringContainsString('Local #99', $context->notifications[0]['message']);
            $this->assertSame([], $fixture->hookCalls);
        } finally {
            $fixture->workspace->close();
        }
    }
}
