<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Generic;

use Symfony\AI\Platform\Bridge\Generic\Completions\CompletionsConversionTrait;
use Symfony\AI\Platform\Bridge\Generic\Completions\ResultConverter;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

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
 * Error/usage/text/reasoning handling is identical to the vendor trait.
 *
 * @internal
 *
 * @phpstan-type StreamEvent = 'capture_start'|'raw_chunk'|'converted_delta'|'capture_end'
 * @phpstan-type StreamListener = \Closure(string $event, int $ordinal, array<string, mixed> $context): void
 */
final class DurableResultConverter extends ResultConverter
{
    use CompletionsConversionTrait;

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
     * Override convert() to route the streaming path to our durable
     * implementation while delegating the non-stream path (error
     * handling, choice conversion) to the parent.
     */
    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        if (true === ($options['stream'] ?? false)) {
            return new StreamResult($this->convertStream($result));
        }

        // Non-stream: delegate to parent for error handling and choice conversion.
        return parent::convert($result, $options);
    }

    public function getTokenUsageExtractor(): TokenUsageExtractorInterface
    {
        return new PromptCacheTokenUsageExtractor();
    }

    /**
     * Durable streaming conversion — replaces the trait's convertStream().
     *
     * Track tool‑call blocks by both stream index and tool‑call id
     * so that sparse/out‑of‑order chunks are correctly reconciled.
     *
     * When an $onStreamEvent closure is configured, each raw chunk and the
     * converted deltas it produces are emitted as structured events for
     * offline inspection.
     *
     * @return \Generator<TextDelta|ThinkingDelta|ThinkingComplete|ToolCallStart|ToolInputDelta|ToolCallComplete|TokenUsage>
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
        $sawFinishReason = false;
        $finishReason = null;
        $chunkOrdinal = 0;
        $alreadyEmittedEnd = false;

        $this->emit('capture_start', -1, []);

        try {
            foreach ($result->getDataStream() as $data) {
                $this->emit('raw_chunk', $chunkOrdinal, ['data' => $data]);

                if (isset($data['error'])) {
                    $message = \is_array($data['error']) ? ($data['error']['message'] ?? 'Unknown error') : (string) $data['error'];
                    throw new RuntimeException(\sprintf('Stream error: "%s".', $message));
                }

                $sawChunk = true;

                if (null !== ($data['choices'][0]['finish_reason'] ?? null)) {
                    $sawFinishReason = true;
                    $finishReason = $data['choices'][0]['finish_reason'];
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

                // Durable tool-call processing
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

            if ($sawChunk && !$sawFinishReason) {
                $this->emit('capture_end', -1, ['stop_reason' => 'incomplete']);
                $alreadyEmittedEnd = true;
                throw new IncompleteStreamException('Completions stream ended before a finish reason was received.');
            }

            $this->emit('capture_end', -1, ['stop_reason' => $finishReason]);
        } catch (\Throwable $e) {
            if (!$alreadyEmittedEnd) {
                $this->emit('capture_end', -1, ['stop_reason' => 'error']);
            }
            throw $e;
        }
    }

    // ── Token usage overrides ───────────────────────────────────────────────

    /**
     * Override streaming usage conversion to use the prompt-cache-aware extractor.
     *
     * {@inheritDoc}
     */
    protected function convertStreamUsage(array $usage): TokenUsage
    {
        return (new PromptCacheTokenUsageExtractor())->extractFromArray($usage);
    }

    /**
     * Yield streaming deltas for the current chunk using dual-map reconciliation.
     *
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
                // ID-bearing chunk: resolve the block using the OpenAI stream index.
                $stableKey = $this->resolveBlockKey($blocks, $blockByIndex, $blockById, $index, $chunk['id'], $chunk['function']['name'] ?? '');

                // Only yield starts for non-empty IDs.
                if ('' !== $chunk['id']) {
                    $delta = new ToolCallStart($chunk['id'], $chunk['function']['name'] ?? '');
                    $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                    yield $delta;
                }

                // Replay any buffered arguments now that we have a stable identity.
                $bufferedArgs = $blocks[$stableKey]['partialJson'] ?? '';
                if ('' !== $bufferedArgs && '' !== $chunk['id']) {
                    $delta = new ToolInputDelta($chunk['id'], $chunk['function']['name'] ?? '', $bufferedArgs);
                    $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                    yield $delta;
                }
            } elseif (isset($chunk['function']['arguments'])) {
                // Argument-only chunk: find the block by the OpenAI stream index.
                $stableKey = $blockByIndex[$index] ?? null;

                if (null !== $stableKey && isset($blocks[$stableKey])) {
                    $id = $blocks[$stableKey]['id'];

                    // Only yield argument deltas for blocks with known non-empty IDs.
                    if ('' !== $id) {
                        $delta = new ToolInputDelta($id, $blocks[$stableKey]['name'], $chunk['function']['arguments']);
                        $this->emit('converted_delta', $chunkOrdinal, $this->deltaContext($delta));
                        yield $delta;
                    }
                    // Arguments on a block without ID are silently buffered
                    // in accumulateDurableToolCalls (called after this method).
                }
                // Arguments at an index without any associated block are
                // suppressed — they are orphan phantom chunks.
            }
        }
    }

    /**
     * Accumulate tool-call state from the current chunk.
     *
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
                // ID-bearing chunk: resolve or create the block, update identity.
                $stableKey = $this->resolveBlockKey($blocks, $blockByIndex, $blockById, $index, $chunk['id'], $chunk['function']['name'] ?? '');

                // A chunk can carry both an id AND arguments (e.g. cross-index
                // re-association or a provider that sends id+args together).
                if (isset($chunk['function']['arguments'])) {
                    $blocks[$stableKey]['partialJson'] .= $chunk['function']['arguments'];
                }
            } elseif (isset($chunk['function']['arguments'])) {
                // Argument-only chunk: append to the block at this index.
                $stableKey = $blockByIndex[$index] ?? null;

                if (null !== $stableKey && isset($blocks[$stableKey])) {
                    $blocks[$stableKey]['partialJson'] .= $chunk['function']['arguments'];
                } else {
                    // No block exists yet for this index — create a placeholder
                    // that will be upgraded when an ID-bearing chunk arrives.
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
     * Resolve or create a stable block key for a given stream index + id.
     *
     * Priority:
     *  1. Look up by id (re-associate cross-index).
     *  2. Look up by index (update existing block at this position).
     *  3. Create a new block.
     *
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
        // 1. Re-associate by id (cross-index merge).
        if ('' !== $id && isset($blockById[$id])) {
            $stableKey = $blockById[$id];
            // Update the index mapping.
            $blockByIndex[$index] = $stableKey;
            $blocks[$stableKey]['index'] = $index;

            return $stableKey;
        }

        // 2. Update existing block at this index — but only when the arriving
        //    id matches the block already at this position or the block is
        //    anonymous (id empty).  A different id at a reused index means a
        //    new tool call arrived under the same stream position, which can
        //    happen with some providers that emit phantom or duplicate calls.
        $existingKey = $blockByIndex[$index] ?? null;
        if (null !== $existingKey && isset($blocks[$existingKey])) {
            $existingId = $blocks[$existingKey]['id'];

            // If the arriving id is non-empty and differs from the existing
            // block's identity, treat this as a new tool call — fall through
            // to path 3 (create new block).
            if ('' !== $id && '' !== $existingId && $id !== $existingId) {
                // New tool call at a reused index — create a fresh block below.
            } else {
                // Same tool call (or anonymous block upgrade).
                $blocks[$existingKey]['name'] = $name;
                $blocks[$existingKey]['index'] = $index;
                if ('' !== $id) {
                    $blocks[$existingKey]['id'] = $id;
                    $blockById[$id] = $existingKey;
                }

                return $existingKey;
            }
        }

        // 3. Create a new block.
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
     * Compute the next available stable block key.
     *
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
     * Build the final ToolCall array for ToolCallComplete.
     *
     * Only includes blocks that have BOTH a non-empty id and name.
     * Phantom blocks (no id, no name, or both empty) are excluded.
     *
     * @param array<int, array{id: string, name: string, partialJson: string, index: int}> $blocks
     *
     * @return list<ToolCall>
     */
    private function buildDurableFinalToolCalls(array $blocks): array
    {
        $toolCalls = [];

        foreach ($blocks as $block) {
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

    // ── Capture helpers ──────────────────────────────────────────────────────

    /**
     * Emit a stream event to the optional listener.
     *
     * @param string               $event   Event name ('capture_start', 'raw_chunk', 'converted_delta', 'capture_end')
     * @param int                  $ordinal Chunk ordinal (-1 for start/end)
     * @param array<string, mixed> $context Event-specific context
     */
    private function emit(string $event, int $ordinal, array $context): void
    {
        if (null === $this->onStreamEvent) {
            return;
        }

        ($this->onStreamEvent)($event, $ordinal, $context);
    }

    /**
     * Build context array from a delta object for emission.
     *
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
