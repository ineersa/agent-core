<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\ContextBudget\ContextBudgetReminderDecision;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Compaction\CodingAgentPreLlmContextBudgetReminderPolicy;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ContextBudgetReminderConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Compaction\CodingAgentPreLlmContextBudgetReminderPolicy
 *
 * Thesis: threshold edges, urgent precedence, one-shot markers, compaction
 * barrier reset, missing metadata, and catalog context-window fallback.
 */
#[AllowMockObjectsWithoutExpectations]
final class CodingAgentPreLlmContextBudgetReminderPolicyTest extends TestCase
{
    /** @var EventStoreInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventStore;
    private CodingAgentPreLlmContextBudgetReminderPolicy $policy;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->policy = new CodingAgentPreLlmContextBudgetReminderPolicy(
            $this->eventStore,
            new ContextBudgetReminderConfig(
                earlyInputTokens: 200000,
                urgentRemainingTokens: 25000,
            ),
            $this->appConfigWithCatalog(window: 272000),
        );
    }

    public function testNullWhenNoProviderUsage(): void
    {
        $this->mockEvents([$this->runStarted(272000)]);

        $this->assertNull($this->policy->decide('run-1', 'test/model'));
    }

    public function testNullWhenNoContextWindow(): void
    {
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->eventStore->method('allFor')->willReturn([
            $this->runStarted(null),
            $this->llmCompleted(1, 210000),
        ]);
        $policy = new CodingAgentPreLlmContextBudgetReminderPolicy(
            $this->eventStore,
            new ContextBudgetReminderConfig(),
            $this->appConfigWithCatalog(window: null),
        );

        $this->assertNull($policy->decide('run-1', 'missing/model'));
    }

    public function testEarlyAtExactThreshold(): void
    {
        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 200000),
        ]);

        $decision = $this->policy->decide('run-1', 'test/model');
        $this->assertNotNull($decision);
        $this->assertSame(CodingAgentPreLlmContextBudgetReminderPolicy::EARLY_TEXT, $decision->text);
        $this->assertSame([ContextBudgetReminderDecision::KEY_EARLY], $decision->handledThresholdKeys);
    }

    public function testEarlyNotBelowThreshold(): void
    {
        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 199999),
        ]);

        $this->assertNull($this->policy->decide('run-1', 'test/model'));
    }

    public function testUrgentStrictEdge(): void
    {
        // remaining = 272000 - 247000 = 25000 — not urgent (strict <),
        // but early still fires because 247000 >= 200000.
        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 247000),
        ]);
        $atEdge = $this->policy->decide('run-1', 'test/model');
        $this->assertNotNull($atEdge);
        $this->assertSame(CodingAgentPreLlmContextBudgetReminderPolicy::EARLY_TEXT, $atEdge->text);
        $this->assertSame([ContextBudgetReminderDecision::KEY_EARLY], $atEdge->handledThresholdKeys);

        // remaining = 24999 → urgent; also early because 247001 >= 200000
        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 247001),
        ]);
        $decision = $this->policy->decide('run-1', 'test/model');
        $this->assertNotNull($decision);
        $this->assertSame(CodingAgentPreLlmContextBudgetReminderPolicy::URGENT_TEXT, $decision->text);
        $this->assertSame(
            [ContextBudgetReminderDecision::KEY_URGENT, ContextBudgetReminderDecision::KEY_EARLY],
            $decision->handledThresholdKeys,
        );
    }

    public function testUrgentStrictEdgeWithoutEarlyOnSmallWindow(): void
    {
        // Window 50000, early threshold 200000 never reachable.
        // remaining == 25000 at input 25000 — not urgent.
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->eventStore->method('allFor')->willReturn([
            $this->runStarted(50000),
            $this->llmCompleted(1, 25000),
        ]);
        $policy = new CodingAgentPreLlmContextBudgetReminderPolicy(
            $this->eventStore,
            new ContextBudgetReminderConfig(
                earlyInputTokens: 200000,
                urgentRemainingTokens: 25000,
            ),
            $this->appConfigWithCatalog(window: 50000),
        );
        $this->assertNull($policy->decide('run-1', 'test/model'));

        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->eventStore->method('allFor')->willReturn([
            $this->runStarted(50000),
            $this->llmCompleted(1, 25001),
        ]);
        $policy = new CodingAgentPreLlmContextBudgetReminderPolicy(
            $this->eventStore,
            new ContextBudgetReminderConfig(
                earlyInputTokens: 200000,
                urgentRemainingTokens: 25000,
            ),
            $this->appConfigWithCatalog(window: 50000),
        );
        $decision = $policy->decide('run-1', 'test/model');
        $this->assertNotNull($decision);
        $this->assertSame(CodingAgentPreLlmContextBudgetReminderPolicy::URGENT_TEXT, $decision->text);
        $this->assertSame([ContextBudgetReminderDecision::KEY_URGENT], $decision->handledThresholdKeys);
    }

    public function testOneShotMarkersSuppressRepeat(): void
    {
        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 210000, [ContextBudgetReminderDecision::KEY_EARLY]),
            $this->llmCompleted(2, 220000),
        ]);

        $this->assertNull($this->policy->decide('run-1', 'test/model'));
    }

    public function testEarlyThenUrgentLater(): void
    {
        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 210000, [ContextBudgetReminderDecision::KEY_EARLY]),
            $this->llmCompleted(2, 250000),
        ]);

        $decision = $this->policy->decide('run-1', 'test/model');
        $this->assertNotNull($decision);
        $this->assertSame(CodingAgentPreLlmContextBudgetReminderPolicy::URGENT_TEXT, $decision->text);
        $this->assertSame([ContextBudgetReminderDecision::KEY_URGENT], $decision->handledThresholdKeys);
    }

    public function testCompactionResetsEpisode(): void
    {
        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 250000, [
                ContextBudgetReminderDecision::KEY_EARLY,
                ContextBudgetReminderDecision::KEY_URGENT,
            ]),
            $this->contextCompacted(2),
            // stale pre-compaction usage must be ignored; no post-compaction usage yet
        ]);
        $this->assertNull($this->policy->decide('run-1', 'test/model'));

        $this->mockEvents([
            $this->runStarted(272000),
            $this->llmCompleted(1, 250000, [
                ContextBudgetReminderDecision::KEY_EARLY,
                ContextBudgetReminderDecision::KEY_URGENT,
            ]),
            $this->contextCompacted(2),
            $this->llmCompleted(3, 210000),
        ]);
        $decision = $this->policy->decide('run-1', 'test/model');
        $this->assertNotNull($decision);
        $this->assertSame([ContextBudgetReminderDecision::KEY_EARLY], $decision->handledThresholdKeys);
    }

    public function testCatalogFallbackWhenRunMetadataLacksWindow(): void
    {
        $this->mockEvents([
            $this->runStarted(null),
            $this->llmCompleted(1, 200000),
        ]);

        $decision = $this->policy->decide('run-1', 'test/model');
        $this->assertNotNull($decision);
        $this->assertSame(CodingAgentPreLlmContextBudgetReminderPolicy::EARLY_TEXT, $decision->text);
    }

    public function testPromptTokensAliasAccepted(): void
    {
        $event = new RunEvent(
            runId: 'run-1',
            seq: 1,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 's1',
                'usage' => ['prompt_tokens' => 200000],
            ],
        );

        $this->mockEvents([$this->runStarted(272000), $event]);
        $this->assertNotNull($this->policy->decide('run-1', 'test/model'));
    }

    /**
     * @param list<RunEvent> $events
     */
    private function mockEvents(array $events): void
    {
        // Fresh mock each time: createMock stubs accumulate and last willReturn wins poorly across tests.
        $this->eventStore = $this->createMock(EventStoreInterface::class);
        $this->eventStore->method('allFor')->willReturn($events);
        $this->policy = new CodingAgentPreLlmContextBudgetReminderPolicy(
            $this->eventStore,
            new ContextBudgetReminderConfig(
                earlyInputTokens: 200000,
                urgentRemainingTokens: 25000,
            ),
            $this->appConfigWithCatalog(window: 272000),
        );
    }

