<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\Model\RunModelResolverInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
        private ?RunModelResolverInterface $runModelResolver = null,
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

        $invocationModel = $this->resolveInvocationModel($message->runId());

        RunLogContext::enter([
            'event_type' => 'llm.request.started',
            'model' => $invocationModel,
            'provider' => 'symfony-ai',
        ]);

        try {
            $invoke = fn (): PlatformInvocationResult => $this->platform->invoke(new ModelInvocationRequest(
                model: $invocationModel,
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
                    'model' => $invocationModel,
                ], $invoke)
            ;

            // One-shot retry for thinking-only provider responses.
            // Providers like DeepSeek can intermittently return
            // reasoning-only output (thinking, no text, no tool calls)
            // due to cache-state shifts or server-side slot contention.
            // A single immediate retry often resolves this without
            // user-visible failure (session 16 regression).
            // The retry runs the identical request — no prompt content
            // is added to RunState.messages and no transient
            // instructions leak into persisted history.
            if ($this->isThinkingOnlyResponse($response)) {
                $this->logger->warning('llm.request.retrying_thinking_only', [
                    'run_id' => $message->runId(),
                    'turn_no' => $message->turnNo(),
                    'step_id' => $message->stepId(),
                    'event_type' => 'llm.request.retrying_thinking_only',
                ]);

                // Retry exactly once (also tracer-wrapped if available).
                $response = null === $this->tracer
                    ? $invoke()
                    : $this->tracer->inSpan('llm.call', [
                        'run_id' => $message->runId(),
                        'turn_no' => $message->turnNo(),
                        'step_id' => $message->stepId(),
                        'model' => $invocationModel,
                    ], $invoke)
                ;
            }

            // Thinking-only assistant messages (no text content, no
            // tool calls) are not valid conversation turns. Providers
            // like DeepSeek can produce reasoning-only responses when
            // max_tokens is exhausted mid-thinking, and replaying these
            // empty messages causes HTTP 400 "content or tool_calls
            // must be set". Convert to an error before metrics/logging
            // so it counts as a failure.  The one-shot retry above may
            // already have recovered; this guard catches the final
            // (possibly retried) result.
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
                    modelNotifications: $response->modelNotifications,
                );
            }

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            // Detect fully empty platform response BEFORE metrics and
            // logging so the deficiency is counted as an error, not a
            // silent success.
            $assistantMessage = $response->assistantMessage;
            $hasStreamDeltas = [] !== $response->deltas();
            if (null === $assistantMessage && !$hasStreamDeltas && null === $response->error) {
                // Fully empty platform result: no assistant message, no stream
                // deltas, and no error. A finish_reason/stopReason alone (no
                // content) still counts as empty here.
                // Treat as an error
                // rather than fabricating placeholder text that enters the
                // conversation history.
                $response = new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: $response->deltas,
                    usage: $response->usage,
                    stopReason: $response->stopReason,
                    modelNotifications: $response->modelNotifications,
                    error: [
                        'type' => 'empty_response',
                        'message' => 'LLM provider returned an empty response.',
                        'retryable' => false,
                    ],
                );
                $assistantMessage = null;
            }

            $this->metrics?->recordLlmLatency($durationMs, null !== $response->error);

            if (null !== $response->error) {
                $logCtx = [
                    'duration_ms' => round($durationMs, 3),
                    'event_type' => 'llm.request.failed',
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
                modelNotifications: $response->modelNotifications,
            );
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordLlmLatency($durationMs, true);

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
                ],
                toolsRef: $message->toolsRef,
                modelNotifications: [],
            );
        } finally {
            RunLogContext::leave();
        }
    }

    /**
     * Resolve the model sent to the platform for this run.
     *
     * Normal turns use the active session model from {@see RunModelResolverInterface}.
     * $defaultModel remains a documented fallback for isolated tests or degenerate
     * configurations where no resolver or catalog model is available.
     */
    private function resolveInvocationModel(string $runId): string
    {
        $resolved = $this->runModelResolver?->resolveActiveModel($runId);
        if (null !== $resolved && '' !== $resolved) {
            return $resolved;
        }

        return $this->defaultModel;
    }

    /**
     * Returns true when the platform returned reasoning-only output
     * (thinking content, no text, no tool calls) without an explicit error.
     *
     * These are not valid conversation turns and must not be persisted
     * in RunState.messages — they cause HTTP 400 "content or tool_calls
     * must be set" when replayed on the next turn (DeepSeek, session 6).
     *
     * callers are expected to retry once before conceding failure
     * (session 16 regression: intermittent reasoning-only output from
     *  provider cache-state shifts resolves on simple immediate retry).
     */
    private function isThinkingOnlyResponse(PlatformInvocationResult $response): bool
    {
        $assistantMessage = $response->assistantMessage;

        return null !== $assistantMessage
            && null === $response->error
            && !$assistantMessage->hasToolCalls()
            && null === $assistantMessage->asText();
    }
}
