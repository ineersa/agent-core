<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Generic;

use Symfony\AI\Platform\Bridge\Generic\Completions\CompletionsConversionTrait;
use Symfony\AI\Platform\Bridge\Generic\Completions\FinishReasonMapper;
use Symfony\AI\Platform\Bridge\Generic\Completions\ResultConverter;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * OpenAI-compatible completions ResultConverter with durable streaming tool-call conversion.
 *
 * Extends the Symfony Generic {@see ResultConverter} and replaces only the
 * streaming conversion method ({@see convertStream}) with a dual-map
 * (stream index + tool-call id) implementation that handles sparse,
 * out-of-order, empty-id, and phantom tool-call chunks correctly.
 *
 * Key improvements over the vendor trait:
 *
 *  1. Dual-map tracking (by stream index AND tool-call id) so chunks
 *     that arrive at the same index but reference different tools are
 *     correctly associated, and argument-only chunks at an index that
 *     never received an ID chunk are buffered instead of yielding
 *     empty‑id deltas.
 *
 *  2. Empty‑id suppression: ToolCallStart and ToolInputDelta are not
 *     yielded when the tool‑call id is empty.  Argument chunks without
 *     an id are accumulated silently; when an id‑bearing chunk arrives
 *     at the same index, the accumulated arguments are replayed in one
 *     ToolInputDelta.
 *
 *  3. Only blocks with a non‑empty id AND name appear in the final
 *     ToolCallComplete.  Phantom blocks that were started but never
 *     completed, or blocks that accumulated arguments without ever
 *     receiving an id, are excluded from the canonical tool‑call list.
 *
 * Non-stream conversion, HTTP status handling, token usage extraction,
 * and finish-reason metadata follow Symfony AI Generic v0.11.
 *
 * @internal
 *
 * @phpstan-type StreamEvent = 'capture_start'|'raw_chunk'|'converted_delta'|'capture_end'
 * @phpstan-type StreamListener = \Closure(string $event, int $ordinal, array<string, mixed> $context): void
 */
final class DurableResultConverter extends ResultConverter
{
    use CompletionsConversionTrait {
        isRateLimitError as private vendorIsRateLimitError;
        isServerError as private vendorIsServerError;
    }

    /**
     * @param \Closure(string, int, array<string, mixed>): void|null $onStreamEvent Optional listener for debug capture.
     *                                                                              Called with (event name, chunk ordinal, context array) for each raw chunk
     *                                                                              and converted delta. Null when capture is not configured.
     */
    public function __construct(
        private readonly ?\Closure $onStreamEvent = null,
    ) {
    }

    /**
     * Route streaming through the durable converter; delegate non-stream paths to Generic v0.11.
     */
    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        $response = $result->getObject();

        if (401 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? 'Authentication failed.';
            throw new AuthenticationException($errorMessage);
        }

        if (400 === $response->getStatusCode()) {
            $error = json_decode($response->getContent(false), true)['error'] ?? [];
            $errorMessage = $error['message'] ?? 'Bad Request';

            if ('context_length_exceeded' === ($error['code'] ?? null) || preg_match('/context[_ ]length[_ ]exceeded/i', $errorMessage)) {
                throw new ExceedContextSizeException($errorMessage);
            }

            throw new BadRequestException($errorMessage);
        }

        if (429 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? null;
            throw new RateLimitExceededException(null, $errorMessage);
        }

