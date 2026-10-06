<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\ContextBudget;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ContextBudgetReminderConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\ContextBudget\ContextBudgetReminderHookSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: a committed llm_step_completed with provider usage queues exactly one
 * user append_message wrapped in <system-reminder>, with one-shot + compaction
 * reset derived only from generic agent_command_* events.
 *
 * @covers \Ineersa\CodingAgent\ContextBudget\ContextBudgetReminderHookSubscriber
 */
#[AllowMockObjectsWithoutExpectations]
final class ContextBudgetReminderHookSubscriberTest extends TestCase
{
    /** @var EventStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventStore;
    /** @var AgentRunnerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $agentRunner;
    private ContextBudgetReminderHookSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->agentRunner = $this->createMock(AgentRunnerInterface::class);

        $this->subscriber = new ContextBudgetReminderHookSubscriber(
            $this->eventStore,
            new ContextBudgetReminderConfig(
                earlyInputTokens: 200000,
                urgentRemainingTokens: 25000,
            ),
            $this->appConfigWithCatalogWindow(272000),
        );
    }

    public function testEarlyQueuesWrappedAppendMessage(): void
    {
        $this->mockEvents([
            $this->runStarted(1, 272000),
        ]);

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                new Callback(static function (AgentMessage $message): bool {
                    return 'user' === $message->role
                        && true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::EARLY_TEXT,
                        );
                }),
            );

        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 200000],
            ]),
        ]));
    }

    public function testUrgentWhenBothEligibleQueuesUrgentOnly(): void
    {
        $this->mockEvents([
            $this->runStarted(1, 272000),
        ]);

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                new Callback(static function (AgentMessage $message): bool {
                    return true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::URGENT_TEXT,
                        );
                }),
            );

        // remaining = 272000 - 250000 = 22000 < 25000 and input >= 200000
        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['prompt_tokens' => 250000],
            ]),
        ]));
    }

    public function testOneShotEarlyThenUrgent(): void
    {
        $earlyWrapped = ContextBudgetReminderHookSubscriber::wrapSystemReminder(
            ContextBudgetReminderHookSubscriber::EARLY_TEXT,
        );

        $this->mockEvents([
            $this->runStarted(1, 272000),
            $this->commandQueued(3, $earlyWrapped),
        ]);

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                new Callback(static function (AgentMessage $message): bool {
                    return true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::URGENT_TEXT,
                        );
                }),
            );

        // Early already queued; remaining now urgent.
        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(4, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 250000],
            ]),
        ]));
    }

    public function testCompactionResetsEpisodeAndRequiresFreshUsage(): void
    {
        $earlyWrapped = ContextBudgetReminderHookSubscriber::wrapSystemReminder(
            ContextBudgetReminderHookSubscriber::EARLY_TEXT,
        );

        // Pre-compaction early was issued; after context_compacted it no longer counts.
        // But no post-compaction llm completion in historical store — the hot batch
        // completion is the fresh usage that re-enables early.
        $this->mockEvents([
            $this->runStarted(1, 272000),
            $this->commandApplied(2, $earlyWrapped),
            $this->event(3, RunEventTypeEnum::ContextCompacted->value, []),
        ]);

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                new Callback(static function (AgentMessage $message): bool {
                    return true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::EARLY_TEXT,
                        );
                }),
            );

        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(4, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 210000],
            ]),
        ]));
    }

    public function testBelowBothThresholdsSkipsReminderHistoryScan(): void
    {
        $this->eventStore->method('firstFor')->willReturn($this->runStarted(1, 272000));
        $this->eventStore->expects($this->never())->method('reverseFor');
        $this->agentRunner->expects($this->never())->method('appendMessage');

        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 100000],
            ]),
        ]));
    }

    public function testMissingUsageOrWindowDoesNotQueue(): void
    {
        $this->mockEvents([
            $this->runStarted(1, null),
        ]);

        $this->agentRunner->expects($this->never())->method('appendMessage');

        // No positive usage
        $committedState = $this->runState(model: null);

        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 0],
            ]),
        ], $committedState));

        // Missing window (run_started has none, catalog empty for null model)
        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(3, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 210000],
            ]),
        ], $committedState));
    }

    public function testUnrelatedOrAbortedEventsDoNotQueue(): void
    {
        $this->mockEvents([
            $this->runStarted(1, 272000),
        ]);
        $this->agentRunner->expects($this->never())->method('appendMessage');

        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepAborted->value, [
                'usage' => ['input_tokens' => 250000],
            ]),
        ]));

        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(3, RunEventTypeEnum::ToolBatchCommitted->value, []),
        ]));
    }

    public function testUsesCommittedContextModelWhenRunStartedHasNoWindow(): void
    {
        $this->mockEvents([
            $this->runStarted(1, null),
        ]);
        $this->agentRunner->expects($this->once())->method('appendMessage');

        $this->dispatchPrepared($this->subscriber, $this->hookContext(
            [
                $this->summary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                    'usage' => ['input_tokens' => 200000],
                ]),
            ],
            $this->runState(),
        ));
    }

    public function testUrgentAlreadyQueuedSuppressesLaterReminders(): void
    {
        $urgentWrapped = ContextBudgetReminderHookSubscriber::wrapSystemReminder(
            ContextBudgetReminderHookSubscriber::URGENT_TEXT,
        );

        $this->mockEvents([
            $this->runStarted(1, 272000),
            $this->commandQueued(2, $urgentWrapped),
        ]);
        $this->agentRunner->expects($this->never())->method('appendMessage');

        $this->dispatchPrepared($this->subscriber, $this->hookContext([
            $this->summary(3, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 260000],
            ]),
        ]));
    }

    /** @param array<string, mixed> $session */
    #[DataProvider('childReminderCases')]
    public function testChildReminderSettings(array $session, bool $disableForks, bool $disableSubagents, int $inputTokens, bool $suppressed): void
    {
        $subscriber = new ContextBudgetReminderHookSubscriber(
            $this->eventStore,
            new ContextBudgetReminderConfig(disableForForks: $disableForks, disableForSubagents: $disableSubagents),
            $this->appConfigWithCatalogWindow(272000),
        );
        $this->mockEvents([$this->runStarted(1, 272000, $session)]);
        $expectation = $this->agentRunner->expects($suppressed ? $this->never() : $this->once())
            ->method('appendMessage');
        if (!$suppressed) {
            $text = 200000 === $inputTokens
                ? ContextBudgetReminderHookSubscriber::EARLY_TEXT
                : ContextBudgetReminderHookSubscriber::URGENT_TEXT;
            $expectation->with('run-1', new Callback(static fn (AgentMessage $message): bool => ContextBudgetReminderHookSubscriber::wrapSystemReminder($text) === ($message->content[0]['text'] ?? null)));
        }

        $this->dispatchPrepared($subscriber, $this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => $inputTokens],
            ]),
        ]));
    }

    /** @return iterable<string, array{array<string, mixed>, bool, bool, int, bool}> */
    public static function childReminderCases(): iterable
    {
        $fork = ['kind' => 'agent_child', 'child_kind' => 'fork'];
        $subagent = ['kind' => 'agent_child'];
        foreach (['early' => 200000, 'urgent' => 260000] as $level => $tokens) {
            yield 'fork defaults '.$level => [$fork, false, false, $tokens, false];
            yield 'subagent defaults '.$level => [$subagent, false, false, $tokens, false];
            yield 'fork disabled '.$level => [$fork, true, false, $tokens, true];
            yield 'subagent disabled '.$level => [$subagent, false, true, $tokens, true];
            yield 'fork ignores subagent flag '.$level => [$fork, false, true, $tokens, false];
            yield 'subagent ignores fork flag '.$level => [$subagent, true, false, $tokens, false];
            yield 'parent ignores both flags '.$level => [[], true, true, $tokens, false];
        }
    }

    private function dispatchPrepared(ContextBudgetReminderHookSubscriber $subscriber, AfterTurnCommitHookContext $context): void
    {
        foreach ($subscriber->prepareAfterTurnCommit($context, $context->runState->lastSeq) as $action) {
            $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO::class, $action);
            $data = $action->message->payload['message'];
            $this->agentRunner->appendMessage($context->runId, new AgentMessage(role: $data['role'], content: $data['content'], metadata: $data['metadata']));
        }
    }

    /** @param list<RunEvent> $events */
    private function mockEvents(array $events): void
    {
        $this->eventStore->method('firstFor')->willReturn($events[0] ?? null);
        $this->eventStore->method('reverseFor')->willReturn(array_reverse($events));
    }

    /**
     * @param list<AfterTurnCommitEventSummary> $events
     */
    private function hookContext(array $events, ?RunState $runState = null): AfterTurnCommitHookContext
    {
        return new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 1,
            status: RunStatus::Running->value,
            events: $events,
            effectsCount: 0,
            runState: $runState ?? $this->runState(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summary(int $seq, string $type, array $payload): AfterTurnCommitEventSummary
    {
        return new AfterTurnCommitEventSummary(
            seq: $seq,
            type: $type,
            payload: $payload,
            turnNo: 1,
            createdAt: '2026-07-28T00:00:00+00:00',
        );
    }

    /** @param array<string, mixed> $session */
    private function runStarted(int $seq, ?int $contextWindow, array $session = []): RunEvent
    {
        $metadata = ['session' => $session];
        if (null !== $contextWindow) {
            $metadata['context_window'] = $contextWindow;
        }

        return $this->event($seq, RunEventTypeEnum::RunStarted->value, [
            'payload' => [
                'metadata' => $metadata,
            ],
        ]);
    }

    private function commandQueued(int $seq, string $text): RunEvent
    {
        return $this->event($seq, RunEventTypeEnum::AgentCommandQueued->value, [
            'kind' => 'append_message',
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
        ]);
    }

    private function commandApplied(int $seq, string $text): RunEvent
    {
        return $this->event($seq, RunEventTypeEnum::AgentCommandApplied->value, [
            'kind' => 'append_message',
            'text' => $text,
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(int $seq, string $type, array $payload): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: 1,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable('2026-07-28T00:00:00+00:00'),
        );
    }

    private function runState(?string $model = 'test/model'): RunState
    {
        return new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 1,
            model: $model,
        );
    }

    private function appConfigWithCatalogWindow(int $window): AppConfig
    {
        $ai = new AiConfig(
            defaultModel: 'test/model',
            providers: [
                'test' => new AiProviderConfig(
                    id: 'test',
                    models: [
                        'model' => new AiModelDefinition(
                            id: 'model',
                            contextWindow: $window,
                        ),
                    ],
                ),
            ],
        );

        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            ai: $ai,
            catalog: new HatfieldModelCatalog($ai),
        );
    }
}
