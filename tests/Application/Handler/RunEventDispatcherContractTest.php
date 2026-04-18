<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\EventSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\RunEventDispatcher;
use Ineersa\AgentCore\Contract\Extension\EventSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;
use Ineersa\AgentCore\Domain\Event\Lifecycle\AgentStartEvent;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RunEventDispatcherContractTest extends TestCase
{
    public function testCoreLifecycleEventsAreDispatchedToSymfonyEventDispatcher(): void
    {
        $symfonyDispatcher = new EventDispatcher();
        $received = [];

        $symfonyDispatcher->addListener(CoreLifecycleEventType::AGENT_START, static function (RunEvent $event) use (&$received): void {
            $received[] = $event;
        });

        $dispatcher = new RunEventDispatcher(
            new EventSubscriberRegistry([]),
            $symfonyDispatcher,
        );

        $event = new AgentStartEvent('run-stage-01', 1, 0);
        $dispatcher->dispatch($event);

        self::assertCount(1, $received);
        self::assertSame($event, $received[0]);
    }

    public function testExtensionEventsCanBeAddedViaSubscribers(): void
    {
        $subscriber = new class implements EventSubscriberInterface {
            /** @var list<RunEvent> */
            public array $received = [];

            public static function subscribedEventTypes(): array
            {
                return ['ext_compaction_start'];
            }

            public function onEvent(RunEvent $event): void
            {
                $this->received[] = $event;
            }
        };

        $dispatcher = new RunEventDispatcher(
            new EventSubscriberRegistry([$subscriber]),
            new EventDispatcher(),
        );

        $event = RunEvent::extension(
            runId: 'run-stage-01',
            seq: 10,
            turnNo: 2,
            type: 'ext_compaction_start',
            payload: ['strategy' => 'summary'],
        );

        $dispatcher->dispatch($event);

        self::assertCount(1, $subscriber->received);
        self::assertSame('ext_compaction_start', $subscriber->received[0]->type);
    }
}
