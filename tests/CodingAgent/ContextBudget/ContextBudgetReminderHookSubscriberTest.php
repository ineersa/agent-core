<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\ContextBudget;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
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
    /** @var RunStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $runStore;
    /** @var AgentRunnerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $agentRunner;
    private ContextBudgetReminderHookSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->runStore = $this->createMock(RunStoreInterface::class);
        $this->agentRunner = $this->createMock(AgentRunnerInterface::class);

        $this->subscriber = new ContextBudgetReminderHookSubscriber(
            $this->eventStore,
            $this->agentRunner,
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
        $this->runStore->method('get')->willReturn($this->runState());

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                $this->callback(static function (AgentMessage $message): bool {
                    return 'user' === $message->role
                        && true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::EARLY_TEXT,
                        );
                }),
            );

        $this->subscriber->handleAfterTurnCommit($this->hookContext([
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
        $this->runStore->method('get')->willReturn($this->runState());

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                $this->callback(static function (AgentMessage $message): bool {
                    return true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::URGENT_TEXT,
                        );
                }),
            );

        // remaining = 272000 - 250000 = 22000 < 25000 and input >= 200000
        $this->subscriber->handleAfterTurnCommit($this->hookContext([
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
        $this->runStore->method('get')->willReturn($this->runState());

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                $this->callback(static function (AgentMessage $message): bool {
                    return true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::URGENT_TEXT,
                        );
                }),
            );

        // Early already queued; remaining now urgent.
        $this->subscriber->handleAfterTurnCommit($this->hookContext([
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
        $this->runStore->method('get')->willReturn($this->runState());

        $this->agentRunner->expects($this->once())
            ->method('appendMessage')
            ->with(
                'run-1',
                $this->callback(static function (AgentMessage $message): bool {
                    return true === ($message->metadata['system_reminder'] ?? null)
                        && ($message->content[0]['text'] ?? null) === ContextBudgetReminderHookSubscriber::wrapSystemReminder(
                            ContextBudgetReminderHookSubscriber::EARLY_TEXT,
                        );
                }),
            );

        $this->subscriber->handleAfterTurnCommit($this->hookContext([
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

        $this->subscriber->handleAfterTurnCommit($this->hookContext([
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
        $this->runStore->method('get')->willReturn($this->runState(model: null));

        $this->agentRunner->expects($this->never())->method('appendMessage');

        // No positive usage
        $this->subscriber->handleAfterTurnCommit($this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 0],
            ]),
        ]));

        // Missing window (run_started has none, catalog empty for null model)
        $this->subscriber->handleAfterTurnCommit($this->hookContext([
            $this->summary(3, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 210000],
            ]),
        ]));
    }

    public function testUnrelatedOrAbortedEventsDoNotQueue(): void
    {
        $this->mockEvents([
            $this->runStarted(1, 272000),
        ]);
        $this->runStore->method('get')->willReturn($this->runState());
        $this->agentRunner->expects($this->never())->method('appendMessage');

        $this->subscriber->handleAfterTurnCommit($this->hookContext([
            $this->summary(2, RunEventTypeEnum::LlmStepAborted->value, [
                'usage' => ['input_tokens' => 250000],
            ]),
        ]));

        $this->subscriber->handleAfterTurnCommit($this->hookContext([
            $this->summary(3, RunEventTypeEnum::ToolBatchCommitted->value, []),
        ]));
    }

    public function testUsesCommittedContextModelWhenRunStartedHasNoWindow(): void
    {
        $this->mockEvents([
            $this->runStarted(1, null),
        ]);
        $this->runStore->expects($this->never())->method('get');
        $this->agentRunner->expects($this->once())->method('appendMessage');

        $this->subscriber->handleAfterTurnCommit($this->hookContext(
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
        $this->runStore->method('get')->willReturn($this->runState());
        $this->agentRunner->expects($this->never())->method('appendMessage');

        $this->subscriber->handleAfterTurnCommit($this->hookContext([
            $this->summary(3, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 260000],
            ]),
        ]));
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
            runState: $runState ?? $this->runStore->get('run-1'),
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

    private function runStarted(int $seq, ?int $contextWindow): RunEvent
    {
        $metadata = [];
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
