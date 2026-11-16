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

    /** @param list<AfterTurnCommitEventSummary>|null $events */
    private function context(string $runId, ?array $events = null): AfterTurnCommitHookContext
    {
        return new AfterTurnCommitHookContext(
            runId: $runId, turnNo: 1, status: RunStatus::Running->value,
            events: $events ?? [new AfterTurnCommitEventSummary(1, 'llm_step_completed', [])],
            effectsCount: 0, runState: new RunState($runId, RunStatus::Running, turnNo: 1),
        );
    }
}