        if (($code = $response->getStatusCode()) >= 500) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? null;
            throw new ServerException($code, $errorMessage);
        }

        if (true === ($options['stream'] ?? false)) {
            if (($code = $response->getStatusCode()) >= 400) {
                throw new RuntimeException(\sprintf('Unexpected response code %d: "%s"', $code, $response->getContent(false)));
            }

            return new StreamResult($this->convertStream($result));
        }

        return parent::convert($result, $options);
    }

    /**
     * Durable streaming conversion — replaces the trait's convertStream().
     *
     * @return \Generator<TextDelta|ThinkingDelta|ThinkingComplete|ToolCallStart|ToolInputDelta|ToolCallComplete|TokenUsage|MetadataDelta>
     */
    protected function convertStream(RawResultInterface $result): \Generator
    {
        /** @var array<int, array{id: string, name: string, partialJson: string, index: int}> $blocks stable key → block data */
        $blocks = [];
        /** @var array<int, int> $blockByIndex stream index → stable key */
        $blockByIndex = [];
        /** @var array<non-empty-string, int> $blockById tool-call id → stable key */
        $blockById = [];
        $nextStableKey = 0;

        $reasoning = '';
        $sawChunk = false;
        $finishReason = null;
        $chunkOrdinal = 0;
        $alreadyEmittedEnd = false;

        $this->emit('capture_start', -1, []);

        try {
            foreach ($result->getDataStream() as $data) {
                $this->emit('raw_chunk', $chunkOrdinal, ['data' => $data]);

                if (isset($data['error'])) {
                    $message = \is_array($data['error']) ? ($data['error']['message'] ?? 'Unknown error') : (string) $data['error'];
                    $code = \is_array($data['error']) ? ($data['error']['code'] ?? null) : null;
                    $type = \is_array($data['error']) ? ($data['error']['type'] ?? null) : null;
                    $errorMessage = \sprintf('Stream error: "%s".', $message);

                    if ($this->vendorIsRateLimitError($code, $type)) {
                        throw new RateLimitExceededException(null, $errorMessage);
                    }

                    if ($this->vendorIsServerError($code, $type)) {
                        throw new ServerException(null, $errorMessage);
                    }

                    throw new RuntimeException($errorMessage);
                }

                $sawChunk = true;

                if (null !== ($data['choices'][0]['finish_reason'] ?? null)) {
                    $finishReason ??= FinishReasonMapper::map($data['choices'][0]['finish_reason']);
                }

                if (isset($data['usage'])) {
                    $usage = $this->convertStreamUsage($data['usage']);
                    $this->emit('converted_delta', $chunkOrdinal, [
                        'type' => 'TokenUsage',
                        'input_tokens' => $usage->getPromptTokens(),
                        'output_tokens' => $usage->getCompletionTokens(),
                        'total_tokens' => $usage->getTotalTokens(),
                    ]);
                    yield $usage;
                }

                $toolCallDeltas = $data['choices'][0]['delta']['tool_calls'] ?? null;
                if (\is_array($toolCallDeltas)) {
                    yield from $this->yieldDurableToolCallDeltas($blocks, $blockByIndex, $blockById, $data, $chunkOrdinal);
                    $this->accumulateDurableToolCalls($blocks, $blockByIndex, $blockById, $nextStableKey, $data);
                }

                if ([] !== $blocks && $this->isToolCallsStreamFinished($data)) {
                    $delta = new ToolCallComplete($this->buildDurableFinalToolCalls($blocks));
                    $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                    yield $delta;
                }

                $reasoningContent = $data['choices'][0]['delta']['reasoning_content']
                    ?? $data['choices'][0]['delta']['reasoning'] ?? null;
                if (null !== $reasoningContent && '' !== $reasoningContent) {
                    $reasoning .= $reasoningContent;
                    $delta = new ThinkingDelta($reasoningContent);
                    $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                    yield $delta;
                }

                if ('' !== $reasoning && isset($data['choices'][0]['delta']['content']) && '' !== $data['choices'][0]['delta']['content']) {
                    $delta = new ThinkingComplete($reasoning);
                    $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                    yield $delta;
                    $reasoning = '';
                }

                if (!isset($data['choices'][0]['delta']['content'])) {
                    ++$chunkOrdinal;
                    continue;
                }

                $delta = new TextDelta($data['choices'][0]['delta']['content']);
                $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                yield $delta;

                ++$chunkOrdinal;
            }

            if ('' !== $reasoning) {
                $delta = new ThinkingComplete($reasoning);
                $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                yield $delta;
            }

            if ($sawChunk && null === $finishReason) {
                $this->emit('capture_end', -1, ['stop_reason' => 'incomplete']);
                $alreadyEmittedEnd = true;
                throw new IncompleteStreamException('Completions stream ended before a finish reason was received.');
            }

            if (null !== $finishReason) {
                $delta = new MetadataDelta('finish_reason', $finishReason);
                $this->emit('converted_delta', $chunkOrdinal, ['type' => 'MetadataDelta', 'key' => 'finish_reason']);
                yield $delta;
            }

            $this->emit('capture_end', -1, ['stop_reason' => $finishReason?->getRaw() ?? null]);
        } catch (\Throwable $e) {
            if (!$alreadyEmittedEnd) {
                $this->emit('capture_end', -1, ['stop_reason' => 'error']);
            }
            throw $e;
        }
    }

    /**
     * @param array<int, array{id: string, name: string, partialJson: string, index: int}> $blocks
     * @param array<int, int>                                                              $blockByIndex
     * @param array<non-empty-string, int>                                                 $blockById
     * @param array<string, mixed>                                                         $data
     * @param int                                                                          $chunkOrdinal Ordinal of the raw chunk producing these deltas
     *
     * @return \Generator<ToolCallStart|ToolInputDelta>
     */
    private function yieldDurableToolCallDeltas(
        array &$blocks,
        array &$blockByIndex,
        array &$blockById,
        array $data,
        int $chunkOrdinal,
    ): \Generator {
        foreach ($data['choices'][0]['delta']['tool_calls'] ?? [] as $chunk) {
            $index = $chunk['index'] ?? 0;

            if (isset($chunk['id'])) {
                $stableKey = $this->resolveBlockKey($blocks, $blockByIndex, $blockById, $index, $chunk['id'], $chunk['function']['name'] ?? '');

                if ('' !== $chunk['id']) {
                    $delta = new ToolCallStart($chunk['id'], $chunk['function']['name'] ?? '');
                    $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                    yield $delta;
                }

                // Arguments may arrive before the id-bearing chunk at this index;
                // replay the buffered JSON once we can address a non-empty tool-call id.
                $bufferedArgs = $blocks[$stableKey]['partialJson'] ?? '';
                if ('' !== $bufferedArgs && '' !== $chunk['id']) {
                    $delta = new ToolInputDelta($chunk['id'], $chunk['function']['name'] ?? '', $bufferedArgs);
                    $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                    yield $delta;
                }
            } elseif (isset($chunk['function']['arguments'])) {
                $stableKey = $blockByIndex[$index] ?? null;

                if (null !== $stableKey && isset($blocks[$stableKey])) {
                    $id = $blocks[$stableKey]['id'];

                    if ('' !== $id) {
                        $delta = new ToolInputDelta($id, $blocks[$stableKey]['name'], $chunk['function']['arguments']);
                        $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                        yield $delta;
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array{id: string, name: string, partialJson: string, index: int}> $blocks
     * @param array<int, int>                                                              $blockByIndex
     * @param array<non-empty-string, int>                                                 $blockById
     * @param array<string, mixed>                                                         $data
     */
    private function accumulateDurableToolCalls(
        array &$blocks,
        array &$blockByIndex,
        array &$blockById,
        int &$nextStableKey,
        array $data,
    ): void {
        foreach ($data['choices'][0]['delta']['tool_calls'] ?? [] as $chunk) {
            $index = $chunk['index'] ?? 0;

            if (isset($chunk['id'])) {
                $stableKey = $this->resolveBlockKey($blocks, $blockByIndex, $blockById, $index, $chunk['id'], $chunk['function']['name'] ?? '');

                if (isset($chunk['function']['arguments'])) {
                    $blocks[$stableKey]['partialJson'] .= $chunk['function']['arguments'];
                }
            } elseif (isset($chunk['function']['arguments'])) {
                $stableKey = $blockByIndex[$index] ?? null;

                if (null !== $stableKey && isset($blocks[$stableKey])) {
                    $blocks[$stableKey]['partialJson'] .= $chunk['function']['arguments'];
                } else {
                    // Orphan argument chunk: no id yet — hold in a placeholder block
                    // so phantom/incomplete tool calls never surface in ToolCallComplete.
                    $stableKey = $nextStableKey++;
                    $blocks[$stableKey] = [
                        'id' => '',
                        'name' => '',
                        'partialJson' => $chunk['function']['arguments'],
                        'index' => $index,
                    ];
                    $blockByIndex[$index] = $stableKey;
                }
            }
        }
    }

    /**
     * @param array<int, array{id: string, name: string, partialJson: string, index: int}> $blocks
     * @param array<int, int>                                                              $blockByIndex
     * @param array<non-empty-string, int>                                                 $blockById
     *
     * @return int stable block key
     */
    private function resolveBlockKey(
        array &$blocks,
        array &$blockByIndex,
        array &$blockById,
        int $index,
        string $id,
        string $name,
    ): int {
        if ('' !== $id && isset($blockById[$id])) {
            $stableKey = $blockById[$id];
            $blockByIndex[$index] = $stableKey;
            $blocks[$stableKey]['index'] = $index;

            return $stableKey;
        }

        $existingKey = $blockByIndex[$index] ?? null;
        if (null !== $existingKey && isset($blocks[$existingKey])) {
            $existingId = $blocks[$existingKey]['id'];

            if ('' !== $id && '' !== $existingId && $id !== $existingId) {
                // New tool call at a reused index — create a fresh block below.
            } else {
                $blocks[$existingKey]['name'] = $name;
                $blocks[$existingKey]['index'] = $index;
                if ('' !== $id) {
                    $blocks[$existingKey]['id'] = $id;
                    $blockById[$id] = $existingKey;
                }

                return $existingKey;
            }
        }

        $stableKey = $this->nextBlockKey($blocks);

        $blocks[$stableKey] = [
            'id' => $id,
            'name' => $name,
            'partialJson' => '',
            'index' => $index,
        ];

        if ('' !== $id) {
            $blockById[$id] = $stableKey;
        }
        $blockByIndex[$index] = $stableKey;

        return $stableKey;
    }

    /**
     * @param array<int, mixed> $blocks
     */
    private function nextBlockKey(array $blocks): int
    {
        if ([] === $blocks) {
            return 0;
        }

        return max(array_keys($blocks)) + 1;
    }

    /**
     * @param array<int, array{id: string, name: string, partialJson: string, index: int}> $blocks
     *
     * @return list<ToolCall>
     */
    private function buildDurableFinalToolCalls(array $blocks): array
    {
        $toolCalls = [];

        foreach ($blocks as $block) {
            // Exclude phantom blocks: started without id/name or never completed.
            if ('' === $block['id'] || '' === $block['name']) {
                continue;
            }

            if ('' !== $block['partialJson']) {
                try {
                    $arguments = json_decode($block['partialJson'], true, flags: \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $arguments = [];
                }
            } else {
                $arguments = [];
            }

            $toolCalls[] = new ToolCall($block['id'], $block['name'], $arguments);
        }

        return $toolCalls;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emit(string $event, int $ordinal, array $context): void
    {
        if (null === $this->onStreamEvent) {
            return;
        }

        ($this->onStreamEvent)($event, $ordinal, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function deltaContext(object $delta): array
    {
        $ctx = [
            'type' => (new \ReflectionClass($delta))->getShortName(),
        ];

        if (method_exists($delta, 'getId')) {
            $ctx['id'] = $delta->getId();
        }
        if (method_exists($delta, 'getName')) {
            $ctx['name'] = $delta->getName();
        }
        if (method_exists($delta, 'getPartialJson')) {
            $ctx['partial_json'] = $delta->getPartialJson();
        }
        if (method_exists($delta, 'getText')) {
            $ctx['text'] = $delta->getText();
        }
        if (method_exists($delta, 'getContent')) {
            $content = $delta->getContent();
            $ctx['content'] = \is_string($content) ? mb_substr($content, 0, 2000) : $content;
        }
        if (method_exists($delta, 'getToolCalls')) {
            $toolCalls = [];
            foreach ($delta->getToolCalls() as $tc) {
                $toolCalls[] = [
                    'id' => $tc->getId(),
                    'name' => $tc->getName(),
                    'arguments' => $tc->getArguments(),
                ];
            }
            $ctx['tool_calls'] = $toolCalls;
        }

        return $ctx;
    }
}
