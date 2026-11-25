<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\Future;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\CommandConsumptionPolicyInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\DTO\CommandConsumptionDecisionDTO;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Builder\AdvanceRunMessageBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundJobObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiScheduleDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundJobObservationTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum as Observation;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionActivityContextInterface;
use Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension;
use Ineersa\Tui\Runtime\TuiRunObservationMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class Hatfield360ExtensionTest extends TestCase
{
    public function testLiveDuplicateRequestsRetainTheSecondOccurrenceAfterOneAnswer(): void
    {
        $extension = $this->extension();
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::QuestionRequested, 'same'));
        $first = $this->attention($extension);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::ApprovalRequested, 'same'));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 4, Observation::InputResolved, 'same'));
        $second = $this->attention($extension);
        $this->assertSame('permission', $second['reason']);
        $this->assertNotSame($first['id'], $second['id']);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 5, Observation::InputResolved, 'same'));
        $this->assertNull($this->attention($extension));
    }

    public function testLiveDuplicatesAndOverflowDoNotEraseUnknownOrChildAttention(): void
    {
        $extension = $this->extension();
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        for ($i = 0; $i < 2001; ++$i) {
            $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2 + $i, Observation::QuestionRequested, 'same'));
        }
        for ($i = 0; $i < 2001; ++$i) {
            $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3000 + $i, Observation::InputResolved, 'same'));
        }
        $this->assertNotNull($this->attention($extension), 'Overflow must not be resolved by an uncorrelated answer.');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ApprovalRequested, 'child-question', 'child'));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 6000, Observation::TurnCompleted));
        $this->assertSame('permission', $this->attention($extension)['reason']);
    }

    public function testCompletionRefreshesInventoryInEitherSettlementOrder(): void
    {
        $clock = new MockClock();
        $extension = $this->extension($clock);
        $context = $this->createStub(TuiSessionActivityContextInterface::class);
        $context->method('getSessionId')->willReturn('42');
        $active = true;
        $children = 1;
        $jobs = 1;
        $reads = [];
        $context->method('isMainActive')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });
        $context->method('getBackgroundSnapshot')->willReturnCallback(static function (bool $refresh) use (&$children, &$jobs, &$reads): TuiBackgroundSnapshotDTO {
            $reads[] = $refresh;

            return new TuiBackgroundSnapshotDTO('42', $children, $jobs);
        });
        $extension->registerTui($context);

        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::QuestionRequested, 'question'));
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 1));
        $this->assertSame([false, false], $reads, 'Ordinary observations use the host sample; poll callbacks already have it.');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionStarted, childRunId: 'child'));
        $this->assertSame(1, $this->snapshot($extension)['activeSubagents']);
        $active = false;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::TurnCompleted));
        $this->assertSame(1, $this->snapshot($extension)['activeSubagents']);
        $completed = ['id' => '1', 'status' => 'completed'];
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $children = 0;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionEnded, childRunId: 'child'));
        $this->assertNull($this->snapshot($extension)['activeSubagents']);
        $this->assertSame(1, $this->snapshot($extension)['backgroundScripts']);
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $clock->modify('+4 seconds');
        $jobs = 0;
        $extension->onTuiBackgroundJobObserved(new TuiBackgroundJobObservationDTO('42', '42', 'bash:1', TuiBackgroundJobObservationTypeEnum::Ended));
        $this->assertSame(0, $this->snapshot($extension)['backgroundScripts']);
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0));
        $this->assertNull($this->snapshot($extension)['activeSubagents']);
        $clock->modify('+1 second');
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0));
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame($completed, $this->snapshot($extension)['turn'], 'Script changes neither restart the delay nor mint another completion identity.');

        $children = 1;
        $active = true;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::TurnStarted));
        $children = 0;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::ChildExecutionEnded, childRunId: 'child'));
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame('working', $this->snapshot($extension)['turn']['status']);
        $active = false;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 4, Observation::TurnCompleted));
        $this->assertSame(['id' => '3', 'status' => 'completed'], $this->snapshot($extension)['turn']);
        $this->assertNull($this->snapshot($extension)['activeSubagents']);
        $clock->modify('+5 seconds');
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0));
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame([false, false, true, true, true, true, true, false, true, true, true], $reads);
    }

    public function testUnknownStateAndMainReactivationCannotSettleOldCompletion(): void
    {
        $extension = $this->extension();
        $context = $this->createStub(TuiSessionActivityContextInterface::class);
        $selection = '42';
        $active = null;
        $context->method('getSessionId')->willReturnCallback(static function () use (&$selection): string {
            return $selection;
        });
        $context->method('isMainActive')->willReturnCallback(static function () use (&$active): ?bool {
            return $active;
        });
        $context->method('getBackgroundSnapshot')->willReturn(new TuiBackgroundSnapshotDTO('42', 0, 0));
        $extension->registerTui($context);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::TurnCompleted));
        $incomplete = $this->snapshot($extension);
        $this->assertNull($incomplete['activeSubagents']);
        $selection = 'other';
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 1));
        $this->assertSame($incomplete, $this->snapshot($extension));
        $selection = '42';
        $active = true; // The TUI accepted new input before its canonical opening event arrived.
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionEnded, childRunId: 'child'));
        $this->assertNull($this->snapshot($extension)['turn']);
        $active = false;
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 1));
        $this->assertNull($this->snapshot($extension)['turn']);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 4, Observation::TurnFailed));
        $this->assertSame(['id' => '3', 'status' => 'failed'], $this->snapshot($extension)['turn']);
    }

    public function testScheduleSnapshotsAndAppliedTurnTrigger(): void
    {
        $extension = $this->extension();
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0));
        $this->assertArrayNotHasKey('schedules', $this->snapshot($extension));
        $next = new \DateTimeImmutable('2026-01-01T00:00:00.123Z');
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0, [new TuiScheduleDTO('id', 'Every minute', true, $next)]));
        $this->assertSame([['id' => 'id', 'label' => 'Every minute', 'recurring' => true, 'nextRunAt' => 1767225600123]], $this->snapshot($extension)['schedules']);

        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec($serializer), $serializer);
        $observe = static function (RunEvent $event) use ($translator, $extension): void {
            $runtime = $translator->translate($event);
            self::assertNotNull($runtime);
            $observation = TuiRunObservationMapper::map($runtime);
            self::assertNotNull($observation);
            $extension->onTuiRunObserved($observation);
        };
        $seq = 1;
        foreach ([['schedule:job'], ['user'], ['child-result'], ['schedule:job', 'user']] as $identities) {
            $store = new InMemoryCommandStore();
            $policy = $this->createStub(CommandConsumptionPolicyInterface::class);
            $policy->method('prepare')->willReturnCallback(static fn (RunState $state, PendingCommand $command): ?CommandConsumptionDecisionDTO => 'user' === $command->idempotencyKey ? null : new CommandConsumptionDecisionDTO(metadata: ['background_completion' => ['identity' => $command->idempotencyKey]]));
            foreach ($identities as $identity) {
                $store->enqueue(new PendingCommand(
                    runId: '42',
                    kind: 'user' === $identity ? CoreCommandKind::FollowUp : CoreCommandKind::AppendMessage,
                    idempotencyKey: $identity,
                    payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Input']]]],
                    options: new CommandCancellationOptions(safe: false),
                ));
            }
            $handler = new AdvanceRunHandler(new InMemoryDeferredToolCompletionRepository(), new CommandMailboxPolicy($store, new CommandRouter([]), [$policy]), new EventFactory());
            $result = $handler->handle(AdvanceRunMessageBuilder::create('42')->withTurnNo(1)->build(), new RunState('42', RunStatus::Completed, turnNo: 1, lastSeq: $seq));
            $starts = array_values(array_filter($result->events, static fn (RunEvent $event): bool => RunEventTypeEnum::TurnAdvanced->value === $event->type));
            $this->assertCount(1, $starts);
            $start = $starts[0];
            $observe($start);
            $trigger = ['schedule:job'] === $identities ? ['trigger' => 'schedule'] : [];
            $this->assertSame(['id' => (string) $start->seq, 'status' => 'working'] + $trigger, $this->snapshot($extension)['turn']);
            $seq = $start->seq + 1;
            $observe(new RunEvent('42', $seq, $start->turnNo, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']));
            $this->assertSame(['id' => (string) $start->seq, 'status' => 'completed'] + $trigger, $this->snapshot($extension)['turn']);
            if ([] !== $trigger) {
                $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0, [new TuiScheduleDTO('id', 'Every minute', true, $next->modify('+1 minute'))]));
                $this->assertSame([['id' => 'id', 'label' => 'Every minute', 'recurring' => true, 'nextRunAt' => 1767225660123]], $this->snapshot($extension)['schedules']);
            }
        }

        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0, []));
        $this->assertArrayNotHasKey('schedules', $this->snapshot($extension));
        $overflow = array_map(static fn (int $i): TuiScheduleDTO => new TuiScheduleDTO((string) $i, 'Every minute', true, null), range(1, 17));
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 0, $overflow));
        $this->assertArrayNotHasKey('schedules', $this->snapshot($extension));
    }

    public function testExpiryRechecksInventoryAndDiscardsSupersededResponse(): void
    {
        $clock = new MockClock();
        $extension = $this->extension($clock);
        $context = $this->createStub(TuiSessionActivityContextInterface::class);
        $context->method('getSessionId')->willReturn('42');
        $active = false;
        $children = 0;
        $context->method('isMainActive')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });
        $context->method('getBackgroundSnapshot')->willReturnCallback(static function () use (&$children): TuiBackgroundSnapshotDTO {
            return new TuiBackgroundSnapshotDTO('42', $children, 0);
        });
        $extension->registerTui($context);
        $zero = new TuiBackgroundSnapshotDTO('42', 0, 0);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::TurnCompleted));
        $children = null;
        $clock->modify('+5 seconds');
        $extension->onTuiBackgroundSnapshot($zero);
        $this->assertNull($this->snapshot($extension)['activeSubagents'], 'Unknown inventory invalidates the pending completion, even if the poll reported zero.');

        $children = 0;
        $extension->onTuiBackgroundSnapshot($zero);
        $clock->modify('+4 seconds');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionStarted, childRunId: 'child'));
        $clock->modify('+1 second');
        $extension->onTuiBackgroundSnapshot($zero);
        $this->assertNull($this->snapshot($extension)['activeSubagents'], 'A child opening invalidates the old deadline even when inventory still reports zero.');

        $clock->modify('+3 seconds');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::ApprovalRequested, 'child-question', 'child'));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::InputResolved, 'child-question', 'child'));
        $clock->modify('+1 second');
        $extension->onTuiBackgroundSnapshot($zero);
        $this->assertNull($this->snapshot($extension)['activeSubagents'], 'Even transient attention restarts the delay.');

        // The handoff activates main before the new canonical turn begins.
        $active = true;
        $clock->modify('+4 seconds');
        $extension->onTuiBackgroundSnapshot($zero);
        $this->assertNull($this->snapshot($extension)['turn']);
        $active = false;
        $extension->onTuiBackgroundSnapshot($zero);
        $this->assertNull($this->snapshot($extension)['turn'], 'A later idle sample cannot resurrect the superseded response.');
    }

    public function testInvalidationAfterReleaseRequiresAnotherQuietWindow(): void
    {
        $clock = new MockClock();
        $extension = $this->extension($clock);
        $zero = new TuiBackgroundSnapshotDTO('42', 0, 0);
        $selection = '42';
        $context = $this->createStub(TuiSessionActivityContextInterface::class);
        $context->method('getSessionId')->willReturnCallback(static function () use (&$selection): string {
            return $selection;
        });
        $context->method('isMainActive')->willReturn(false);
        $context->method('getBackgroundSnapshot')->willReturn($zero);
        $extension->registerTui($context);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted, trigger: 'schedule'));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::TurnCompleted));
        $completed = ['id' => '1', 'status' => 'completed', 'trigger' => 'schedule'];
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $clock->modify('+5 seconds');
        $extension->onTuiBackgroundSnapshot($zero);
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);

        $assertNewWindow = function (string $reason) use ($extension, $clock, $zero, $completed): void {
            $extension->onTuiBackgroundSnapshot($zero);
            $this->assertNull($this->snapshot($extension)['activeSubagents'], $reason);
            $clock->modify('+4 seconds');
            $extension->onTuiBackgroundSnapshot($zero);
            $this->assertNull($this->snapshot($extension)['activeSubagents']);
            $clock->modify('+1 second');
            $extension->onTuiBackgroundSnapshot($zero);
            $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
            $this->assertSame($completed, $this->snapshot($extension)['turn']);
        };

        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionStarted, childRunId: 'child'));
        $assertNewWindow('Child start invalidates release even if refreshed inventory still says zero.');
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', null, 0));
        $assertNewWindow('Recovering from unknown inventory requires reconfirmation.');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::ApprovalRequested, 'request', 'child'));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::InputResolved, 'request', 'child'));
        $assertNewWindow('Resolving transient child attention requires reconfirmation.');
        $selection = 'other';
        $extension->onTuiBackgroundSnapshot($zero);
        $selection = '42';
        $assertNewWindow('A session context mismatch invalidates release.');
    }

    private function extension(?MockClock $clock = null): Hatfield360Extension
    {
        $extension = new Hatfield360Extension($clock ?? new MockClock());
        (new \ReflectionProperty($extension, 'sessionId'))->setValue($extension, '42');
        // Hold the outbound queue locally: exercise real live observations
        // without registration, credentials, sockets, or an asynchronous delivery race.
        (new \ReflectionProperty($extension, 'drain'))->setValue($extension, Future::complete());

        return $extension;
    }

    /** @return array{id: string, reason: string}|null */
    private function attention(Hatfield360Extension $extension): ?array
    {
        return $this->snapshot($extension)['attention'];
    }

    /** @return array<string, mixed> */
    private function snapshot(Hatfield360Extension $extension): array
    {
        $pending = (new \ReflectionProperty($extension, 'pending'))->getValue($extension);

        return $pending[array_key_last($pending)][4];
    }
}