    private function runStarted(?int $contextWindow): RunEvent
    {
        $metadata = ['model' => 'test/model'];
        if (null !== $contextWindow) {
            $metadata['context_window'] = $contextWindow;
        }

        return new RunEvent(
            runId: 'run-1',
            seq: 0,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start',
                'payload' => [
                    'metadata' => $metadata,
                ],
            ],
        );
    }

    /**
     * @param list<string> $handled
     */
    private function llmCompleted(int $seq, int $inputTokens, array $handled = []): RunEvent
    {
        $payload = [
            'step_id' => 'step-'.$seq,
            'usage' => ['input_tokens' => $inputTokens],
        ];
        if ([] !== $handled) {
            $payload['context_budget_reminders_handled'] = $handled;
        }

        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: $seq,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: $payload,
        );
    }

    private function contextCompacted(int $seq): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: $seq,
            type: RunEventTypeEnum::ContextCompacted->value,
            payload: ['trigger' => 'auto'],
        );
    }

    private function appConfigWithCatalog(?int $window): AppConfig
    {
        $model = new AiModelDefinition(
            id: 'model',
            name: 'model',
            contextWindow: $window,
        );
        $provider = new AiProviderConfig(
            id: 'test',
            models: ['model' => $model],
        );
        $ai = new AiConfig(
            defaultModel: 'test/model',
            providers: ['test' => $provider],
        );

        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            ai: $ai,
            catalog: new HatfieldModelCatalog($ai),
        );
    }
}
