<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\CostCalculatorInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

final readonly class LlmPlatformAdapter implements PlatformInterface
{
    /**
     * @param iterable<TransformContextHookInterface> $transformContextHooks
     * @param iterable<ConvertToLlmHookInterface>     $convertToLlmHooks
     */
    public function __construct(
        private RunStoreInterface $runStore,
        private AgentMessageConverter $messageConverter,
        private DynamicToolDescriptionProcessor $toolDescriptionProcessor,
        private SymfonyPlatformInterface $platform,
        private iterable $transformContextHooks,
        private iterable $convertToLlmHooks,
        private ?LlmStreamObserverInterface $streamObserver,
        private ?CostCalculatorInterface $costCalculator,
        private LoggerInterface $logger,
        private ?ModelResolverInterface $modelResolver = null,
        private readonly LlmProviderErrorClassifier $errorClassifier = new LlmProviderErrorClassifier(),
    ) {
    }

    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
    {
        $cancelToken = $this->cancellationToken($request);
        $messages = $this->resolveContextMessages($request->input);
        $messages = $this->applyTransformHooks($messages, $cancelToken);

        $messageBag = $this->applyConvertHooks($messages, $cancelToken, $request->model);

        $options = $this->buildInputOptions($request);
        $input = new Input($request->model, $messageBag, $options);
        $this->toolDescriptionProcessor->processInput($input);

        // Build a privacy-safe request summary for error diagnostics.
        // This is included in the error array when the request fails.
        $inputOptions = $input->getOptions();
        $requestSummary = [
            'model' => $request->model,
            'input_count' => \count($messageBag->withoutSystemMessage()->getMessages()),
            'has_instructions' => null !== $messageBag->getSystemMessage(),
            'has_tools' => isset($inputOptions['tools']),
            'tool_count' => \is_array($inputOptions['tools'] ?? null) ? \count($inputOptions['tools']) : 0,
        ];

        // Resolve the effective model ref for cost calculation and any
        // model-aware logic.  $request->model is the legacy empty-string
        // container parameter (app.default_model), not a user override;
        // SessionAwareModelResolver picks the real model from session
        // metadata / provider defaults without an explicit override.
        $effectiveModel = $request->model;
        if (null !== $this->modelResolver) {
            $effectiveModel = $this->modelResolver->resolve(
                defaultModel: $request->model,
                messages: $messageBag,
                input: $request->input,
                options: new ModelResolutionOptions($inputOptions),
            )->model;
        }

        return $this->consumeStream(
            $this->platform->invoke(
                $input->getModel(),
                $input->getMessageBag(),
                PlatformInvocationMetadata::inject(
                    array_replace($inputOptions, ['stream' => true]),
                    new PlatformInvocationMetadata($request->input, $cancelToken),
                ),
            ),
            $cancelToken,
            $request->input->runId ?? '',
            $request->input->stepId,
            $effectiveModel,
            $requestSummary,
        );
    }

    /**
     * @return list<AgentMessage>
     */
    private function resolveContextMessages(ModelInvocationInput $input): array
    {
        if (null !== $input->messages) {
            return $input->messages;
        }

        if (null === $input->runId) {
            return [];
        }

        $state = $this->runStore->get($input->runId);
        if (null === $state) {
            return [];
        }

        return $this->hydrateMessages($state->messages);
    }

    /**
     * Convert raw arrays (from JSON-deserialized RunState) back to
     * AgentMessage objects so downstream converters receive typed values.
     *
     * @param list<AgentMessage|array<string, mixed>> $raw
     *
     * @return list<AgentMessage>
     */
    private function hydrateMessages(array $raw): array
    {
        $messages = [];

        foreach ($raw as $entry) {
            if ($entry instanceof AgentMessage) {
                $messages[] = $entry;
            } elseif (\is_array($entry)) {
                $hydrated = AgentMessage::fromPayload($entry);
                if (null !== $hydrated) {
                    $messages[] = $hydrated;
                }
            }
        }

        return $messages;
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    private function applyTransformHooks(array $messages, CancellationTokenInterface $cancelToken): array
    {
        $transformed = $messages;

        foreach ($this->transformContextHooks as $hook) {
            $transformed = $hook->transformContext($transformed, $cancelToken);
        }

        return $transformed;
    }

    /**
     * @param list<AgentMessage> $messages
     * @param string             $modelName Model identifier for capability-aware
     *                                      hooks (e.g. image gating). Passed through
     *                                      to ConvertToLlmHookInterface::convertToLlm().
     */
    private function applyConvertHooks(array $messages, CancellationTokenInterface $cancelToken, string $modelName = ''): MessageBag
    {
        $resolvedMessageBag = null;

        foreach ($this->convertToLlmHooks as $hook) {
            $resolvedMessageBag = $hook->convertToLlm($messages, $cancelToken, $modelName);
        }

        return $resolvedMessageBag ?? $this->messageConverter->toMessageBag($messages);
    }

    /**
     * Build Input options, propagating toolsRef, turnNo, and runId for
     * DynamicToolDescriptionProcessor / ToolSetResolver resolution.
     *
     * @return array<string, mixed>
     */
    private function buildInputOptions(ModelInvocationRequest $request): array
    {
        $options = [];

        if (null !== $request->input->toolsRef) {
            $options['tools_ref'] = $request->input->toolsRef;
        }
        if (null !== $request->input->turnNo) {
            $options['turn_no'] = $request->input->turnNo;
        }
        if (null !== $request->input->runId) {
            $options['run_id'] = $request->input->runId;
        }

        return $options;
    }

    private function cancellationToken(ModelInvocationRequest $request): CancellationTokenInterface
    {
        if ($request->options->cancelToken instanceof CancellationTokenInterface) {
            return $request->options->cancelToken;
        }

        if (null !== $request->input->runId) {
            return new RunCancellationToken($this->runStore, $request->input->runId);
        }

        return new NullCancellationToken();
    }

    /**
     * @param array<string, mixed> $requestSummary Privacy-safe request summary for error diagnostics
     */
    private function consumeStream(
        DeferredResult $deferredResult,
        CancellationTokenInterface $cancelToken,
        string $runId,
        ?string $stepId,
        string $modelName = '',
        array $requestSummary = [],
    ): PlatformInvocationResult {
        $aborted = false;
        $deltas = [];

        $this->notifyStreamStart($runId, $stepId);

        try {
            foreach ($deferredResult->asStream() as $delta) {
                if ($cancelToken->isCancellationRequested()) {
                    $aborted = true;
                    break;
                }

                if ($delta instanceof DeltaInterface) {
                    $deltas[] = $delta;
                    $this->notifyDelta($runId, $stepId, $delta);
                }
            }
        } catch (\Throwable $exception) {
            $this->notifyStreamError($runId, $stepId, $exception);

            $this->logger->warning('llm.provider.stream_error', $this->buildErrorLogContext(
                $exception,
                $runId,
                $stepId,
                $deferredResult,
                $requestSummary,
            ));

            return $this->errorResult($deltas, $exception, $deferredResult, $modelName, $requestSummary);
        }

        $this->notifyStreamEnd($runId, $stepId);

        if ($aborted) {
            $this->abortConnection($deferredResult);
        }

        $assistantMessage = $this->buildAssistantMessage($deltas);

        return new PlatformInvocationResult(
            assistantMessage: $assistantMessage,
            deltas: $deltas,
            usage: $this->extractUsage($deferredResult, $modelName),
            stopReason: $aborted ? 'aborted' : $this->resolveStopReason($assistantMessage),
            error: null,
        );
    }

    /**
     * @param array<string, mixed> $requestSummary Privacy-safe request summary
     *
     * @return array<string, mixed>
     */
    private function buildErrorLogContext(
        \Throwable $exception,
        string $runId,
        ?string $stepId,
        DeferredResult $deferredResult,
        array $requestSummary = [],
    ): array {
        $context = [
            'event_type' => 'llm.provider.stream_error',
            'run_id' => $runId,
            'step_id' => $stepId,
            'error_type' => $exception::class,
            'error_message' => mb_substr($exception->getMessage(), 0, 500),
        ];

        // Extract response diagnostics from the raw HTTP result if available.
        $responseDiagnostics = $this->extractResponseDiagnostics($deferredResult);
        foreach ($responseDiagnostics as $key => $value) {
            if (null !== $value) {
                $context[$key] = $value;
            }
        }

        // Merge request summary (privacy-safe structural metadata).
        foreach ($requestSummary as $key => $value) {
            $context['request_'.$key] = $value;
        }

        return $context;
    }

    /**
     * Extract privacy-safe response diagnostics from a DeferredResult.
     *
     * Returns an array of diagnostics keys, with values truncated and
     * sensitive data excluded.
     *
     * @return array<string, mixed>
     */
    private function extractResponseDiagnostics(DeferredResult $deferredResult): array
    {
        $rawResult = $deferredResult->getRawResult();

        if (!$rawResult instanceof RawHttpResult) {
            return [];
        }

        try {
            $response = $rawResult->getObject();
        } catch (\Throwable) {
            return [];
        }

        $diag = [
            'http_status_code' => null,
            'response_content_type' => null,
            'response_error_code' => null,
            'response_error_type' => null,
            'response_error_param' => null,
            'response_error_message' => null,
            'response_body_preview' => null,
        ];

        try {
            $diag['http_status_code'] = $response->getStatusCode();
        } catch (\Throwable) {
        }

        // Extract headers
        try {
            $headers = $response->getHeaders(false);
            $diag['response_content_type'] = $headers['content-type'][0] ?? null;
        } catch (\Throwable) {
        }

        // Try to parse response body for structured error fields.
        // If body is not JSON, include a truncated preview.
        try {
            $body = $response->getContent(false);
        } catch (\Throwable) {
            return $diag;
        }

        $data = json_decode($body, true);

        if (null !== $data) {
            if (isset($data['error']) && \is_array($data['error'])) {
                $error = $data['error'];
                $diag['response_error_code'] = isset($error['code']) && '' !== $error['code'] ? $error['code'] : null;
                $diag['response_error_type'] = $error['type'] ?? null;
                $diag['response_error_param'] = $error['param'] ?? null;
                $diag['response_error_message'] = mb_substr($error['message'] ?? '', 0, 500);
            } elseif (\is_string($data['error'] ?? null)) {
                // Alternative: {"error": "message string"}
                $diag['response_error_message'] = mb_substr($data['error'], 0, 500);
            } elseif (isset($data['error_description'])) {
                $diag['response_error_message'] = mb_substr($data['error_description'], 0, 500);
            }
        } else {
            // Non-JSON body — include truncated preview
            $preview = trim(preg_replace('/\s+/', ' ', $body));
            $diag['response_body_preview'] = mb_substr($preview, 0, 500);
        }

        return $diag;
    }

    /**
     * @param list<DeltaInterface> $deltas
     * @param array<string, mixed> $requestSummary Privacy-safe request summary
     */
    private function errorResult(array $deltas, \Throwable $exception, DeferredResult $deferredResult, string $modelName = '', array $requestSummary = []): PlatformInvocationResult
    {
        $error = [
            'type' => $exception::class,
            'message' => mb_substr($exception->getMessage(), 0, 500),
        ];

        // Include response diagnostics in the error array for downstream logging.
        $responseDiag = $this->extractResponseDiagnostics($deferredResult);
        foreach ($responseDiag as $key => $value) {
            if (null !== $value) {
                $error[$key] = $value;
            }
        }

        // Include request summary.
        foreach ($requestSummary as $key => $value) {
            $error['request_'.$key] = $value;
        }

        // Classify the error with retryability, category, and sanitized user message.
        $error = $this->errorClassifier->classify($error);

        return new PlatformInvocationResult(
            assistantMessage: $this->buildAssistantMessage($deltas),
            deltas: $deltas,
            usage: $this->extractUsage($deferredResult, $modelName),
            stopReason: 'error',
            error: $error,
        );
    }

    private function abortConnection(DeferredResult $deferredResult): void
    {
        try {
            $rawResult = $deferredResult->getRawResult();
            if ($rawResult instanceof RawHttpResult) {
                $rawResult->getObject()->cancel();
            }
        } catch (\Throwable $e) {
            // Connection cleanup failures following stream abort are
            // expected for already-closed or unestablished connections.
            // Log at debug level since this is normal cleanup noise.
            $this->logger->debug('HTTP connection abort threw (expected for already-closed connections)', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param list<DeltaInterface> $deltas
     */
    private function buildAssistantMessage(array $deltas): ?AssistantMessage
    {
        $text = '';
        $thinking = '';
        $thinkingSignature = null;
        $completedToolCalls = null;

        /** @var array<string, array{name: string, partial_json: string, order_index: int}> $partialToolCalls */
        $partialToolCalls = [];
        $toolOrderCursor = 0;

        foreach ($deltas as $delta) {
            match (true) {
                $delta instanceof TextDelta => $text .= $delta->getText(),
                $delta instanceof ThinkingDelta => $thinking .= $delta->getThinking(),
                $delta instanceof ThinkingSignature => $thinkingSignature = $delta->getSignature(),
                $delta instanceof ThinkingComplete => [$thinking, $thinkingSignature] = [
                    $delta->getThinking(),
                    $delta->getSignature() ?? $thinkingSignature,
                ],
                $delta instanceof ToolCallStart => $partialToolCalls[$delta->getId()] ??= [
                    'name' => $delta->getName(),
                    'partial_json' => '',
                    'order_index' => $toolOrderCursor++,
                ],
                $delta instanceof ToolInputDelta => $partialToolCalls[$delta->getId()] = [
                    'name' => $delta->getName(),
                    'partial_json' => ($partialToolCalls[$delta->getId()]['partial_json'] ?? '').$delta->getPartialJson(),
                    'order_index' => $partialToolCalls[$delta->getId()]['order_index'] ?? $toolOrderCursor++,
                ],
                $delta instanceof ToolCallComplete => $completedToolCalls = $delta->getToolCalls(),
                default => null,
            };
        }

        $toolCalls = $completedToolCalls ?? $this->buildPartialToolCalls($partialToolCalls);

        if ('' === $text && [] === $toolCalls && '' === $thinking && null === $thinkingSignature) {
            return null;
        }

        /** @var ContentInterface[] $contentParts */
        $contentParts = [];

        if ('' !== $text) {
            $contentParts[] = new Text($text);
        }

        if ('' !== $thinking || null !== $thinkingSignature) {
            $contentParts[] = new Thinking(
                content: $thinking,
                signature: $thinkingSignature,
            );
        }

        foreach ($toolCalls as $toolCall) {
            $contentParts[] = $toolCall;
        }

        return new AssistantMessage(...$contentParts);
    }

    private function resolveStopReason(?AssistantMessage $assistantMessage): ?string
    {
        if ($assistantMessage?->hasToolCalls()) {
            return 'tool_call';
        }

        return null;
    }

    /**
     * @param array<string, array{name: string, partial_json: string, order_index: int}> $partialToolCalls
     *
     * @return list<ToolCall>
     */
    private function buildPartialToolCalls(array $partialToolCalls): array
    {
        uasort(
            $partialToolCalls,
            static fn (array $left, array $right): int => $left['order_index'] <=> $right['order_index'],
        );

        $toolCalls = [];
        foreach ($partialToolCalls as $toolCallId => $toolCall) {
            $toolCalls[] = new ToolCall(
                $toolCallId,
                $toolCall['name'],
                $this->parseArguments($toolCall['partial_json']),
            );
        }

        return $toolCalls;
    }

    /**
     * @return array<string, int|float>
     */
    private function extractUsage(DeferredResult $deferredResult, string $modelName = ''): array
    {
        $tokenUsage = $deferredResult->getMetadata()->get('token_usage');

        if (!$tokenUsage instanceof TokenUsageInterface) {
            return [];
        }

        $usage = array_filter([
            'input_tokens' => $tokenUsage->getPromptTokens(),
            'output_tokens' => $tokenUsage->getCompletionTokens(),
            'thinking_tokens' => $tokenUsage->getThinkingTokens(),
            'tool_tokens' => $tokenUsage->getToolTokens(),
            'cached_tokens' => $tokenUsage->getCachedTokens(),
            'total_tokens' => $tokenUsage->getTotalTokens(),
        ], static fn (mixed $value): bool => null !== $value);

        // Compute cost from model pricing and token usage.
        // Cost flows through LlmStepResult → events → RuntimeEventTranslator
        // → RuntimeEvent → UsageProjection::accumulate() → TUI footer.
        if ('' !== $modelName && null !== $this->costCalculator) {
            $cost = $this->costCalculator->calculateCost($modelName, $usage);
            if (0.0 !== $cost) {
                $usage['cost'] = $cost;
            }
        }

        return $usage;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseArguments(string $json): array
    {
        if ('' === $json) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function notifyStreamStart(string $runId, ?string $stepId): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $this->streamObserver->onStreamStart($runId, $stepId);
        } catch (\Throwable $e) {
            // Observer failures must not break model invocation.
            // This is intentional diagnostic local degradation:
            // the observer is an optional side-channel and its
            // failure should not abort the LLM request.
            $this->logger->warning('LlmStreamObserver::onStreamStart threw', [
                'run_id' => $runId,
                'step_id' => $stepId,
                'exception' => $e,
            ]);
        }
    }

    private function notifyDelta(string $runId, ?string $stepId, DeltaInterface $delta): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $this->streamObserver->onDelta($runId, $stepId, $delta);
        } catch (\Throwable $e) {
            // Observer failures must not break model invocation.
            // Intentional diagnostic local degradation — optional side-channel.
            $this->logger->warning('LlmStreamObserver::onDelta threw', [
                'run_id' => $runId,
                'step_id' => $stepId,
                'delta_class' => $delta::class,
                'exception' => $e,
            ]);
        }
    }

    private function notifyStreamEnd(string $runId, ?string $stepId): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $this->streamObserver->onStreamEnd($runId, $stepId);
        } catch (\Throwable $e) {
            // Observer failures must not break model invocation.
            // Intentional diagnostic local degradation — optional side-channel.
            $this->logger->warning('LlmStreamObserver::onStreamEnd threw', [
                'run_id' => $runId,
                'step_id' => $stepId,
                'exception' => $e,
            ]);
        }
    }

    private function notifyStreamError(string $runId, ?string $stepId, \Throwable $error): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $this->streamObserver->onStreamError($runId, $stepId, $error);
        } catch (\Throwable $e) {
            // Observer failures must not break model invocation.
            // Intentional diagnostic local degradation — optional side-channel.
            $this->logger->warning('LlmStreamObserver::onStreamError threw', [
                'run_id' => $runId,
                'step_id' => $stepId,
                'observer_exception' => $e,
                'original_error' => $error,
            ]);
        }
    }
}
