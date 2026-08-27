<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Execution identity is resolved at the provider boundary, not scheduled from
 * RunState.  RunState.model remains a historical replay projection (run_started
 * / model_changed) for resume display and diagnostics, but AdvanceRun schedules
 * {@see ExecuteLlmStep} without any model snapshot: the current session
 * metadata wins for ordinary turns (session-41 regression: DB said Sol/high,
 * historical run_started said Grok/minimal — execution must use Sol/high).
 */
final class RunStateModelIdentityTest extends TestCase
{
    public function testRunStartedModelReplaysIntoStateButSchedulingCarriesNoModel(): void
    {
        $runId = 'run-model-1';
        $events = [
            new RunEvent(
                runId: $runId,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'step_id' => 'start',
                    'payload' => [
                        'messages' => [],
                        'metadata' => ['model' => 'deepseek/deepseek-v4-flash'],
                    ],
                ],
            ),
        ];

        $state = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()))->replay(RunState::queued($runId), $events);
        $this->assertSame('deepseek/deepseek-v4-flash', $state->model, 'Historical model still replays for diagnostics.');

        $commandStore = new InMemoryCommandStore();
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter([]),
            ),
            eventFactory: new EventFactory(),
        );
        $result = $handler->handle(
            new AdvanceRun($runId, 0, 'adv-1', 1, 'ik-1'),
            $state,
        );

        $step = null;
        foreach ($result->effects as $effect) {
            if ($effect instanceof ExecuteLlmStep) {
                $step = $effect;
            }
        }
        $this->assertInstanceOf(ExecuteLlmStep::class, $step);
        $this->assertFalse(property_exists($step, 'model'), 'Scheduling must not snapshot RunState model.');
        $this->assertSame('deepseek/deepseek-v4-flash', $result->nextState?->model, 'Replay projection stays intact.');
    }

    public function testModelChangedEventReplaysIntoStateButSchedulingStillCarriesNoModel(): void
    {
        $runId = 'run-model-2';
        $replayed = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()))->replay(
            RunState::queued($runId),
            [
                new RunEvent(
                    runId: $runId,
                    seq: 1,
                    turnNo: 0,
                    type: RunEventTypeEnum::RunStarted->value,
                    payload: [
                        'step_id' => 'start',
                        'payload' => ['messages' => [], 'metadata' => ['model' => 'deepseek/deepseek-v4-flash']],
                    ],
                ),
                new RunEvent(
                    runId: $runId,
                    seq: 2,
                    turnNo: 0,
                    type: RunEventTypeEnum::ModelChanged->value,
                    payload: ['model' => 'openai-codex/gpt-5.6-sol', 'previous_model' => 'deepseek/deepseek-v4-flash'],
                ),
            ],
        );

        $this->assertSame('openai-codex/gpt-5.6-sol', $replayed->model);

        $commandStore = new InMemoryCommandStore();
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter([]),
            ),
            eventFactory: new EventFactory(),
        );
        $result = $handler->handle(
            new AdvanceRun($runId, 0, 'adv-2', 1, 'ik-2'),
            $replayed,
        );

        $step = null;
        foreach ($result->effects as $effect) {
            if ($effect instanceof ExecuteLlmStep) {
                $step = $effect;
            }
        }
        $this->assertInstanceOf(ExecuteLlmStep::class, $step);
        $this->assertFalse(property_exists($step, 'model'), 'Historical model_changed must not become an execution override.');
        $this->assertSame('openai-codex/gpt-5.6-sol', $result->nextState?->model);
    }

    public function testTurnAdvancedReplaysCommittedAdvanceToken(): void
    {
        $state = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()))->replay(
            RunState::queued('run-advance-replay'),
            [new RunEvent(
                runId: 'run-advance-replay',
                seq: 1,
                turnNo: 1,
                type: RunEventTypeEnum::TurnAdvanced->value,
                payload: [
                    'turn_no' => 1,
                    'step_id' => 'llm-step-1',
                    'operation_idempotency_key' => 'llm-key-1',
                    'advance_idempotency_key' => 'advance-key-1',
                ],
            )],
        );

        $this->assertSame('advance-key-1', $state->lastAppliedAdvanceKey);
    }

    public function testAutoCompactionRequestReplaysAdvanceTokenAndRejectsRedelivery(): void
    {
        $guard = $this->createStub(PreLlmCompactionGuardInterface::class);
        $guard->method('shouldCompactBeforeLlmStep')->willReturn(true);
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(new InMemoryCommandStore(), new CommandRouter([])),
            eventFactory: new EventFactory(),
            preLlmCompactionGuard: $guard,
        );
        $state = new RunState(runId: 'run-auto-compact', status: RunStatus::Running);
        $advance = new AdvanceRun('run-auto-compact', 0, 'advance-1', 1, 'advance-key-1');

        $started = $handler->handle($advance, $state);
        $this->assertContainsOnlyInstancesOf(CompactRun::class, $started->effects);
        $replayed = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()))->replay($state, $started->events);

        $this->assertSame('advance-key-1', $replayed->lastAppliedAdvanceKey);
        $redelivery = $handler->handle($advance, $replayed);
        $this->assertNull($redelivery->nextState);
        $this->assertSame([], $redelivery->effects);
    }

    public function testHistoricalCompactionStartWithoutOperationKeyReplaysTerminalEventSafely(): void
    {
        $state = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()))->replay(
            RunState::queued('run-legacy-compaction'),
            [
                new RunEvent(
                    runId: 'run-legacy-compaction', seq: 1, turnNo: 0,
                    type: RunEventTypeEnum::ContextCompactionStarted->value,
                    payload: ['step_id' => 'compact-1'],
                ),
                new RunEvent(
                    runId: 'run-legacy-compaction', seq: 2, turnNo: 0,
                    type: RunEventTypeEnum::ContextCompacted->value,
                    payload: ['messages' => [], 'trigger' => 'manual'],
                ),
            ],
        );

        $this->assertSame(RunStatus::Completed, $state->status);
        $this->assertNull($state->lastAppliedCompactionKey);
    }

    public function testMissingRunModelSchedulesWithNoModelInsteadOfFailingClosed(): void
    {
        $runId = 'run-model-missing';
        $state = new RunState(runId: $runId, status: RunStatus::Running, model: null);
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter([]),
            ),
            eventFactory: new EventFactory(),
        );
        $result = $handler->handle(new AdvanceRun($runId, 0, 'adv-missing', 1, 'ik-missing'), $state);

        $step = null;
        foreach ($result->effects as $effect) {
            if ($effect instanceof ExecuteLlmStep) {
                $step = $effect;
            }
        }
        $this->assertInstanceOf(ExecuteLlmStep::class, $step);
        $this->assertFalse(property_exists($step, 'model'), 'Missing RunState model is not a scheduling failure: the provider boundary resolves it.');
    }
}
