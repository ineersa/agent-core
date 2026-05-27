<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
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
        private ?LlmStreamObserverInterface $streamObserver = null,
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

        return $this->consumeStream(
            $this->platform->invoke(
                $input->getModel(),
                $input->getMessageBag(),
                PlatformInvocationMetadata::inject(
                    array_replace($input->getOptions(), ['stream' => true, 'stream_options' => ['include_usage' => true]]),
                    new PlatformInvocationMetadata($request->input, $cancelToken),
                ),
            ),
            $cancelToken,
            $request->input->runId ?? '',
            $request->input->stepId,
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
     * @param string             $modelName Model identifier for capability gating
     */
    private function applyConvertHooks(array $messages, CancellationTokenInterface $cancelToken, string $modelName = ''): MessageBag
    {
        $resolvedMessageBag = null;

        foreach ($this->convertToLlmHooks as $hook) {
            $resolvedMessageBag = $hook->convertToLlm($messages, $cancelToken);
        }

        return $resolvedMessageBag ?? $this->messageConverter->toMessageBag($messages, $modelName);
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

    private function consumeStream(
        DeferredResult $deferredResult,
        CancellationTokenInterface $cancelToken,
        string $runId,
        ?string $stepId,
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

            return $this->errorResult($deltas, $exception, $deferredResult);
        }

        $this->notifyStreamEnd($runId, $stepId);

        if ($aborted) {
            $this->abortConnection($deferredResult);
        }

        $assistantMessage = $this->buildAssistantMessage($deltas);

        return new PlatformInvocationResult(
            assistantMessage: $assistantMessage,
            deltas: $deltas,
            usage: $this->extractUsage($deferredResult),
            stopReason: $aborted ? 'aborted' : $this->resolveStopReason($assistantMessage),
            error: null,
        );
    }

    /**
     * @param list<DeltaInterface> $deltas
     */
    private function errorResult(array $deltas, \Throwable $exception, DeferredResult $deferredResult): PlatformInvocationResult
    {
        return new PlatformInvocationResult(
            assistantMessage: $this->buildAssistantMessage($deltas),
            deltas: $deltas,
            usage: $this->extractUsage($deferredResult),
            stopReason: 'error',
            error: [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        );
    }

    private function abortConnection(DeferredResult $deferredResult): void
    {
        try {
            $rawResult = $deferredResult->getRawResult();
            if ($rawResult instanceof RawHttpResult) {
                $rawResult->getObject()->cancel();
            }
        } catch (\Throwable) {
            // Ignore already-closed or not-yet-established connections.
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
    private function extractUsage(DeferredResult $deferredResult): array
    {
        $tokenUsage = $deferredResult->getMetadata()->get('token_usage');

        if (!$tokenUsage instanceof TokenUsageInterface) {
            return [];
        }

        return array_filter([
            'input_tokens' => $tokenUsage->getPromptTokens(),
            'output_tokens' => $tokenUsage->getCompletionTokens(),
            'thinking_tokens' => $tokenUsage->getThinkingTokens(),
            'tool_tokens' => $tokenUsage->getToolTokens(),
            'cached_tokens' => $tokenUsage->getCachedTokens(),
            'total_tokens' => $tokenUsage->getTotalTokens(),
        ], static fn (mixed $value): bool => null !== $value);
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
        } catch (\Throwable) {
            // Observer failures must not break model invocation.
        }
    }

    private function notifyDelta(string $runId, ?string $stepId, DeltaInterface $delta): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $this->streamObserver->onDelta($runId, $stepId, $delta);
        } catch (\Throwable) {
            // Observer failures must not break model invocation.
        }
    }

    private function notifyStreamEnd(string $runId, ?string $stepId): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $this->streamObserver->onStreamEnd($runId, $stepId);
        } catch (\Throwable) {
            // Observer failures must not break model invocation.
        }
    }

    private function notifyStreamError(string $runId, ?string $stepId, \Throwable $error): void
    {
        if (null === $this->streamObserver || '' === $runId) {
            return;
        }

        try {
            $this->streamObserver->onStreamError($runId, $stepId, $error);
        } catch (\Throwable) {
            // Observer failures must not break model invocation.
        }
    }
}
