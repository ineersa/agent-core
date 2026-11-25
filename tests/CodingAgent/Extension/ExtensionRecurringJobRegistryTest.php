<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobMessage;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Extension\ExtensionRecurringJobRegistry;
use Ineersa\CodingAgent\Extension\RefreshExtensionRecurringJobs;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobDTO;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Schedule;

final class ExtensionRecurringJobRegistryTest extends IsolatedKernelTestCase
{
    public function testImmediateEligibilityCadenceAndStableDescriptorReplacementLeaveNoOrphans(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $schedule = new Schedule();
        $handlers = new ExtensionAgentJobRegistry();
        $handler = $this->createStub(ExtensionAgentJobHandlerInterface::class);
        $handlers->register('first', $handler);
        $handlers->register('second', $handler);
        $bus = new TestMessageBus();
        $registry = new ExtensionRecurringJobRegistry(static fn () => $schedule, $handlers, 'owner', $bus, $clock);
        $source = new class implements RecurringJobSourceInterface {
            /** @var list<RecurringJobDTO> */
            public array $definitions = [];

            public function jobs(string $runId): iterable
            {
                return $this->definitions;
            }
        };
        $this->assertNull($registry->sessionSchedules('owner'));
        $registry->register('scheduling', $source);
        $this->assertSame([], $registry->sessionSchedules('owner'));
        $generator = new MessageGenerator($schedule, 'test', $clock);
        $due = static function () use ($generator, $registry): array {
            $messages = [];
            foreach ($generator->getMessages() as $message) {
                if ($message instanceof RefreshExtensionRecurringJobs) {
                    // Handle before advancing the generator: Symfony checkpoints after the handler returns.
                    $registry->refresh($message);
                } elseif ($message instanceof RedispatchMessage) {
                    $messages[] = $message;
                }
            }

            return $messages;
        };
        $this->assertSame([], $due());
        $clock->modify('+500 milliseconds');
        $source->definitions = [new RecurringJobDTO('short', 'first', ['short' => true], '5 minutes', $clock->now(), $clock->now()->modify('+60 seconds'))];
        $this->assertSame('Every 5 minutes', $registry->sessionSchedules('owner')[0]->label);
        $this->assertNull($registry->sessionSchedules('owner')[0]->nextRunAt);
        $clock->modify('+500 milliseconds');
        $this->assertSame([], $due(), 'The scheduler does not generate the initial occurrence.');
        $this->assertCount(1, $bus->messages, 'The refresh dispatches immediately even if the lifetime is shorter than the interval.');
        $this->assertSame(['short' => true], $bus->messages[0]->getMessage()->payload);
        $clock->modify('+10 seconds');
        $this->assertSame([], $due(), 'Late observation must not require a synthetic trigger slot.');
        $this->assertCount(1, $bus->messages);
        $from = $clock->now();
        $until = $from->modify('+1 hour');
        $source->definitions = [new RecurringJobDTO('iso', 'first', [], 'PT1H', $from, $until->modify('+1 second'))];
        [$iso] = $registry->sessionSchedules('owner');
        $this->assertSame('Recurring schedule', $iso->label);
        $this->assertEquals($until, $iso->nextRunAt);
        $source->definitions = [new RecurringJobDTO('stable', 'first', ['value' => 1], '1 minute', $from, $until)];
        [$view] = $registry->sessionSchedules('owner');
        $this->assertSame(['id', 'label', 'recurring', 'nextRunAt'], array_keys(get_object_vars($view)));
        $this->assertSame('Every 1 minute', $view->label);
        $this->assertEquals($from->modify('+1 minute'), $view->nextRunAt);
        $clock->modify('+1 second');
        $this->assertSame([], $due());
        $this->assertCount(2, $bus->messages);
        $initial = $bus->messages[1];
        $this->assertInstanceOf(Envelope::class, $initial);
        $this->assertSame('agent.execution.bus', $initial->last(BusNameStamp::class)?->getBusName());
        $this->assertSame(['value' => 1], $initial->getMessage()->payload);
        $installed = $schedule->getRecurringMessages();
        $registry->refresh(new RefreshExtensionRecurringJobs());
        $this->assertSame($installed, $schedule->getRecurringMessages(), 'An unchanged refresh must preserve installed instances.');
        $this->assertCount(2, $bus->messages);
        $clock->modify('+58 seconds');
        $this->assertSame([], $due());
        $clock->modify('+1 second');
        $this->assertCount(1, $due(), 'The next occurrence follows the configured cadence.');
        $this->assertCount(2, $bus->messages);
        $job = end($installed);
        $this->assertEquals($from->modify('+2 minutes'), $job->getTrigger()->getNextRunDate($clock->now()));
        $this->assertEquals($from->modify('+2 minutes'), $registry->sessionSchedules('owner')[0]->nextRunAt);

        foreach ([
            new RecurringJobDTO('stable', 'first', ['value' => 1], '5 minutes', $from, $until),
            new RecurringJobDTO('stable', 'second', ['value' => 2], '5 minutes', $from, $until),
            new RecurringJobDTO('stable', 'second', ['value' => 2], '5 minutes', $from->modify('+1 second'), $until->modify('+1 hour')),
        ] as $replacement) {
            $previous = $job->getId();
            $source->definitions = [$replacement];
            $registry->refresh(new RefreshExtensionRecurringJobs());
            $messages = $schedule->getRecurringMessages();
            $this->assertCount(2, $messages, 'Only the refresh task and current descriptor may remain installed.');
            $job = end($messages);
            $this->assertNotSame($previous, $job->getId());
            $this->assertFalse(array_any($messages, static fn ($message): bool => $message->getId() === $previous));
            $dispatch = end($bus->messages);
            $this->assertInstanceOf(Envelope::class, $dispatch);
            $callback = $dispatch->getMessage();
            $this->assertSame($replacement->handlerId, $callback->handlerId);
            $this->assertSame($replacement->payload, $callback->payload);
            $this->assertEquals($replacement->from->modify('+5 minutes'), $job->getTrigger()->getNextRunDate($clock->now()));
            $dispatched = \count($bus->messages);
            $this->assertNull($job->getTrigger()->getNextRunDate($replacement->until));
            $registry->refresh(new RefreshExtensionRecurringJobs());
            $this->assertSame($messages, $schedule->getRecurringMessages());
            $this->assertCount($dispatched, $bus->messages);
        }
        $source->definitions = [];
        $this->assertSame([], $registry->sessionSchedules('owner'));
        $registry->refresh(new RefreshExtensionRecurringJobs());
        $this->assertCount(1, $schedule->getRecurringMessages());
        $clock->modify('+2 hours');
        $this->assertSame([], $due(), 'Removing a stable job removes the actual installed recurrence.');
        $this->assertCount(5, $bus->messages);
        $future = $clock->now()->modify('+10 seconds');
        $futureJob = new RecurringJobDTO('future', 'first', ['future' => true], '5 minutes', $future, $future->modify('+60 seconds'));
        $source->definitions = [
            $futureJob,
            new RecurringJobDTO('removed', 'first', [], '5 minutes', $future, $future->modify('+60 seconds')),
            new RecurringJobDTO('replaced', 'first', ['obsolete' => true], '5 minutes', $future, $future->modify('+60 seconds')),
            new RecurringJobDTO('expired', 'first', [], '5 minutes', $from, $until),
        ];
        $clock->modify('+1 second');
        $this->assertSame([], $due());
        $this->assertCount(5, $bus->messages, 'Future and expired descriptors must not dispatch at registration.');
        $this->assertCount(1, $schedule->getRecurringMessages());
        $source->definitions = [$futureJob, new RecurringJobDTO('replaced', 'second', [], '5 minutes', $future->modify('+5 seconds'), $future->modify('+60 seconds'))];
        $clock->modify('+11 seconds');
        $this->assertSame([], $due());
        $this->assertCount(6, $bus->messages, 'First observation after start dispatches only the still-eligible descriptor.');
        $this->assertSame(['future' => true], $bus->messages[5]->getMessage()->payload);
        $clock->modify('+58 seconds');
        $this->assertSame([], $due());
        $this->assertSame([], $registry->sessionSchedules('owner'));
        $this->assertCount(6, $bus->messages, 'The replacement expires before its first eligible refresh.');
        $this->assertCount(1, $schedule->getRecurringMessages());
    }

