<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Tool\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Tool\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Tool\PlatformInvocationResult;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ExecuteLlmStepWorker
{
    public function __construct(
        private PlatformInterface $platform,
        private MessageBusInterface $commandBus,
        private string $defaultModel,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * Handles ExecuteLlmStep message by delegating to execute method.
     */
    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExecuteLlmStep $message): void
    {
        $execute = function () use ($message): void {
            $result = $this->execute($message);

            try {
                $this->commandBus->dispatch($result);
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch LLM result to command bus.', previous: $exception);
            }
        };

        if (null === $this->tracer) {
            $execute();

            return;
        }

        $this->tracer->inSpan('turn.execution.llm_worker', [
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'worker' => 'llm',
        ], $execute, root: true);
    }

    private function execute(ExecuteLlmStep $message): LlmStepResult
    {
        $startedAt = hrtime(true);

        try {
            $invoke = fn (): PlatformInvocationResult => $this->platform->invoke(new ModelInvocationRequest(
                model: $this->defaultModel,
                input: new ModelInvocationInput(
                    runId: $message->runId(),
                    turnNo: $message->turnNo(),
                    stepId: $message->stepId(),
                    contextRef: $message->contextRef,
                    toolsRef: $message->toolsRef,
                ),
            ));

            $response = null === $this->tracer
                ? $invoke()
                : $this->tracer->inSpan('llm.call', [
                    'run_id' => $message->runId(),
                    'turn_no' => $message->turnNo(),
                    'step_id' => $message->stepId(),
                ], $invoke)
            ;

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, null !== $response->error);

            $assistantMessage = $response->assistantMessage;
            $hasStreamDeltas = [] !== $response->deltas();
            if (null === $assistantMessage && !$hasStreamDeltas && null === $response->stopReason && null === $response->error) {
                $assistantMessage = new AssistantMessage(
                    content: \sprintf('LLM placeholder response for %s', $message->contextRef),
                );
            }

            return new LlmStepResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                assistantMessage: $assistantMessage,
                usage: $response->usage,
                stopReason: $response->stopReason,
                error: $response->error,
            );
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, true);

            return new LlmStepResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                assistantMessage: null,
                usage: [],
                stopReason: 'error',
                error: [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );
        }
    }
}
