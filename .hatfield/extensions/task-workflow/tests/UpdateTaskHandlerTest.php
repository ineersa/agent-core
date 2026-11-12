<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\UpdateTaskHandler;
use PHPUnit\Framework\TestCase;

final class UpdateTaskHandlerTest extends TestCase
{
    public function testCurrentBodyAndIntentionalDiscussionReplaceNoWorkflowOrDiary(): void
    {
        $fixture = new TransitionFixture();
        try {
            $body = "## Test plan\nRegression\n\n## QA results\nFocused checks passed; manual check unrun.\n";
            $result = (new UpdateTaskHandler($fixture->store, $fixture->states, $fixture->settings))(['task' => '7', 'body' => $body, 'comment' => 'Clarify remaining acceptance check.'], new ToolInvocationContextDTO(runId: 'test'));
            $this->assertSame($body, Toon::decode($result)['task']['body']);
            $this->assertSame($body, $fixture->taskBody);
            $this->assertSame('TODO', $fixture->status);
            $this->assertSame('Clarify remaining acceptance check.', $fixture->comment);
            $this->assertSame([], $fixture->hookCalls);
        } finally {
            $fixture->workspace->close();
        }
    }

    public function testSelectedArtifactReceivesEditWithoutChangingIssue(): void
    {
        $fixture = new TransitionFixture();
        try {
            $result = (new UpdateTaskHandler($fixture->store, $fixture->states, $fixture->settings))(['task' => '7', 'artifact' => '2', 'title' => 'Revised plan', 'body' => 'Updated artifact content'], new ToolInvocationContextDTO(runId: 'test'));
            $this->assertSame('Updated artifact content', Toon::decode($result)['artifact']['body']);
            $this->assertSame('Revised plan', $fixture->artifact['name']);
            $this->assertSame('Title', $fixture->taskTitle);
            $this->assertSame('Specification', $fixture->taskBody);
            $this->assertSame('TODO', $fixture->status);
        } finally {
            $fixture->workspace->close();
        }
    }
}
