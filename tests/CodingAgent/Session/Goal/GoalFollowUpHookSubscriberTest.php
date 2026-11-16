<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Goal;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\Goal\GoalFollowUpHookSubscriber;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class GoalFollowUpHookSubscriberTest extends IsolatedKernelTestCase
{
    public function testCommittedCompletionUsesDurableCoordinationAndNativeMailboxDeduplication(): void
    {
        $container = self::getContainer();
        $id = $container->get(HatfieldSessionStore::class)->createSession('', 'Objective');
        $state = new RunState($id, RunStatus::Running);
        $events = [
            RunEvent::forAppend($id, 0, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($id, 1, 'llm_step_completed', ['step_id' => 'answer', 'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer']]]]),
            RunEvent::forAppend($id, 1, 'agent_end', ['reason' => 'completed']),
        ];
        $container->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $container->get(RunCommit::class)->commit($state, $container->get(RunStateReducer::class)->replay($state, $events), $events);
        $commands = array_values(array_filter($container->get('messenger.transport.run_control')->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof ApplyCommand && $envelope->getMessage()->runId() === $id));
        $this->assertCount(1, $commands);
        $command = $commands[0]->getMessage();
        $this->assertSame('goal-follow-up-3', $command->stepId());
        $processor = $container->get(RunMessageProcessor::class);
        $processor->process('command', $command);
        $processor->process('command', $command);
        $pending = $container->get(CommandStoreInterface::class)->pending($id);
        $this->assertCount(1, $pending);
        $this->assertSame(CoreCommandKind::FollowUp, $pending[0]->kind);
        $this->assertTrue($pending[0]->payload['message']['metadata']['system_reminder']);
    }

    public function testRegisteredHookPreparesOneNativeFollowUpWithStableIdentity(): void
    {
        $id = self::getContainer()->get(HatfieldSessionStore::class)->createSession('', 'Objective');
        $context = $this->context($id, 'agent_end', 'completed');
        $actions = self::getContainer()->get(HookDispatcher::class)->prepareAfterTurnCommit($context, 41);
        $goalActions = array_values(array_filter($actions, static fn (object $action): bool => $action instanceof DispatchCoordinationMessageDTO && str_starts_with($action->message->stepId(), 'goal-follow-up-')));
        $this->assertCount(1, $goalActions);
        $command = $goalActions[0]->message;
        $this->assertSame(CoreCommandKind::FollowUp, $command->kind);
        $this->assertSame($id, $command->runId());
        $this->assertTrue($command->payload['message']['metadata']['system_reminder']);
        $this->assertStringContainsString('get_goal first', $command->payload['message']['content'][0]['text']);
        $again = self::getContainer()->get(GoalFollowUpHookSubscriber::class)->prepareAfterTurnCommit($context, 41);
        $this->assertSame($command->idempotencyKey(), $again[0]->message->idempotencyKey());
        $later = self::getContainer()->get(GoalFollowUpHookSubscriber::class)->prepareAfterTurnCommit($context, 43);
        $this->assertNotSame($command->idempotencyKey(), $later[0]->message->idempotencyKey());
        self::getContainer()->get(SessionGoalService::class)->set($id, null);
        $this->assertSame([], self::getContainer()->get(GoalFollowUpHookSubscriber::class)->prepareAfterTurnCommit($context, 41));
    }

    public function testIntermediateCancelledFailedChildAndAbsentGoalNeverFollowUp(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $id = $store->createSession('', 'Objective');
        $hook = self::getContainer()->get(GoalFollowUpHookSubscriber::class);
        foreach (['llm_step_completed', 'tool_execution_end', 'turn_advanced', 'context_compacted'] as $type) {
            $this->assertSame([], $hook->prepareAfterTurnCommit($this->context($id, $type, 'completed'), 41), $type);
        }
        foreach (['cancelled', 'failed', 'waiting_human'] as $reason) {
            $this->assertSame([], $hook->prepareAfterTurnCommit($this->context($id, 'agent_end', $reason), 41));
        }
        $context = $this->context($id, 'agent_end', 'completed', 'parent');
        $this->assertSame([], $hook->prepareAfterTurnCommit($context, 41));
        $this->assertSame([], $hook->prepareAfterTurnCommit($this->context($store->createSession(), 'agent_end', 'completed'), 41));
    }

    public function testStandaloneShellCompletionDoesNotStartGoalPursuit(): void
    {
        $id = self::getContainer()->get(HatfieldSessionStore::class)->createSession('', 'Objective');
        $state = new RunState($id, RunStatus::Completed);
        $events = [
            new AfterTurnCommitEventSummary(41, 'tool_execution_end', ['tool_name' => 'bash', 'standalone' => true]),
            new AfterTurnCommitEventSummary(42, 'agent_end', ['reason' => 'completed']),
        ];
        $context = new AfterTurnCommitHookContext($id, 4, 'completed', $events, 0, $state);
        $this->assertSame([], self::getContainer()->get(GoalFollowUpHookSubscriber::class)->prepareAfterTurnCommit($context, 40));
    }

    private function context(string $id, string $type, string $reason, ?string $parent = null): AfterTurnCommitHookContext
    {
        $state = new RunState($id, RunStatus::Completed, parentRunId: $parent);
        $events = [new AfterTurnCommitEventSummary(42, $type, ['reason' => $reason])];
        if ('agent_end' === $type) {
            array_unshift($events, new AfterTurnCommitEventSummary(41, 'llm_step_completed', []));
        }

        return new AfterTurnCommitHookContext($id, 4, 'completed', $events, 0, $state);
    }
}
