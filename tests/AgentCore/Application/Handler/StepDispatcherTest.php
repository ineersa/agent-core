<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\EffectDispatchFailedEvent;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StepDispatcherTest extends TestCase
{
    public function testDispatchesCoordinationActionsOnCommandBusInOrder(): void
    {
        $commandBus = new TestMessageBus();
        $dispatcher = new StepDispatcher($commandBus, $commandBus, new \Ineersa\AgentCore\Tests\Support\TestLogger(), events: new EventDispatcher());

        $advance = new AdvanceRun('run-1', 1, 'advance-1', 1, 'advance-key');
        $compact = new CompactRun('run-1', 1, 'compact-1', 1, 'compact-key');

        $dispatcher->dispatchCoordinationActions([$advance, $compact]);

        $this->assertSame([$advance, $compact], $commandBus->messages);
    }

    public function testPartialRejectionIsReportedAfterLockReleaseWithoutStoppingOtherSends(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $locks = new RunLockManager($factory);
        $events = new EventDispatcher();
        $outcomes = [];
        $events->addListener(EffectDispatchFailedEvent::class, static function (EffectDispatchFailedEvent $event) use (&$outcomes): void {
            $outcomes[] = $event;
        });
        $first = new AdvanceRun('run-1', 1, 'first', 1, 'first');
        $second = new CompactRun('run-1', 1, 'second', 1, 'second');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(function (object $message) use ($factory, $first): Envelope {
            $probe = $factory->createLock('agent_loop.run.run-1');
            $this->assertTrue($probe->acquire(), 'Messenger must not run under the owner lock.');
            $probe->release();
            if ($message === $first) {
                throw new \RuntimeException('sensitive transport detail');
            }

            return new Envelope($message);
        });
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $dispatcher = new StepDispatcher($bus, $bus, $logger, $events);
        $locks->synchronized('run-1', function () use ($locks, $dispatcher, $first, $second, &$outcomes): void {
            $locks->afterRelease('run-1', static fn () => $dispatcher->dispatchEffects([$first, $second]));
            $this->assertSame([], $outcomes);
        });
        $this->assertCount(1, $outcomes);
        $this->assertSame('run-1', $outcomes[0]->runId);
        $this->assertSame(1, $outcomes[0]->accepted);
        $this->assertSame(1, $outcomes[0]->failed);
        $this->assertStringNotContainsString('sensitive transport detail', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }
}
