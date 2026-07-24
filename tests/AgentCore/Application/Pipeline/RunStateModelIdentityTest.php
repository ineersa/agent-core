<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use PHPUnit\Framework\TestCase;

final class RunStateModelIdentityTest extends TestCase
{
    public function testRunStartedModelSurvivesReplayAndSchedulesExecuteLlmStep(): void
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

        $state = (new RunStateReducer())->replay(RunState::queued($runId), $events);
        $this->assertSame('deepseek/deepseek-v4-flash', $state->model);

        $commandStore = new InMemoryCommandStore();
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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
        $this->assertSame('deepseek/deepseek-v4-flash', $step->model);
        $this->assertSame('deepseek/deepseek-v4-flash', $result->nextState?->model);
    }

    public function testModelChangedEventReplaysIntoStateAndNextScheduleUsesNewModel(): void
    {
        $runId = 'run-model-2';
        $queuedStep = new ExecuteLlmStep(
            runId: $runId,
            turnNo: 1,
            stepId: 'queued-old',
            attempt: 1,
            idempotencyKey: 'ik-old',
            contextRef: 'hot:run:'.$runId,
            toolsRef: 'toolset:run:'.$runId.':turn:1',
            model: 'deepseek/deepseek-v4-flash',
        );

        $replayed = (new RunStateReducer())->replay(
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
        // Already-queued ExecuteLlmStep remains immutable on the old model.
        $this->assertSame('deepseek/deepseek-v4-flash', $queuedStep->model);

        $commandStore = new InMemoryCommandStore();
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: $commandStore,
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
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
        $this->assertSame('openai-codex/gpt-5.6-sol', $step->model);
        $this->assertSame('openai-codex/gpt-5.6-sol', $result->nextState?->model);
    }

    public function testMissingRunModelFailsClosedBeforeScheduling(): void
    {
        $runId = 'run-model-missing';
        $state = new RunState(runId: $runId, status: RunStatus::Running, model: null);
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run model is absent');
        $handler->handle(new AdvanceRun($runId, 0, 'adv-missing', 1, 'ik-missing'), $state);
    }
}
