<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Messenger;

use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Messenger\WorkerFailedEventSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class WorkerFailedEventSubscriberTest extends TestCase
{
    private const string RUN_ID = 'test-run-123';
    private const string RECEIVER_NAME = 'run_control';

    #[Test]
    public function skipsReplayCorruptionFailures(): void
    {
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->never())->method('compareAndSwap');
        $eventStore = $this->createMock(SequencedEventStoreInterface::class);
        $eventStore->expects($this->never())->method('appendWithNextSeq');

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, new NullLogger());
        $envelope = new Envelope($this->createStartRun());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent($envelope, new RunStateReplayException('duplicate sequence number(s): 345')));

        $this->assertTrue(true);
    }

    #[Test]
    public function writesFailedStateAndEventForExistingRun(): void
    {
        $existingState = new RunState(runId: self::RUN_ID, status: RunStatus::Running, version: 3, turnNo: 2, lastSeq: 5);
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->method('get')->willReturn($existingState);
        $runStore->expects($this->once())->method('compareAndSwap')->willReturn(true);

        $eventStore = $this->createMock(SequencedEventStoreInterface::class);
        $eventStore->expects($this->once())->method('appendWithNextSeq')->willReturn(new RunEvent(self::RUN_ID, 6, 2, 'agent_end', ['reason' => 'failed']));

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new Envelope($this->createStartRun()), new \RuntimeException('boom')));

        $this->assertTrue(true);
    }

    private function createFinalFailedEvent(Envelope $envelope, \Throwable $exception): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent($envelope, self::RECEIVER_NAME, $exception);
    }

    private function createStartRun(): StartRun
    {
        return new StartRun(self::RUN_ID, 0, 'step-1', 1, 'ik-test-123', new StartRunPayload(systemPrompt: 'test prompt'));
    }
}
