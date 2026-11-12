<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ListTasksHandler;
use PHPUnit\Framework\TestCase;

final class ListTasksHandlerTest extends TestCase
{
    public function testClosedActiveIssueAndTerminalLocalLeftoversRemainVisibleWithoutHooks(): void
    {
        $fixture = new TransitionFixture();
        try {
            $fixture->status = 'CODE-REVIEW';
            $fixture->issueState = 'closed';
            $fixture->states->initialize('acme/project', 99, 'https://github.com/acme/project/issues/99', '', '');
            $fixture->states->beginCleanup('acme/project', 99, 'cancel');
            $result = (new ListTasksHandler($fixture->store, $fixture->states, $fixture->settings))(['status' => 'CODE-REVIEW'], new ToolInvocationContextDTO(runId: 'test'));
            $decoded = Toon::decode($result);
            $this->assertArrayNotHasKey('message', $decoded);
            $this->assertSame('CODE-REVIEW', $decoded['tasks'][0]['status']);
            $this->assertSame('closed', $decoded['tasks'][0]['state']);
            $this->assertSame(99, $decoded['local_workspaces'][0]['issue']);
            $this->assertSame('cancel', $decoded['local_workspaces'][0]['pending_operation']);
            $this->assertSame([], $fixture->hookCalls);
        } finally {
            $fixture->workspace->close();
        }
    }
}
