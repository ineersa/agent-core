<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Extension\ExtensionRecurringJobRegistry;
use Ineersa\CodingAgent\Extension\RefreshExtensionRecurringJobs;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobDTO;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Schedule;

final class ExtensionRecurringJobRegistryTest extends TestCase
{
    public function testImmediateEligibilityCadenceAndStableDescriptorReplacementLeaveNoOrphans(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $schedule = new Schedule();
        $handlers = new ExtensionAgentJobRegistry();
        $handler = $this->createStub(ExtensionAgentJobHandlerInterface::class);
        $handlers->register('first', $handler);
        $handlers->register('second', $handler);
        $registry = new ExtensionRecurringJobRegistry(static fn () => $schedule, $handlers, 'owner');
        $source = new class implements RecurringJobSourceInterface {
            /** @var list<RecurringJobDTO> */
            public array $definitions = [];

            public function jobs(string $runId): iterable
            {
                return $this->definitions;
            }
        };
        $registry->register('source', $source);
        $generator = new MessageGenerator($schedule, 'test', $clock);
        $due = static fn (): array => array_values(array_filter(iterator_to_array($generator->getMessages(), false), static fn (object $message): bool => $message instanceof RedispatchMessage));
        $this->assertSame([], $due());
        $clock->modify('+1 microsecond');
        $from = $clock->now();
        $until = $from->modify('+1 hour');
        $source->definitions = [new RecurringJobDTO('stable', 'first', ['value' => 1], '1 minute', $from, $until)];
        $registry->refresh(new RefreshExtensionRecurringJobs());
        $installed = $schedule->getRecurringMessages();
        $registry->refresh(new RefreshExtensionRecurringJobs());
        $this->assertSame($installed, $schedule->getRecurringMessages(), 'An unchanged refresh must preserve installed instances.');
        $initial = $due();
        $this->assertCount(1, $initial, 'New work is immediately eligible rather than delayed by its interval.');
        $this->assertSame(['value' => 1], $initial[0]->envelope->getMessage()->payload);
        $clock->modify('+59 seconds');
        $this->assertSame([], $due());
        $clock->modify('+1 second');
        $this->assertCount(1, $due(), 'The next occurrence follows the configured cadence.');
        $job = end($installed);
        $this->assertEquals($from->modify('+2 minutes'), $job->getTrigger()->getNextRunDate($clock->now()));

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
            $context = new MessageContext('test', $job->getId(), $job->getTrigger(), $clock->now());
            $callback = iterator_to_array($job->getMessages($context), false)[0]->envelope->getMessage();
            $this->assertSame($replacement->handlerId, $callback->handlerId);
            $this->assertSame($replacement->payload, $callback->payload);
            $this->assertEquals($replacement->from->modify('+5 minutes'), $job->getTrigger()->getNextRunDate($clock->now()));
            $this->assertNull($job->getTrigger()->getNextRunDate($replacement->until));
            $registry->refresh(new RefreshExtensionRecurringJobs());
            $this->assertSame($messages, $schedule->getRecurringMessages());
        }
        $source->definitions = [];
        $registry->refresh(new RefreshExtensionRecurringJobs());
        $this->assertCount(1, $schedule->getRecurringMessages());
        $clock->modify('+2 hours');
        $this->assertSame([], $due(), 'Removing a stable job removes the actual installed recurrence.');
    }
}