    public function testInitialOccurrenceUsesConfiguredToolTransport(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $schedule = new Schedule();
        $handlers = new ExtensionAgentJobRegistry();
        $handlers->register('first', $this->createStub(ExtensionAgentJobHandlerInterface::class));
        $container = self::getContainer();
        $registry = new ExtensionRecurringJobRegistry(static fn () => $schedule, $handlers, 'owner', $container->get('agent.command.bus'), $clock);
        $registry->register('source', new class($clock->now()) implements RecurringJobSourceInterface {
            public function __construct(private readonly \DateTimeImmutable $from)
            {
            }

            public function jobs(string $runId): iterable
            {
                yield new RecurringJobDTO('initial', 'first', ['initial' => true], '5 minutes', $this->from, $this->from->modify('+1 minute'));
            }
        });

        $registry->refresh(new RefreshExtensionRecurringJobs());
        $sent = $container->get('messenger.transport.tool')->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(ExtensionAgentJobMessage::class, $sent[0]->getMessage());
        $this->assertSame(['initial' => true], $sent[0]->getMessage()->payload);
        $this->assertSame('agent.execution.bus', $sent[0]->last(BusNameStamp::class)?->getBusName());
        $this->assertSame(['tool'], $sent[0]->last(TransportNamesStamp::class)?->getTransportNames());
    }
}
