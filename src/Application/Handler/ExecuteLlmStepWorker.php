<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * This class serves as a message handler for executing LLM steps within the agent execution domain. It coordinates with the platform to perform the step logic and dispatches the resulting state updates via a dedicated command bus.
 */
final readonly class ExecuteLlmStepWorker
{
    /**
     * Injects platform and command bus dependencies.
     */
    public function __construct(
        private PlatformInterface $platform,
        private MessageBusInterface $commandBus,
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

    /**
     * Executes the LLM step logic and returns the result.
     */
    private function execute(ExecuteLlmStep $message): LlmStepResult
    {
        $startedAt = hrtime(true);

        try {
            $invoke = fn (): array => $this->platform->invoke('default', [
                'run_id' => $message->runId(),
                'turn_no' => $message->turnNo(),
                'step_id' => $message->stepId(),
                'context_ref' => $message->contextRef,
                'tools_ref' => $message->toolsRef,
            ]);

            $response = null === $this->tracer
                ? $invoke()
                : $this->tracer->inSpan('llm.call', [
                    'run_id' => $message->runId(),
                    'turn_no' => $message->turnNo(),
                    'step_id' => $message->stepId(),
                ], $invoke)
            ;

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, \is_array($response['error'] ?? null));

            $assistantMessage = null;
            if (\is_array($response['assistant_message'] ?? null)) {
                $assistantMessage = $response['assistant_message'];
            } elseif (\is_string($response['text'] ?? null)) {
                $assistantMessage = [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => $response['text'],
                    ]],
                ];
            } elseif (null === ($response['stop_reason'] ?? null) && !\is_array($response['error'] ?? null)) {
                $assistantMessage = [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => \sprintf('LLM placeholder response for %s', $message->contextRef),
                    ]],
                ];
            }

            return new LlmStepResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                assistantMessage: $assistantMessage,
                usage: \is_array($response['usage'] ?? null) ? $response['usage'] : [],
                stopReason: \is_string($response['stop_reason'] ?? null) ? $response['stop_reason'] : null,
                error: \is_array($response['error'] ?? null) ? $response['error'] : null,
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
