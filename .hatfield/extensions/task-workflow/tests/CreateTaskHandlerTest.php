<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\GitHubTaskException;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\CreateTaskHandler;
use PHPUnit\Framework\TestCase;

final class CreateTaskHandlerTest extends TestCase
{
    public function testPreparedTemplateBecomesCurrentBodyWithoutFrontmatter(): void
    {
        $fixture = new TransitionFixture();
        try {
            $result = (new CreateTaskHandler($fixture->store))(['title' => 'Bug', 'body' => "---\nname: Bug report\nabout: Describe a defect\n---\n\n## Root cause\nNot investigated yet.\n\n## QA results\nNot run.\n"], new ToolInvocationContextDTO(runId: 'test'));
            $decoded = Toon::decode($result);
            $this->assertSame('TODO', $decoded['task']['status']);
            $this->assertSame(7, $decoded['task']['number']);
            $this->assertStringNotContainsString('about:', $fixture->taskBody);
            $this->assertStringContainsString('Not investigated yet.', $fixture->taskBody);
            $this->assertStringContainsString('Not run.', $fixture->taskBody);
        } finally {
            $fixture->workspace->close();
        }
    }

    public function testCancelledInvocationDoesNotReachGithub(): void
    {
        $fixture = new TransitionFixture();
        try {
            $before = \count($fixture->calls);
            $token = new class implements ToolCancellationTokenInterface {
                public function isCancellationRequested(): bool
                {
                    return true;
                }
            };
            try {
                (new CreateTaskHandler($fixture->store))(['title' => 'Title', 'body' => 'Plan'], new ToolInvocationContextDTO(runId: 'test', cancellationToken: $token));
                $this->fail('Cancellation must refuse before GitHub mutation.');
            } catch (GitHubTaskException $exception) {
                $this->assertStringContainsString('interrupted', $exception->getMessage());
                $this->assertCount($before, $fixture->calls);
            }
        } finally {
            $fixture->workspace->close();
        }
    }
}
