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
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\CostCalculatorInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\Platform\Result\CancellableRawResultInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
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
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final readonly class LlmPlatformAdapter implements PlatformInterface
{
    /**
     * @param iterable<TransformContextHookInterface> $transformContextHooks
     * @param iterable<ConvertToLlmHookInterface>     $convertToLlmHooks
     */
    public function __construct(
        private RunOperationalStatusReaderInterface $statusReader,
        private AgentMessageConverter $messageConverter,
        private DynamicToolDescriptionProcessor $toolDescriptionProcessor,
        private SymfonyPlatformInterface $platform,
        private iterable $transformContextHooks,
        private iterable $convertToLlmHooks,
        private ?LlmStreamObserverInterface $streamObserver,
        private ?CostCalculatorInterface $costCalculator,
        private LoggerInterface $logger,
        private DenormalizerInterface $denormalizer,
        private ?ModelResolverInterface $modelResolver = null,
        private readonly ProviderRequestPreparer $providerRequestPreparer = new ProviderRequestPreparer(),
        private readonly LlmProviderErrorClassifier $errorClassifier = new LlmProviderErrorClassifier(),
        private readonly AgentMessageToolCallSequenceValidator $toolCallSequenceValidator = new AgentMessageToolCallSequenceValidator(),
    ) {
    }

    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
    {
        $cancelToken = $this->cancellationToken($request);
        $messages = $this->resolveContextMessages($request->input);
        $preTransformIds = $this->extractNotificationIds($messages);
        $messages = $this->applyTransformHooks($messages, $cancelToken, $request->input->runId);
        $modelNotifications = $this->extractNewModelNotifications($messages, $preTransformIds);

        // Preflight invariant: validate tool-call/tool-result sequence
        // before any provider call.  An assistant message with tool_calls
        // must be immediately followed by matching tool result messages,
        // or the provider will reject the request or produce orphaned
        // tool_call blocks in resumed runs.  This fails loudly — no
        // silent filtering.
        $this->toolCallSequenceValidator->validate($messages);

        $providerOptions = $this->buildProviderOptions($request);

        // Resolve ordinary turns from current session metadata at the provider
        // boundary. RunState is historical and must not be an execution override.
        $resolvedModel = null !== $this->modelResolver
            ? $this->modelResolver->resolve(
                defaultModel: $request->model,
                messages: $this->messageConverter->toMessageBag($messages),
                input: $request->input,
                options: new ModelResolutionOptions($request->options->extraOptions),
            )
            : new \Ineersa\AgentCore\Domain\Model\ResolvedModel($request->model);

        $messageBag = $this->applyConvertHooks($messages, $cancelToken, $resolvedModel->model);

        $input = new Input($resolvedModel->model, $messageBag, $providerOptions);
        $this->toolDescriptionProcessor->processInput($input, $request->input);

        // Build a privacy-safe request summary for error diagnostics.
        // This is included in the error array when the request fails.
        $inputOptions = $input->getOptions();
        $availableToolsSnapshot = $this->captureAvailableToolsSnapshot($inputOptions);
        $requestSummary = [
            'model' => $resolvedModel->model,
            'reasoning' => $resolvedModel->reasoning,
            'input_count' => \count($messageBag->withoutSystemMessage()->getMessages()),
            'has_instructions' => null !== $messageBag->getSystemMessage(),
            'has_tools' => isset($inputOptions['tools']),
            'tool_count' => \is_array($inputOptions['tools'] ?? null) ? \count($inputOptions['tools']) : 0,
        ];

        $effectiveModel = $resolvedModel->model;

        // Provider transport can fail synchronously during invoke (e.g. Codex WS
        // send_failure before asStream()). Classify here so bounded LLM retry sees
        // a retryable PlatformInvocationResult instead of a generic worker exception.
        try {
            $platform = new PreparedInvocationPlatform(
                $this->platform,
                $this->providerRequestPreparer,
                $resolvedModel,
                $request->input,
                $cancelToken,
            );
            $deferredResult = $platform->invoke(
                $input->getModel(),
                $input->getMessageBag(),
                array_replace($inputOptions, ['stream' => true]),
            );
        } catch (\Throwable $exception) {
            return $this->errorResult(
                deltas: [],
                exception: $exception,
                deferredResult: null,
                modelName: $effectiveModel,
                requestSummary: $requestSummary,
                modelNotifications: $modelNotifications,
                availableTools: $availableToolsSnapshot['tools'],
                availableToolsSchemaTokensEstimate: $availableToolsSnapshot['schema_tokens_estimate'],
            );
        }

        return $this->consumeStream(
            $deferredResult,
            $cancelToken,
            $request->input->runId ?? '',
            $request->input->stepId,
            $effectiveModel,
            $requestSummary,
            $modelNotifications,
            $request->options->streamObserverEnabled,
            $availableToolsSnapshot['tools'],
            $availableToolsSnapshot['schema_tokens_estimate'],
        );
    }

    /**
     * Capture the exact final provider-visible tool set after description processing.
     *
     * Privacy-safe: tool names + one aggregate approximate schema token estimate only.
     * Never includes descriptions, schemas, handlers, secrets, or separate MCP server labels.
     * MCP affiliation is conveyed by the model-visible prefixed tool name itself.
     *
     * @param array<string, mixed> $inputOptions
     *
     * @return array{tools: list<string>, schema_tokens_estimate: int}
     */
    private function captureAvailableToolsSnapshot(array $inputOptions): array
    {
        $rawTools = $inputOptions['tools'] ?? null;
        if (!\is_array($rawTools) || [] === $rawTools) {
            return ['tools' => [], 'schema_tokens_estimate' => 0];
        }

        $tools = [];
        $schemaRecords = [];
        foreach ($rawTools as $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }

            $tools[] = $tool->getName();

            $schemaRecords[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters() ?? new \stdClass(),
                ],
            ];
        }

        if ([] === $tools) {
            return ['tools' => [], 'schema_tokens_estimate' => 0];
        }

        $json = json_encode($schemaRecords, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $estimate = false === $json ? 0 : (int) ceil(\strlen($json) / 4);

        return [
            'tools' => $tools,
            'schema_tokens_estimate' => $estimate,
        ];
    }

    /**
     * Execution callers must supply typed messages. Prompt reconstruction is
     * owned by run_control at a lifecycle boundary, never by an I/O worker.
     *
     * @return list<AgentMessage>
     */
    private function resolveContextMessages(ModelInvocationInput $input): array
    {
        return $input->messages ?? [];
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    private function applyTransformHooks(array $messages, CancellationTokenInterface $cancelToken, ?string $runId): array
    {
        $transformed = $messages;

        foreach ($this->transformContextHooks as $hook) {
            $transformed = $hook->transformContext($transformed, $cancelToken, $runId);
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
     * Collect notification IDs from all AgentMessages (pre-transform).
     *
     * Used for deduplication: only notifications whose IDs were NOT
     * present pre-transform are new and should be threaded downstream.
     *
     * @param list<AgentMessage> $messages
     *
     * @return array<string, true>
     */
    private function extractNotificationIds(array $messages): array
    {
        $ids = [];

        foreach ($messages as $message) {
            foreach (ModelNotificationCodec::denormalizeFromDetails($this->denormalizer, $message->details) as $notif) {
                // DTO construction guarantees nonblank id.
                $ids[$notif->id] = true;
            }
        }

        return $ids;
    }

    /**
     * Extract notifications newly added by transform context hooks.
     *
     * Compares post-transform message notification IDs against the
     * pre-transform set. Only notifications whose IDs were NOT already
     * present are collected — this prevents re-emitting primary-path
     * notifications that are already in message history.
     *
     * @param list<AgentMessage>  $messages
     * @param array<string, true> $seenIds  Notification IDs present pre-transform
     *
     * @return list<ModelNotificationDTO>
     */
    private function extractNewModelNotifications(array $messages, array $seenIds): array
    {
        $notifications = [];

        foreach ($messages as $message) {
            foreach (ModelNotificationCodec::denormalizeFromDetails($this->denormalizer, $message->details) as $notif) {
                // DTO construction guarantees nonblank id.
                if (!isset($seenIds[$notif->id])) {
                    $notifications[] = $notif;
                    $seenIds[$notif->id] = true;
                }
            }
        }

        return $notifications;
    }

    /**
     * Build only options that may cross the Symfony AI provider boundary.
     * Tool resolver correlation remains on ModelInvocationInput.
     *
     * When toolsEnabled === false, injects tools:[] to force an empty
     * toolbox regardless of ToolSetResolver or toolbox configuration.
     * This guarantees no-tools mode for compaction/summarization calls.
     *
     * @return array<string, mixed>
     */
    private function buildProviderOptions(ModelInvocationRequest $request): array
    {
        // thinking_level is a Hatfield model-resolution input. Every other
        // extra option is explicitly provider-facing.
        $options = array_diff_key($request->options->extraOptions, ['thinking_level' => true]);

        // Explicit no-tools flag: short-circuit all tool resolution by
        // injecting an empty tool array after generic options are merged.
        // The processor sees tools:[] and clears tool_descriptions,
        // preventing any toolbox/ToolSetResolver fallback.
        if (false === $request->options->toolsEnabled) {
            $options['tools'] = [];
        }

        return $options;
    }

    private function cancellationToken(ModelInvocationRequest $request): CancellationTokenInterface
    {
        if ($request->options->cancelToken instanceof CancellationTokenInterface) {
            return $request->options->cancelToken;
        }

        if (null !== $request->input->runId) {
            return new RunCancellationToken($this->statusReader, $request->input->runId);
        }

        return new NullCancellationToken();
    }

    /**
     * @param array<string, mixed>       $requestSummary     Privacy-safe request summary for error diagnostics
     * @param list<ModelNotificationDTO> $modelNotifications generic model notifications
     *                                                       produced by transform context hooks
     * @param list<string>               $availableTools
     */
    private function consumeStream(
        DeferredResult $deferredResult,
        CancellationTokenInterface $cancelToken,
        string $runId,
        ?string $stepId,
        string $modelName = '',
        array $requestSummary = [],
        array $modelNotifications = [],
        bool $streamObserverEnabled = true,
        array $availableTools = [],
        int $availableToolsSchemaTokensEstimate = 0,
    ): PlatformInvocationResult {
        $aborted = false;
        $deltas = [];

        if ($streamObserverEnabled) {
            $this->notifyStreamStart($runId, $stepId);
        }

        try {
            foreach ($deferredResult->asStream() as $delta) {
                if ($cancelToken->isCancellationRequested()) {
                    $aborted = true;
                    break;
                }

                if ($delta instanceof DeltaInterface) {
                    $deltas[] = $delta;
                    if ($streamObserverEnabled) {
                        $this->notifyDelta($runId, $stepId, $delta);
                    }
                }
            }
        } catch (\Throwable $exception) {
            if ($streamObserverEnabled) {
                $this->notifyStreamError($runId, $stepId, $exception);
            }

            $this->logger->warning('llm.provider.stream_error', $this->buildErrorLogContext(
                $exception,
                $runId,
                $stepId,
                $deferredResult,
                $requestSummary,
            ));

            return $this->errorResult(
                $deltas,
                $exception,
                $deferredResult,
                $modelName,
                $requestSummary,
                $modelNotifications,
                $availableTools,
                $availableToolsSchemaTokensEstimate,
            );
        }

        if ($streamObserverEnabled) {
            $this->notifyStreamEnd($runId, $stepId);
        }

        if ($aborted) {
            $this->abortConnection($deferredResult);
        }

        $assistantMessage = $this->buildAssistantMessage($deltas);

        return new PlatformInvocationResult(
            assistantMessage: $assistantMessage,
            deltas: $deltas,
            usage: $this->extractUsage($deferredResult, $modelName),
            stopReason: $aborted ? 'aborted' : $this->resolveStopReason($assistantMessage, $deferredResult),
            error: null,
            model: $modelName,
            reasoning: (string) ($requestSummary['reasoning'] ?? ''),
            modelNotifications: $modelNotifications,
            availableTools: $availableTools,
            availableToolsSchemaTokensEstimate: $availableToolsSchemaTokensEstimate,
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
     * Returns structural HTTP metadata only. Provider-controlled free-text fields
     * and raw response bodies are never copied into diagnostics.
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
        } catch (\Throwable $exception) {
            $this->logDiagnosticFailure('raw_result_get_object', $exception);

            return [];
        }

        $diag = [
            'http_status_code' => null,
            'response_content_type' => null,
            'response_body_bytes' => null,
            'response_body_is_json' => null,
            'response_error_code' => null,
            'response_error_type' => null,
            'response_error_param' => null,
        ];

        try {
            $diag['http_status_code'] = $response->getStatusCode();
        } catch (\Throwable $exception) {
            $this->logDiagnosticFailure('status_code', $exception);
        }

        try {
            $headers = $response->getHeaders(false);
            $diag['response_content_type'] = $headers['content-type'][0] ?? null;
        } catch (\Throwable $exception) {
            $this->logDiagnosticFailure('headers', $exception);
        }

        // Try to parse response body for structured error fields.
        // For non-JSON bodies, record only safe metadata — never raw body content.
        try {
            $body = $response->getContent(false);
        } catch (\Throwable $exception) {
            $this->logDiagnosticFailure('body', $exception);

            return $diag;
        }

        $diag['response_body_bytes'] = \strlen($body);
        $data = json_decode($body, true);

        if (null !== $data) {
            $diag['response_body_is_json'] = true;

            if (isset($data['error']) && \is_array($data['error'])) {
                $error = $data['error'];
                $diag['response_error_code'] = isset($error['code']) && '' !== $error['code'] ? $error['code'] : null;
                $diag['response_error_type'] = $error['type'] ?? null;
                $diag['response_error_param'] = $error['param'] ?? null;
            }
        } else {
            // Non-JSON body — never store raw body content.
            // Only safe metadata is recorded.
            $diag['response_body_is_json'] = false;
        }

        return $diag;
    }

    /**
     * Log a swallowed response-diagnostic failure as intentional local
     * degradation.
     *
     * Logs only stable structural metadata — never the exception object or
     * message and never provider-controlled content (raw responses, bodies,
     * headers, prompts, credentials, session content).
     */
    private function logDiagnosticFailure(string $stage, \Throwable $exception): void
    {
        $this->logger->warning('llm.provider.diagnostic_failed', [
            'component' => 'llm_platform',
            'event_type' => 'llm_platform.diagnostic_failed',
            'diagnostic_stage' => $stage,
            'exception_type' => $exception::class,
        ]);
    }

    /**
     * @param list<DeltaInterface>       $deltas
     * @param array<string, mixed>       $requestSummary     Privacy-safe request summary
     * @param list<ModelNotificationDTO> $modelNotifications generic model notifications
     *                                                       from transform context hooks
     * @param list<string>               $availableTools
     */
    private function errorResult(
        array $deltas,
        \Throwable $exception,
        ?DeferredResult $deferredResult,
        string $modelName = '',
        array $requestSummary = [],
        array $modelNotifications = [],
        array $availableTools = [],
        int $availableToolsSchemaTokensEstimate = 0,
    ): PlatformInvocationResult {
        $error = [
            'type' => $exception::class,
            'message' => mb_substr($exception->getMessage(), 0, 500),
        ];

        if ($exception instanceof ServerException && null !== $exception->getStatusCode()) {
            $error['http_status_code'] = $exception->getStatusCode();
        }

        // Include response diagnostics when a DeferredResult exists (stream-time failures).
        // Synchronous platform->invoke() failures have no DeferredResult/HTTP body.
        if (null !== $deferredResult) {
            $responseDiag = $this->extractResponseDiagnostics($deferredResult);
            foreach ($responseDiag as $key => $value) {
                if (null !== $value) {
                    $error[$key] = $value;
                }
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
            usage: null !== $deferredResult ? $this->extractUsage($deferredResult, $modelName) : [],
            stopReason: 'error',
            error: $error,
            model: $modelName,
            reasoning: (string) ($requestSummary['reasoning'] ?? ''),
            modelNotifications: $modelNotifications,
            availableTools: $availableTools,
            availableToolsSchemaTokensEstimate: $availableToolsSchemaTokensEstimate,
        );
    }

    private function abortConnection(DeferredResult $deferredResult): void
    {
        try {
            $rawResult = $deferredResult->getRawResult();
            if ($rawResult instanceof CancellableRawResultInterface) {
                $rawResult->abort();
            } elseif ($rawResult instanceof RawHttpResult) {
                $rawResult->getObject()->cancel();
            }
        } catch (\Throwable $e) {
            // Connection cleanup failures following stream abort are
            // expected for already-closed or unestablished connections.
            // Log at info level: cleanup exceptions are expected for already-closed/unestablished connections but are kept visible for diagnostics.
            $this->logger->info('HTTP connection abort threw (expected for already-closed connections)', [
                'exception' => $e,
                'component' => 'llm_platform',
                'event_type' => 'llm_platform.abort_connection_exception',
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

    private function resolveStopReason(?AssistantMessage $assistantMessage, DeferredResult $deferredResult): ?string
    {
        if ($assistantMessage?->hasToolCalls()) {
            return 'tool_call';
        }

        $finishReason = $deferredResult->getMetadata()->get('finish_reason');
        if (!$finishReason instanceof FinishReason) {
            return null;
        }

        return match ($finishReason->getCase()) {
            FinishReasonCase::STOP => 'stop',
            FinishReasonCase::LENGTH => 'length',
            FinishReasonCase::TOOL_CALL => 'tool_call',
            FinishReasonCase::CONTENT_FILTER => 'content_filter',
            FinishReasonCase::STOP_SEQUENCE => $finishReason->getRaw(),
            FinishReasonCase::OTHER => $finishReason->getRaw(),
        };
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
                $this->parseArguments($toolCall['partial_json'], (string) $toolCallId),
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

        // Cache-read tokens: the primary signal for the TUI footer's
        // cache-hit percentage.  For providers that only report an
        // aggregate cached-tokens count (getCachedTokens) without
        // splitting read vs creation, treat the aggregate as cache-read.
        // Explicit cache-read telemetry takes precedence when available.
        $cacheReadTokens = $tokenUsage->getCacheReadTokens()
            ?? $tokenUsage->getCachedTokens();

        $usage = array_filter([
            'input_tokens' => $tokenUsage->getPromptTokens(),
            'output_tokens' => $tokenUsage->getCompletionTokens(),
            'thinking_tokens' => $tokenUsage->getThinkingTokens(),
            'tool_tokens' => $tokenUsage->getToolTokens(),
            'cached_tokens' => $tokenUsage->getCachedTokens(),
            'cache_read_tokens' => $cacheReadTokens,
            'cache_creation_tokens' => $tokenUsage->getCacheCreationTokens(),
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
    private function parseArguments(string $json, string $toolCallId = ''): array
    {
        if ('' === $json) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning('LLM tool-call arguments JSON decode failed — using empty arguments.', [
                'component' => 'llm_platform_adapter',
                'event_type' => 'llm.tool_call_args_decode_failed',
                'tool_call_id' => $toolCallId,
                'error_class' => $exception::class,
            ]);

            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function notifyStreamStart(string $runId, ?string $stepId): void
    {
        $this->notifyObserver(
            $runId,
            $stepId,
            'LlmStreamObserver::onStreamStart threw',
            fn () => $this->streamObserver->onStreamStart($runId, $stepId),
            static fn (\Throwable $e): array => ['exception' => $e],
        );
    }

    private function notifyDelta(string $runId, ?string $stepId, DeltaInterface $delta): void
    {
        $this->notifyObserver(
            $runId,
            $stepId,
            'LlmStreamObserver::onDelta threw',
            fn () => $this->streamObserver->onDelta($runId, $stepId, $delta),
            static fn (\Throwable $e): array => ['delta_class' => $delta::class, 'exception' => $e],
        );
    }

    private function notifyStreamEnd(string $runId, ?string $stepId): void
    {
        $this->notifyObserver(
            $runId,
            $stepId,
            'LlmStreamObserver::onStreamEnd threw',
            fn () => $this->streamObserver->onStreamEnd($runId, $stepId),
            static fn (\Throwable $e): array => ['exception' => $e],
        );
    }

    private function notifyStreamError(string $runId, ?string $stepId, \Throwable $error): void
    {
        $this->notifyObserver(
            $runId,
            $stepId,
            'LlmStreamObserver::onStreamError threw',
            fn () => $this->streamObserver->onStreamError($runId, $stepId, $error),
            static fn (\Throwable $e): array => ['observer_exception' => $e, 'original_error' => $error],
        );
    }

    /**
     * Guard and invoke one stream-observer callback, isolating observer
     * failures from model invocation.
     *
     * Observer failures must not break model invocation: this is intentional
     * diagnostic local degradation — the observer is an optional side-channel
     * and its failure should not abort the LLM request. Empty run IDs and a
     * missing observer are suppressed before the callback runs.
     *
     * @param callable(): void                           $callback
     * @param callable(\Throwable): array<string, mixed> $failureContext builds the warning context for the thrown observer exception
     */
    private function notifyObserver(string $runId, ?string $stepId, string $warningMessage, callable $callback, callable $failureContext): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $callback();
        } catch (\Throwable $e) {
            $this->logger->warning($warningMessage, [
                'run_id' => $runId,
                'step_id' => $stepId,
                ...$failureContext($e),
            ]);
        }
    }
}
