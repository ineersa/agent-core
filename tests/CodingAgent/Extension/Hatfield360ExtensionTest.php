<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\Future;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\CommandConsumptionPolicyInterface;
use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\DTO\CommandConsumptionDecisionDTO;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Builder\AdvanceRunMessageBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\CodingAgent\Extension\Session\ExtensionSessionEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\DTO\BackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Session\DTO\ScheduleDTO;
use Ineersa\Hatfield\ExtensionApi\Session\Event\AssistantMessageCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputResolvedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionTitleChangedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnFailedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\SessionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class Hatfield360ExtensionTest extends TestCase
{
    private string $selection = '42';
    private ?int $children = 0;
    private ?int $jobs = 0;
    /** @var list<ScheduleDTO>|null */
    private ?array $schedules = null;
    private int $reads = 0;
    private ?\Closure $tick = null;
    private ?string $title = null;

    public function testLiveDuplicateRequestsRetainTheSecondOccurrenceAfterOneAnswer(): void
    {
        $extension = $this->extension();
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        $extension->onInputRequested(new InputRequestedEvent('42', 2, 'same', InputRequestTypeEnum::Question));
        $first = $this->attention($extension);
        $extension->onInputRequested(new InputRequestedEvent('42', 3, 'same', InputRequestTypeEnum::Approval));
        $extension->onInputResolved(new InputResolvedEvent('42', 4, 'same'));
        $second = $this->attention($extension);
        $this->assertSame('permission', $second['reason']);
        $this->assertNotSame($first['id'], $second['id']);
        $extension->onInputResolved(new InputResolvedEvent('42', 5, 'same'));
        $this->assertNull($this->attention($extension));
    }

    public function testLiveDuplicatesAndOverflowDoNotEraseUnknownOrChildAttention(): void
    {
        $extension = $this->extension();
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        for ($i = 0; $i < 2001; ++$i) {
            $extension->onInputRequested(new InputRequestedEvent('42', 2 + $i, 'same', InputRequestTypeEnum::Question));
        }
        for ($i = 0; $i < 2001; ++$i) {
            $extension->onInputResolved(new InputResolvedEvent('42', 3000 + $i, 'same'));
        }
        $this->assertNotNull($this->attention($extension), 'Overflow must not be resolved by an uncorrelated answer.');
        $extension->onInputRequested(new InputRequestedEvent('42', 1, 'child-question', InputRequestTypeEnum::Approval, 'child'));
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 6000));
        $this->assertSame('permission', $this->attention($extension)['reason']);
    }

    public function testCompletedTurnCarriesTheTailOfTheLastAssistantMessage(): void
    {
        $extension = $this->extension();
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 2, 'Looking at the files.'));
        $final = str_repeat('🦊', 600).str_repeat('b', 600);
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('other', 3, 'Another session'));
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 3, $final));
        $this->assertSame(['id' => '1', 'status' => 'working'], $this->snapshot($extension)['turn'], 'A working turn has no response.');
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 4));
        $response = '…'.mb_substr($final, -999, null, 'UTF-8');
        $this->assertSame(['id' => '1', 'status' => 'completed', 'response' => $response], $this->snapshot($extension)['turn']);
        $this->assertSame(1000, mb_strlen($response, 'UTF-8'));
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 5, 'Late message'));
        $this->poll($extension);
        $this->assertSame($response, $this->snapshot($extension)['turn']['response'], 'Settling snapshots resend the same response.');

        $extension->onTurnStarted(new TurnStartedEvent('42', 6, null));
        $this->assertSame(['id' => '6', 'status' => 'working'], $this->snapshot($extension)['turn']);
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 7, 'Short answer'));
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 8, ''));
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 9));
        $this->assertSame(['id' => '6', 'status' => 'completed'], $this->snapshot($extension)['turn'], 'A final tool-only message leaves no response.');

        $extension->onTurnStarted(new TurnStartedEvent('42', 10, null));
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 11, 'Partial answer'));
        $extension->onTurnFailed(new TurnFailedEvent('42', 12));
        $this->assertSame(['id' => '10', 'status' => 'failed'], $this->snapshot($extension)['turn']);
    }

    public function testTitleChangeResendsOnlyTheTitle(): void
    {
        $this->title = 'Old';
        $extension = $this->extension();
        $this->poll($extension);
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        $pending = (new \ReflectionProperty($extension, 'pending'));
        foreach (['working' => static function (): void {}, 'completed' => static function () use ($extension): void {
            $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 2, 'Done'));
            $extension->onTurnCompleted(new TurnCompletedEvent('42', 3));
        }] as $status => $advance) {
            $advance();
            $before = $pending->getValue($extension);
            $extension->onSessionTitleChanged(new SessionTitleChangedEvent('other', 'Not this one'));
            $this->assertSame($before, $pending->getValue($extension), 'Another session is ignored.');

            $extension->onSessionTitleChanged(new SessionTitleChangedEvent('42', 'Renamed while '.$status));
            $after = $pending->getValue($extension);
            $this->assertSame(array_column($before, 0), array_column($after, 0), 'A rename queues no notice.');
            [, , , $epoch, $fields] = $after[array_key_last($after)];
            $this->assertSame($before[array_key_last($before)][3], $epoch, 'A rename stays in the same epoch.');
            $this->assertSame(array_replace($before[array_key_last($before)][4], ['title' => 'Renamed while '.$status]), $fields);
            $this->assertSame($status, $fields['turn']['status']);
        }
    }

    public function testAstralResponseFitsWithoutEscaping(): void
    {
        $this->title = str_repeat('🦊', 200);
        $this->schedules = array_map(static fn (int $i): ScheduleDTO => new ScheduleDTO((string) $i, 'Every minute', true, null), range(1, 16));
        $extension = $this->extension();
        $this->poll($extension);
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        $extension->onAssistantMessageCompleted(new AssistantMessageCompletedEvent('42', 2, str_repeat('🦊', 1000)));
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 3));

        $this->assertSame(str_repeat('🦊', 1000), $this->snapshot($extension)['turn']['response']);
    }

    public function testCompletionRefreshesInventoryInEitherSettlementOrder(): void
    {
        $clock = new MockClock();
        $extension = $this->extension($clock);
        $this->children = 1;
        $this->jobs = 1;

        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        $extension->onInputRequested(new InputRequestedEvent('42', 2, 'question', InputRequestTypeEnum::Question));
        $this->assertSame(1, $this->reads, 'Ordinary events reuse the latest sample.');
        $this->poll($extension, 0, 1);
        $this->assertSame(2, $this->reads);
        $this->children = 1;
        $extension->onChildExecutionStarted(new ChildExecutionStartedEvent('42', 1, 'child'));
        $this->assertSame(1, $this->snapshot($extension)['activeSubagents']);
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 2));
        $this->assertSame(1, $this->snapshot($extension)['activeSubagents']);
        $completed = ['id' => '1', 'status' => 'completed'];
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $this->children = 0;
        $extension->onChildExecutionEnded(new ChildExecutionEndedEvent('42', 1, 'child'));
        $this->assertNull($this->snapshot($extension)['activeSubagents']);
        $this->assertSame(1, $this->snapshot($extension)['backgroundScripts']);
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $clock->modify('+4 seconds');
        $this->jobs = 0;
        $extension->onBackgroundJob(new BackgroundJobEndedEvent('42', '42', 'bash:1'));
        $this->assertSame(0, $this->snapshot($extension)['backgroundScripts']);
        $this->poll($extension, 0, 0);
        $this->assertNull($this->snapshot($extension)['activeSubagents']);
        $clock->modify('+1 second');
        $this->poll($extension, 0, 0);
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame($completed, $this->snapshot($extension)['turn'], 'Script changes neither restart the delay nor mint another completion identity.');

        $this->children = 1;
        $extension->onTurnStarted(new TurnStartedEvent('42', 3, null));
        $this->children = 0;
        $extension->onChildExecutionEnded(new ChildExecutionEndedEvent('42', 2, 'child'));
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame('working', $this->snapshot($extension)['turn']['status']);
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 4));
        $this->assertSame(['id' => '3', 'status' => 'completed'], $this->snapshot($extension)['turn']);
        $this->assertNull($this->snapshot($extension)['activeSubagents']);
        $clock->modify('+5 seconds');
        $this->poll($extension, 0, 0);
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
    }

    public function testPollsAreThrottledToTheIdleSampleInterval(): void
    {
        $clock = new MockClock();
        $this->extension($clock);
        $tick = $this->tick;
        $this->assertNotNull($tick);
        $tick();
        $tick();
        $this->assertSame(1, $this->reads);
        $clock->modify('+499 milliseconds');
        $tick();
        $this->assertSame(1, $this->reads);
        $clock->modify('+1 millisecond');
        $tick();
        $this->assertSame(2, $this->reads);
    }

    public function testSelectionMismatchCannotSettleOldCompletion(): void
    {
        $clock = new MockClock();
        $extension = $this->extension($clock);
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 2));
        $incomplete = $this->snapshot($extension);
        $this->assertNull($incomplete['activeSubagents']);
        $this->selection = 'other';
        $clock->modify('+5 seconds');
        $this->poll($extension, 0, 1);
        $this->assertSame($incomplete, $this->snapshot($extension));
        $this->selection = '42';
        $extension->onTurnStarted(new TurnStartedEvent('42', 3, null));
        $extension->onTurnFailed(new TurnFailedEvent('42', 4));
        $this->assertSame(['id' => '3', 'status' => 'failed'], $this->snapshot($extension)['turn']);
    }

    public function testScheduleSnapshotsAndAppliedTurnTrigger(): void
    {
        $extension = $this->extension();
        $this->poll($extension);
        $this->assertArrayNotHasKey('schedules', $this->snapshot($extension));
        $next = new \DateTimeImmutable('2026-01-01T00:00:00.123Z');
        $this->schedules = [new ScheduleDTO('id', 'Every minute', true, $next)];
        $this->poll($extension);
        $this->assertSame([['id' => 'id', 'label' => 'Every minute', 'recurring' => true, 'nextRunAt' => 1767225600123]], $this->snapshot($extension)['schedules']);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($extension);
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec($serializer), $serializer);
        $observe = static function (RunEvent $event) use ($translator, $dispatcher): void {
            $runtime = $translator->translate($event);
            self::assertNotNull($runtime);
            $mapped = ExtensionSessionEventMapper::map($runtime);
            self::assertNotNull($mapped);
            $dispatcher->dispatch($mapped);
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
                ));
            }
            $handler = new AdvanceRunHandler(new InMemoryDeferredToolCompletionRepository(), new CommandMailboxPolicy($store, [$policy]), new EventFactory(), tracer: new RunTracer(new NullLogger()), preLlmCompactionGuard: $this->createStub(PreLlmCompactionGuardInterface::class));
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
                $this->schedules = [new ScheduleDTO('id', 'Every minute', true, $next->modify('+1 minute'))];
                $this->poll($extension);
                $this->assertSame([['id' => 'id', 'label' => 'Every minute', 'recurring' => true, 'nextRunAt' => 1767225660123]], $this->snapshot($extension)['schedules']);
            }
        }

        $this->schedules = [];
        $this->poll($extension);
        $this->assertArrayNotHasKey('schedules', $this->snapshot($extension));
        $this->schedules = array_map(static fn (int $i): ScheduleDTO => new ScheduleDTO((string) $i, 'Every minute', true, null), range(1, 17));
        $this->poll($extension);
        $this->assertArrayNotHasKey('schedules', $this->snapshot($extension));
    }

    public function testExpiryRechecksInventoryAndDiscardsSupersededResponse(): void
    {
        $clock = new MockClock();
        $extension = $this->extension($clock);
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, null));
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 2));
        $clock->modify('+5 seconds');
        $this->poll($extension, null, 0);
        $this->assertNull($this->snapshot($extension)['activeSubagents'], 'Unknown inventory invalidates the pending completion.');

        $this->poll($extension, 0, 0);
        $clock->modify('+4 seconds');
        $extension->onChildExecutionStarted(new ChildExecutionStartedEvent('42', 1, 'child'));
        $clock->modify('+1 second');
        $this->poll($extension, 0, 0);
        $this->assertNull($this->snapshot($extension)['activeSubagents'], 'A child opening invalidates the old deadline even when inventory still reports zero.');

        $clock->modify('+3 seconds');
        $extension->onInputRequested(new InputRequestedEvent('42', 2, 'child-question', InputRequestTypeEnum::Approval, 'child'));
        $extension->onInputResolved(new InputResolvedEvent('42', 3, 'child-question', 'child'));
        $clock->modify('+1 second');
        $this->poll($extension, 0, 0);
        $this->assertNull($this->snapshot($extension)['activeSubagents'], 'Even transient attention restarts the delay.');

        $extension->onTurnStarted(new TurnStartedEvent('42', 3, null));
        $clock->modify('+4 seconds');
        $this->poll($extension, 0, 0);
        $this->assertSame(['id' => '3', 'status' => 'working'], $this->snapshot($extension)['turn'], 'A later idle sample cannot resurrect the superseded response.');
    }

    public function testInvalidationAfterReleaseRequiresAnotherQuietWindow(): void
    {
        $clock = new MockClock();
        $extension = $this->extension($clock);
        $extension->onTurnStarted(new TurnStartedEvent('42', 1, 'schedule'));
        $extension->onTurnCompleted(new TurnCompletedEvent('42', 2));
        $completed = ['id' => '1', 'status' => 'completed', 'trigger' => 'schedule'];
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $clock->modify('+5 seconds');
        $this->poll($extension, 0, 0);
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);

        $assertNewWindow = function (string $reason) use ($extension, $clock, $completed): void {
            $this->poll($extension, 0, 0);
            $this->assertNull($this->snapshot($extension)['activeSubagents'], $reason);
            $clock->modify('+4 seconds');
            $this->poll($extension, 0, 0);
            $this->assertNull($this->snapshot($extension)['activeSubagents']);
            $clock->modify('+1 second');
            $this->poll($extension, 0, 0);
            $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
            $this->assertSame($completed, $this->snapshot($extension)['turn']);
        };

        $extension->onChildExecutionStarted(new ChildExecutionStartedEvent('42', 1, 'child'));
        $assertNewWindow('Child start invalidates release even if refreshed inventory still says zero.');
        $this->poll($extension, null, 0);
        $assertNewWindow('Recovering from unknown inventory requires reconfirmation.');
        $extension->onInputRequested(new InputRequestedEvent('42', 2, 'request', InputRequestTypeEnum::Approval, 'child'));
        $extension->onInputResolved(new InputResolvedEvent('42', 3, 'request', 'child'));
        $assertNewWindow('Resolving transient child attention requires reconfirmation.');
        $this->selection = 'other';
        $this->poll($extension, 0, 0);
        $this->selection = '42';
        $assertNewWindow('A session context mismatch invalidates release.');
    }

    private function extension(?MockClock $clock = null): Hatfield360Extension
    {
        $extension = new Hatfield360Extension($clock ?? new MockClock());
        $session = $this->createStub(SessionContextInterface::class);
        $session->method('getSessionId')->willReturnCallback(fn (): string => $this->selection);
        $session->method('getSessionDisplayTitle')->willReturnCallback(fn (): ?string => $this->title);
        $session->method('getBackgroundSnapshot')->willReturnCallback(function (): BackgroundSnapshotDTO {
            ++$this->reads;

            return new BackgroundSnapshotDTO($this->selection, $this->children, $this->jobs, $this->schedules);
        });
        $api = $this->createStub(ExtensionApiInterface::class);
        $api->method('session')->willReturn($session);
        $ui = $this->createStub(TuiExtensionContextInterface::class);
        $ui->method('onTick')->willReturnCallback(function (\Closure $listener): void {
            $this->tick = $listener;
        });
        $extension->register($api);
        $extension->registerTui($ui);
        (new \ReflectionProperty($extension, 'sessionId'))->setValue($extension, '42');
        // Hold the outbound queue locally: exercise real live events without
        // registration, credentials, sockets, or an asynchronous delivery race.
        (new \ReflectionProperty($extension, 'drain'))->setValue($extension, Future::complete());

        return $extension;
    }

    /** One idle-tick poll; resets the throttle covered by testPollsAreThrottledToTheIdleSampleInterval. */
    private function poll(Hatfield360Extension $extension, ?int $children = 0, ?int $jobs = 0): void
    {
        $this->children = $children;
        $this->jobs = $jobs;
        (new \ReflectionProperty($extension, 'pollAt'))->setValue($extension, null);
        $tick = $this->tick;
        $this->assertNotNull($tick);
        $tick();
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
