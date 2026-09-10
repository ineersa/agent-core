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
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Executes one LLM step from the immutable context carried by
 * {@see ExecuteLlmStep}. The provider boundary resolves only current model
 * selection; it never reads a run snapshot for prompt history. This worker
 * propagates the actually-resolved identity onto {@see LlmStepResult} for
 * canonical completion/failure events.
 */
final readonly class ExecuteLlmStepWorker
{
    public function __construct(
        private PlatformInterface $platform,
        private MessageBusInterface $commandBus,
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
                    // Local command-bus delivery failure is not a provider-operation
                    // retry signal. Do not let Messenger redeliver ExecuteLlmStep.
                    throw new UnrecoverableMessageHandlingException('Failed to dispatch LLM result to command bus.', previous: $exception);
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
            'provider' => 'symfony-ai',
        ]);

        try {
            // Empty model is the existing sentinel that tells the provider
            // boundary to resolve current session metadata at invocation time.
            $invoke = fn (): PlatformInvocationResult => $this->platform->invoke(new ModelInvocationRequest(
                model: '',
                input: new ModelInvocationInput(
                    runId: $message->runId(),
                    turnNo: $message->turnNo(),
                    stepId: $message->stepId(),
                    toolsRef: $message->toolsRef,
                    messages: $message->messages,
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

            // Adapter owns thinking-only / empty-stream recovery inside the shared
            // application retry budget. Any leftover empty success here is terminal.
            $assistantMessage = $response->assistantMessage;
            if (null !== $assistantMessage
                && null === $response->error
                && !$assistantMessage->hasToolCalls()
                && null === $assistantMessage->asText()
            ) {
                $response = new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: $response->deltas,
                    usage: $response->usage,
                    stopReason: $response->stopReason,
                    error: [
                        'type' => 'empty_assistant_content',
                        'message' => 'LLM provider returned reasoning without a final assistant response.',
                        'retryable' => false,
                    ],
                    model: $response->model,
                    reasoning: $response->reasoning,
                    modelNotifications: $response->modelNotifications,
                    availableTools: $response->availableTools,
                    availableToolsSchemaTokensEstimate: $response->availableToolsSchemaTokensEstimate,
                );
                $assistantMessage = null;
            }

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            $hasStreamDeltas = [] !== $response->deltas();
            if (null === $assistantMessage && !$hasStreamDeltas && null === $response->error) {
                $response = new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: $response->deltas,
                    usage: $response->usage,
                    stopReason: $response->stopReason,
                    model: $response->model,
                    reasoning: $response->reasoning,
                    modelNotifications: $response->modelNotifications,
                    availableTools: $response->availableTools,
                    availableToolsSchemaTokensEstimate: $response->availableToolsSchemaTokensEstimate,
                    error: [
                        'type' => 'empty_response',
                        'message' => 'LLM provider returned an empty response.',
                        'retryable' => false,
                    ],
                );
                $assistantMessage = null;
            }

            if (null !== $response->error) {
                $logCtx = [
                    'duration_ms' => round($durationMs, 3),
                    'event_type' => 'llm.request.failed',
                    'model' => $response->model,
                    'reasoning' => $response->reasoning,
                    'error_type' => $response->error['type'] ?? 'unknown',
                    'error_message' => mb_substr($response->error['message'] ?? 'Unknown error', 0, 500),
                ];

                // Forward all diagnostics from the platform error result.
                // These are privacy-safe structural metadata (status codes,
                // error codes, types, booleans) and never raw prompts/tokens.
                foreach ($response->error as $key => $value) {
                    if (\in_array($key, ['type', 'message'], true)) {
                        continue; // already logged above
                    }
                    if (\is_string($value)) {
                        $logCtx[$key] = mb_substr($value, 0, 500);
                    } else {
                        $logCtx[$key] = $value;
                    }
                }

                $this->logger->warning('llm.request.failed', $logCtx);
            } else {
                $this->logger->info('llm.request.completed', [
                    'duration_ms' => round($durationMs, 3),
                    'event_type' => 'llm.request.completed',
                    'model' => $response->model,
                    'reasoning' => $response->reasoning,
                ]);
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
                model: $response->model,
                reasoning: $response->reasoning,
                modelNotifications: $response->modelNotifications,
                availableTools: $response->availableTools,
                availableToolsSchemaTokensEstimate: $response->availableToolsSchemaTokensEstimate,
            );
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            $this->logger->warning('llm.request.failed', [
                'duration_ms' => round($durationMs, 3),
                'event_type' => 'llm.request.failed',
                'error_type' => $exception::class,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                // No request/response diagnostics available here because
                // this catch handles unexpected exceptions below PlatformInterface
                // (e.g. DI resolution failures, not provider HTTP errors).
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
                    'retryable' => false,
                ],
                toolsRef: $message->toolsRef,
                modelNotifications: [],
            );
        } finally {
            RunLogContext::leave();
        }
    }
}
