<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionActivityHookSubscriber;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class SessionActivityHookSubscriberTest extends IsolatedKernelTestCase
{
    private ClockInterface $previousClock;
    private MockClock $clock;
    private HatfieldSessionStore $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousClock = Clock::get();
        $this->clock = new MockClock('2026-11-01 12:00:00');
        Clock::set($this->clock);
        $this->sessions = self::getContainer()->get(HatfieldSessionStore::class);
    }

    protected function tearDown(): void
    {
        Clock::set($this->previousClock);
        parent::tearDown();
    }

    public function testRegisteredHookMakesOlderSessionMostRecentWithoutMetadataChanges(): void
    {
        $older = $this->sessions->createSession('Older session');
        $this->clock->modify('+1 minute');
        $newer = $this->sessions->createSession('Newer session');
        $this->assertSame([$newer, $older], array_column($this->sessions->listSessions(), 'sessionId'));
        $this->clock->modify('+1 minute');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $context = $this->context($older);
        $dispatcher = self::getContainer()->get(HookDispatcher::class);
        $this->assertSame($context, $dispatcher->dispatchAfterTurnCommit($context));

        $rows = $this->sessions->listSessions();
        $this->assertSame([$older, $newer], array_column($rows, 'sessionId'));
        $this->assertSame('Older session', $rows[0]['name']);
        $this->assertSame('2026-11-01T12:02:00+00:00', $rows[0]['updated_at']);
        $this->assertSame([$older, $newer], array_column($this->sessions->listCompletionSessions('', 10), 'sessionId'));
        $this->assertSame([], $em->getUnitOfWork()->getIdentityMap());
    }

    public function testEmptyCommitsUnknownChildrenAndDelayedUpdatesDoNotChangeCatalog(): void
    {
        $older = $this->sessions->createSession('Older session');
        $this->clock->modify('+1 minute');
        $this->sessions->createSession('Newer session');
        $before = $this->sessions->listSessions();
        $subscriber = self::getContainer()->get(SessionActivityHookSubscriber::class);
        $this->clock->modify('+1 minute');
        $subscriber->handleAfterTurnCommit($this->context($older, []));
        foreach (['', 'native-child-uuid', '9999999999999999999999999999999999'] as $unknown) {
            $subscriber->handleAfterTurnCommit($this->context($unknown));
        }
        $this->clock->modify('-1 day');
        $subscriber->handleAfterTurnCommit($this->context($older));
        $this->assertSame($before, $this->sessions->listSessions());
    }

    public function testEqualUpdateTimesUseNewestIdFirst(): void
    {
        $first = $this->sessions->createSession('First');
        $second = $this->sessions->createSession('Second');
        $this->assertSame([$second, $first], array_column($this->sessions->listSessions(), 'sessionId'));
        $this->assertSame([$second, $first], array_column($this->sessions->listCompletionSessions('', 10), 'sessionId'));
    }

    public function testOnlyAcceptedUserInputsRefreshRecency(): void
    {
        $id = $this->sessions->createSession('Conversation');
        $subscriber = self::getContainer()->get(SessionActivityHookSubscriber::class);
        $inputs = [new AfterTurnCommitEventSummary(1, 'run_started', [])];
        foreach (['follow_up', 'steer', 'append_message'] as $kind) {
            $inputs[] = new AfterTurnCommitEventSummary(2, 'agent_command_queued', ['kind' => $kind]);
        }
        foreach (['human_response', 'shell_command'] as $kind) {
            $inputs[] = new AfterTurnCommitEventSummary(3, 'agent_command_applied', ['kind' => $kind]);
        }
        foreach ($inputs as $event) {
            $this->clock->modify('+1 minute');
            $subscriber->handleAfterTurnCommit($this->context($id, [$event]));
            $this->assertSame($this->clock->now()->format(\DateTimeInterface::ATOM), $this->sessions->listSessions()[0]['updated_at'], $event->type);
        }

        $before = $this->sessions->listSessions();
        foreach (['llm_step_completed', 'llm_step_failed', 'llm_step_aborted', 'tool_execution_start', 'tool_execution_update', 'tool_execution_end', 'tool_batch_committed', 'turn_advanced', 'agent_end', 'waiting_human', 'context_compacted', 'agent_command_rejected'] as $type) {
            $this->clock->modify('+1 minute');
            $subscriber->handleAfterTurnCommit($this->context($id, [new AfterTurnCommitEventSummary(4, $type, [])]));
            $this->assertSame($before, $this->sessions->listSessions(), $type);
        }
        foreach (['follow_up', 'steer', 'append_message', 'cancel', 'compact'] as $kind) {
            $this->clock->modify('+1 minute');
            $subscriber->handleAfterTurnCommit($this->context($id, [new AfterTurnCommitEventSummary(5, 'agent_command_applied', ['kind' => $kind])]));
            $this->assertSame($before, $this->sessions->listSessions(), $kind.' application must not double-count input');
        }
    }

    public function testAutomaticMessagesDoNotRefreshRecency(): void
    {
        $id = $this->sessions->createSession('Conversation');
        $before = $this->sessions->listSessions();
        $subscriber = self::getContainer()->get(SessionActivityHookSubscriber::class);
        foreach (['agent_communication' => [], 'background_completion' => [], 'agent_task' => [], 'system_reminder' => true] as $key => $value) {
            foreach (['follow_up', 'steer', 'append_message'] as $kind) {
                $this->clock->modify('+1 minute');
                $subscriber->handleAfterTurnCommit($this->context($id, [new AfterTurnCommitEventSummary(1, 'agent_command_queued', [
                    'kind' => $kind,
                    'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Automatic notice']], 'metadata' => [$key => $value]],
                ])]));
                $this->assertSame($before, $this->sessions->listSessions(), $key);
            }
        }
    }

    /** @param list<AfterTurnCommitEventSummary>|null $events */
    private function context(string $runId, ?array $events = null): AfterTurnCommitHookContext
    {
        return new AfterTurnCommitHookContext(
            runId: $runId, turnNo: 1, status: RunStatus::Running->value,
            events: $events ?? [new AfterTurnCommitEventSummary(1, 'agent_command_queued', ['kind' => 'follow_up'])],
            effectsCount: 0, runState: new RunState($runId, RunStatus::Running, turnNo: 1),
        );
    }
}
