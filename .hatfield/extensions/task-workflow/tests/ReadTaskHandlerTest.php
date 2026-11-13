<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskTransitionException;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ReadTaskHandler;
use PHPUnit\Framework\TestCase;

final class ReadTaskHandlerTest extends TestCase
{
    public function testSelectedIssueReadDoesNotListCommentsArtifactsOrRunHooks(): void
    {
        $fixture = new TransitionFixture();
        try {
            $result = (new ReadTaskHandler($fixture->store, $fixture->states, $fixture->settings))(['task' => '7'], new ToolInvocationContextDTO(runId: 'test'));
            $this->assertSame('Specification', Toon::decode($result)['task']['body']);
            $this->assertSame([], $fixture->hookCalls);
            foreach ($fixture->calls as [$command, $args]) {
                $this->assertStringNotContainsString('/comments', implode(' ', $args));
                $this->assertStringNotContainsString('/artifacts', implode(' ', $args));
            }
        } finally {
            $fixture->workspace->close();
        }
    }

    public function testArtifactUrlMustBelongToSelectedIssue(): void
    {
        $this->assertSame(2, ReadTaskHandler::artifactNumber('https://github.com/acme/project/issues/7/artifacts/2', 'https://github.com/acme/project/issues/7'));
        $this->expectException(TaskTransitionException::class);
        ReadTaskHandler::artifactNumber('https://github.com/acme/project/issues/8/artifacts/2', 'https://github.com/acme/project/issues/7');
    }
}
