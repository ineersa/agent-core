<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Tool\AgentCancelTool;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class AgentCancelToolTest extends IsolatedKernelTestCase
{
    #[DataProvider('selectors')]
    public function testSubmitsNativeCancellationForOnlyTheSelectedChild(string $selector, AgentArtifactKindEnum $kind): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c, kind: $kind);
        $transport = $c->get('messenger.transport.run_control');
        $transport->reset();
        $handle = 'artifact_id' === $selector ? $f->artifact : $f->child;

        $this->assertSame(['status' => 'cancellation_requested', 'artifact_id' => $f->artifact, 'agent_run_id' => $f->child], $this->execute($f->parent, [$selector => $handle]));
        $sent = $transport->getSent();
        $this->assertCount(1, $sent);
        $command = $sent[0]->getMessage();
        $this->assertInstanceOf(ApplyCommand::class, $command);
        $this->assertSame($f->child, $command->runId());
        $this->assertSame(CoreCommandKind::Cancel, $command->kind);

        $statuses = $c->get(RunOperationalStatusReaderInterface::class);
        $this->assertSame(RunStatus::Running, $statuses->findOperationalStatus($f->child)?->status);
        // A child with no in-flight operation can settle immediately at the native owner boundary.
        $active = $c->get(ActiveRunContextInterface::class);
        $active->loadRecovered($f->childState->with(['pendingToolCalls' => []]));
        $c->get('agent.command.bus')->dispatch($sent[0], [new ReceivedStamp('run_control')]);
        $this->assertSame(RunStatus::Cancelled, $statuses->findOperationalStatus($f->child)?->status);
        $this->assertSame(RunStatus::Running, $statuses->findOperationalStatus($f->parent)?->status);
        $events = $c->get(EventStoreInterface::class)->allFor($f->child);
        $this->assertNotNull(array_find($events, static fn ($event): bool => 'agent_end' === $event->type && 'cancelled' === ($event->payload['reason'] ?? null)));
    }

    /** @return iterable<string, array{string, AgentArtifactKindEnum}> */
    public static function selectors(): iterable
    {
        yield 'subagent by artifact id' => ['artifact_id', AgentArtifactKindEnum::Subagent];
        yield 'fork by run id' => ['agent_run_id', AgentArtifactKindEnum::Fork];
    }

    public function testResumedChildUsesItsCurrentBatchOrigin(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $c->get(DeferredSubagentBatchRepository::class)->reserveBatch(
            $f->lifecycle.'-resume', $f->parent, 2, 'resume', 0, new \DateTimeImmutable('+1 hour'),
            new DeferredSubagentBatchChildIntentDTO($f->child, $f->artifact, 'scout', 'resume task', 'model', 'medium'),
        );

        $this->assertRefused($f->parent, $f->child, 'current branch');
        $f->append($f->parent, 4, 2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'resume-step']);
        $f->append($f->parent, 5, 2, 'llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'resume', 'name' => 'agent_resume']]]]);

        $this->assertSame(['status' => 'cancellation_requested', 'artifact_id' => $f->artifact, 'agent_run_id' => $f->child], $this->execute($f->parent, ['artifact_id' => $f->artifact]));
    }

    public function testToolIsMainOnlyAndRequiresExactlyOneNonblankHandle(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c, [AgentCancelTool::NAME]);
        $resolver = $c->get(ToolSetResolverInterface::class);
        $this->assertContains(AgentCancelTool::NAME, $resolver->resolve('current', runId: $f->parent)->allowListNames);
        $this->assertNotContains(AgentCancelTool::NAME, $resolver->resolve('current', runId: $f->child)->allowListNames);

        foreach ([[], ['artifact_id' => $f->artifact, 'agent_run_id' => $f->child], ['artifact_id' => ' ']] as $arguments) {
            $this->assertInstanceOf(ConstraintViolationListInterface::class, $this->execute($f->parent, $arguments));
        }

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active main run');
        $this->execute($f->child, ['agent_run_id' => $f->child]);
    }

    public function testRefusesTerminalForeignAndPriorLifetimeTargetsWithoutDispatching(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $foreign = new SubmissionFixture($c);
        $transport = $c->get('messenger.transport.run_control');
        $transport->reset();
        $this->assertRefused($f->parent, $foreign->child, 'Unknown direct child');
        $this->assertRefused($f->parent, $f->parent, 'Unknown direct child');

        $f->status($f->child, RunStatus::Completed);
        $this->assertRefused($f->parent, $f->child, 'requires an active child');

        $f->status($f->child, RunStatus::Running);
        $c->get(AgentArtifactRegistry::class)->beginParentLifetime($f->parent);
        $this->assertRefused($f->parent, $f->child, 'Unknown direct child');
        $this->assertSame([], $transport->getSent());
    }

    private function assertRefused(string $parent, string $child, string $message): void
    {
        try {
            $this->execute($parent, ['agent_run_id' => $child]);
            $this->fail('Expected cancellation to be refused.');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @param array<string, string> $arguments */
    private function execute(string $runId, array $arguments): mixed
    {
        $c = self::getContainer();
        $toolbox = new FaultTolerantToolbox($c->get(ToolboxInterface::class));
        $context = new ToolContext($runId, 1, 'cancel-child', AgentCancelTool::NAME, new NullCancellationToken(), null, stepId: 'step');

        return $c->get(StackToolExecutionContextAccessor::class)->with($context, static fn () => $toolbox->execute(new ToolCall('cancel-child', AgentCancelTool::NAME, $arguments))->getResult());
    }
}
