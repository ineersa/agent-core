<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
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
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Handles ExecuteLlmStep message by delegating to execute method.
     */
    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExecuteLlmStep $message): void
    {
        RunLogContext::enter([
            'run_id' => $message->runId(),
            'session_id' => $message->runId(),
            'component' => 'llm',
            'queue' => 'agent.execution.bus',
            'worker' => 'llm',
        ]);

        try {
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
        } finally {
            RunLogContext::leave();
        }
    }

    private function execute(ExecuteLlmStep $message): LlmStepResult
    {
        $startedAt = hrtime(true);

        RunLogContext::enter([
            'event_type' => 'llm.request.started',
            'model' => $this->defaultModel,
            'provider' => 'symfony-ai',
        ]);

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
                    'model' => $this->defaultModel,
                ], $invoke)
            ;

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, null !== $response->error);

            if (null !== $response->error) {
                $this->logger->info('llm.request.failed', [
                    'duration_ms' => round($durationMs, 3),
                    'event_type' => 'llm.request.failed',
                ]);
            } else {
                $this->logger->info('llm.request.completed', [
                    'duration_ms' => round($durationMs, 3),
                    'event_type' => 'llm.request.completed',
                ]);
            }

            $assistantMessage = $response->assistantMessage;
            $hasStreamDeltas = [] !== $response->deltas();
            if (null === $assistantMessage && !$hasStreamDeltas && null === $response->stopReason && null === $response->error) {
                $assistantMessage = new AssistantMessage(
                    new Text(\sprintf('LLM placeholder response for %s', $message->contextRef)),
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
                toolsRef: $message->toolsRef,
            );
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, true);

            $this->logger->info('llm.request.failed', [
                'duration_ms' => round($durationMs, 3),
                'event_type' => 'llm.request.failed',
                'error_type' => $exception::class,
            ]);

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
                toolsRef: $message->toolsRef,
            );
        } finally {
            RunLogContext::leave();
        }
    }
}
