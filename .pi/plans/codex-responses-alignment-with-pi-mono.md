

Now I have all the necessary context. Let me produce the complete implementation specification.

---

# Implementation Specification: fix-codex-null-content-thinking-only-response (GitHub #177)

## Overview

Three problems to fix in the Codex Responses API integration:

1. **Mid-turn stream failures are silent** — `error`, `response.failed`, and `response.incomplete` events during streaming are not handled; the generator just finishes without yielding anything, producing a null assistant message.
2. **Thinking-only responses produce `content:null` HTTP 400** — When the model responds with reasoning but no text, the normalizer emits `{'role':'assistant','type':'message','content':null}` which the Codex API rejects.
3. **Reasoning doesn't round-trip** — The thinking signature (full reasoning item JSON) from the Codex API is not captured, persisted, or re-emitted on subsequent turns.

---

## File 1: `src/Platform/Bridge/OpenAICodex/ResultConverter.php` (314 lines)

### Current `convertStream()` — lines 195–236

```php
    private function convertStream(RawResultInterface $result): \Generator
    {
        $currentThinking = null;

        foreach ($result->getDataStream() as $event) {
            $type = $event['type'] ?? '';

            if (isset($event['response']['usage'])) {
                yield $this->getTokenUsageExtractor()->fromDataArray($event['response']);
            }

            if (str_contains($type, 'output_text') && isset($event['delta'])) {
                yield new TextDelta($event['delta']);
            }

            if ('response.reasoning_summary_text.delta' === $type && isset($event['delta'])) {
                if (null === $currentThinking) {
                    $currentThinking = '';
                    yield new ThinkingStart();
                }
                $currentThinking .= $event['delta'];
                yield new ThinkingDelta($event['delta']);
            }

            if ('response.reasoning_summary_text.done' === $type) {
                yield new ThinkingComplete($currentThinking ?? '');
                $currentThinking = null;
            }

            if (!str_contains($type, 'completed')) {
                continue;
            }

            [$toolCallResult] = $this->extractFunctionCalls($event['response'][self::KEY_OUTPUT] ?? []);

            if (null !== $toolCallResult && 'response.completed' === $type) {
                yield new ToolCallComplete($toolCallResult->getContent());
            }
        }
    }
```

### Current helper `extractFunctionCalls()` — lines 245–261

```php
    /**
     * @param array<OutputMessage|FunctionCall|Thinking> $output
     *
     * @return list<ToolCallResult|array<OutputMessage|Thinking>|null>
     */
    private function extractFunctionCalls(array $output): array
    {
        $functionCalls = [];
        foreach ($output as $key => $item) {
            if ('function_call' === ($item['type'] ?? null)) {
                $functionCalls[] = $item;
                unset($output[$key]);
            }
        }

        $toolCallResult = [] !== $functionCalls ? new ToolCallResult(
            array_map($this->convertFunctionCall(...), $functionCalls)
        ) : null;

        return [$toolCallResult, $output];
    }
```

### Target changes

#### 1A. Add imports for error handling

Add these imports at the top of the file (after line 14, with the other `use` statements):

```php
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
```

#### 1B. Replace `convertStream()` entirely

Replace the entire `convertStream()` method with:

```php
    private function convertStream(RawResultInterface $result): \Generator
    {
        $currentThinking = null;
        $currentThinkingSignature = null;
        /** @var array<string, ToolCall> $toolCalls */
        $toolCalls = [];
        $sawResponseEvent = false;
        $sawResponseCompleted = false;

        foreach ($result->getDataStream() as $event) {
            $type = $event['type'] ?? '';
            $sawResponseEvent = true;

            // Mid-stream error event — throw immediately (silent death regression fix)
            if ('error' === $type) {
                throw new RuntimeException($this->generateErrorMessage($this->extractStreamError($event)));
            }

            // response.failed — the response was rejected by the server (silent death regression fix)
            if ('response.failed' === $type) {
                $response = \is_array($event['response'] ?? null) ? $event['response'] : [];
                throw new RuntimeException($this->generateErrorMessage($this->extractStreamError($response)));
            }

            // response.incomplete — context limit or other truncation
            if ('response.incomplete' === $type) {
                $reason = $event['response']['incomplete_details']['reason'] ?? 'unknown';
                if (!\is_string($reason) || '' === $reason) {
                    $reason = 'unknown';
                }

                // Still yield any partial tool calls accumulated so far before throwing
                if ([] !== $toolCalls) {
                    yield new ToolCallComplete(array_values($toolCalls));
                }

                throw new RuntimeException(\sprintf('Codex stream ended incomplete (%s).', $reason));
            }

            // response.done — normalize to completed (Codex API may use either)
            if ('response.done' === $type) {
                $type = 'response.completed';
                $sawResponseCompleted = true;
            }

            if (isset($event['response']['usage'])) {
                yield $this->getTokenUsageExtractor()->fromDataArray($event['response']);
            }

            if (str_contains($type, 'output_text') && isset($event['delta'])) {
                yield new TextDelta($event['delta']);
            }

            // Reasoning summary delta
            if ('response.reasoning_summary_text.delta' === $type && isset($event['delta'])) {
                if (null === $currentThinking) {
                    $currentThinking = '';
                    yield new ThinkingStart();
                }
                $currentThinking .= $event['delta'];
                yield new ThinkingDelta($event['delta']);
            }

            // Reasoning summary done — emit ThinkingComplete WITH signature if we have one
            if ('response.reasoning_summary_text.done' === $type) {
                yield new ThinkingComplete($currentThinking ?? '', $currentThinkingSignature);
                $currentThinking = null;
                $currentThinkingSignature = null;
            }

            // Thinking signature delta (from response.reasoning_summary_text.delta with signature field)
            if ('response.reasoning_summary_text.delta' === $type && isset($event['signature'])) {
                $currentThinkingSignature = $event['signature'];
                yield new ThinkingSignature($event['signature']);
            }

            // output_item.added — capture reasoning item for signature (encrypted_content)
            if ('response.output_item.added' === $type && \is_array($event['item'] ?? null)) {
                $item = $event['item'];
                if ('reasoning' === ($item['type'] ?? null)) {
                    // The 'item' from added event carries the full reasoning item JSON
                    // including encrypted_content. We store it for later.
                    // Pi-mono: this is the source of truth for the thinking signature.
                    $currentThinkingSignature = json_encode($item, \JSON_THROW_ON_ERROR);
                }
            }

            // output_item.done — collect tool calls incrementally (pi-mono pattern)
            if ('response.output_item.done' === $type && \is_array($event['item'] ?? null)) {
                $item = $event['item'];
                if ('function_call' === ($item['type'] ?? null)) {
                    $toolCall = $this->convertFunctionCall($item);
                    $toolCalls[$toolCall->getId()] = $toolCall;
                } elseif ('reasoning' === ($item['type'] ?? null)) {
                    // Capture the full reasoning item JSON as signature at completion
                    $currentThinkingSignature = json_encode($item, \JSON_THROW_ON_ERROR);
                }
            }

            // response.completed — emit final tool calls
            if ('response.completed' !== $type) {
                continue;
            }

            $sawResponseCompleted = true;
            [$toolCallResult] = $this->extractFunctionCalls($event['response'][self::KEY_OUTPUT] ?? []);

            if (null !== $toolCallResult) {
                yield new ToolCallComplete($toolCallResult->getContent());
            } elseif ([] !== $toolCalls) {
                yield new ToolCallComplete(array_values($toolCalls));
            }
        }

        if ($sawResponseEvent && !$sawResponseCompleted) {
            throw new IncompleteStreamException('Codex stream ended before response.completed.');
        }
    }
```

#### 1C. Add helper methods for error formatting (matching OpenResponses pattern)

Add these private methods to the class (before the closing `}`):

```php
    /**
     * @param array<string, mixed> $event
     *
     * @return array{code?: string|null, type?: string|null, param?: string|null, message?: string|null}
     */
    private function extractStreamError(array $event): array
    {
        if (\is_array($event['error'] ?? null)) {
            $event = $event['error'];
        }

        return [
            'code' => \is_string($event['code'] ?? null) ? $event['code'] : null,
            'type' => \is_string($event['type'] ?? null) && 'error' !== $event['type'] ? $event['type'] : null,
            'param' => \is_string($event['param'] ?? null) ? $event['param'] : null,
            'message' => \is_string($event['message'] ?? null) ? $event['message'] : null,
        ];
    }

    private function generateErrorMessage(array $error): string
    {
        return \sprintf('Error "%s"-%s (%s): "%s".', $error['code'] ?? '-', $error['type'] ?? '-', $error['param'] ?? '-', $error['message'] ?? '-');
    }
```

#### 1D. Stop reason mapping

The Codex `response.completed` event contains a `stop_reason` field. The `LlmPlatformAdapter::resolveStopReason()` currently only checks `hasToolCalls()` and returns `'tool_call'` or `null`. This is **sufficient for the current task** because:
- `tool_calls` → `hasToolCalls()` returns true → `'tool_call'` ✓
- `completed` → no tool calls → `null` (correct)
- `refusal` → text content with refusal message → `null` (acceptable; refusal text is already emitted)
- `max_tokens` → partial output → `null` (acceptable)

No change needed to `resolveStopReason()` for this task. Future improvement: read `stop_reason` from `response.completed` and emit a `StopReason` delta.

#### 1E. Exception class choice

- Use `RuntimeException` for `error`, `response.failed`, and `response.incomplete` events (same as OpenResponses bridge).
- Use `IncompleteStreamException` for streams that end without `response.completed` (same as OpenResponses bridge).
- These are from `vendor/symfony/ai-platform/src/Exception/` which is already imported.

---

## File 2: `src/Platform/Bridge/OpenAICodex/Contract/Message/CodexAssistantMessageNormalizer.php` (62 lines)

### Current code — full file

```php
<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Normalizes AssistantMessages into the Codex Responses API format.
 *
 * Without tool calls: returns {role, type: 'message', content: [{type: 'output_text', text}]}.
 * With tool calls: returns a list of {call_id, name, arguments, type: 'function_call'}.
 *
 * Uses typed output_text format matching Pi's /codex/responses shape.
 */
final class CodexAssistantMessageNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array<string, mixed>|list<array{call_id: string, name: string, arguments: string, type: 'function_call'}>
     *                                                                                                                   A single array when no tool calls, a list when tool calls are present
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if ($data->hasToolCalls()) {
            return $this->normalizer->normalize($data->getToolCalls(), $format, $context);
        }

        $text = '';
        foreach ($data->getContent() as $part) {
            if ($part instanceof Text) {
                $text .= $part->getText();
            }
        }

        return [
            'role' => $data->getRole()->value,
            'type' => 'message',
            'content' => '' === $text ? null : [
                ['type' => 'output_text', 'text' => $text],
            ],
        ];
    }

    protected function supportedDataClass(): string
    {
        return AssistantMessage::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
```

### Target changes

Replace the entire `normalize()` method with:

```php
    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     *     Single array for a message item when text is present.
     *     List of arrays when both reasoning and message items are needed.
     *     Empty list when there is nothing to emit (thinking-only with no signature,
     *     or completely empty assistant message).
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if ($data->hasToolCalls()) {
            return $this->normalizer->normalize($data->getToolCalls(), $format, $context);
        }

        $text = '';
        $thinkingSignature = null;

        foreach ($data->getContent() as $part) {
            if ($part instanceof Text) {
                $text .= $part->getText();
            }

            if ($part instanceof \Symfony\AI\Platform\Message\Content\Thinking) {
                $sig = $part->getSignature();
                if (\is_string($sig) && '' !== $sig) {
                    $thinkingSignature = $sig;
                }
            }
        }

        $output = [];

        // If there is a thinking signature, emit a separate reasoning input item
        // carrying the full reasoning item JSON (encrypted_content).
        if (\is_string($thinkingSignature) && '' !== $thinkingSignature) {
            // The signature IS the full reasoning item JSON (captured from
            // response.output_item.added or response.output_item.done in ResultConverter).
            // Pi-mono sends this as a reasoning input item.
            $output[] = json_decode($thinkingSignature, true, flags: \JSON_THROW_ON_ERROR);
        }

        // Emit a message item only if there is actual text content.
        // When there is no text and no thinking signature, return empty array
        // so MessageBagNormalizer emits nothing (pi-mono: `if (output.length === 0) continue;`).
        if ('' !== $text) {
            $output[] = [
                'role' => $data->getRole()->value,
                'type' => 'message',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ];
        }

        // If we produced items, return them; otherwise return empty array
        // so the caller (MessageBagNormalizer) knows to emit nothing.
        if ([] === $output) {
            return [];
        }

        // Single item: return as-is (compatible with existing MessageBagNormalizer
        // which appends non-array results). Multiple items: return as list for
        // flattening by CodexMessageBagNormalizer.
        return 1 === \count($output) ? $output[0] : $output;
    }
```

Add import at top:
```php
use Symfony\AI\Platform\Message\Content\Thinking;
```

### Key behavioral changes:
1. **Thinking-only with signature**: Returns `[reasoning_item]` (single reasoning item, no message). The CodexMessageBagNormalizer will flatten this into the input array.
2. **Thinking + text with signature**: Returns `[reasoning_item, message_item]` (list of 2 items). CodexMessageBagNormalizer will flatten.
3. **Text only (no thinking)**: Returns single message item (unchanged behavior).
4. **Empty (no text, no signature)**: Returns `[]` (empty array). The CodexMessageBagNormalizer must check for this and skip.
5. **Tool calls**: Unchanged (delegated to normalizer chain).

---

## File 3: `src/Platform/Bridge/OpenAICodex/Contract/CodexContract.php` (61 lines)

### Current code — full file

```php
<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract;

use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexAssistantMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexUserMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\Content\TextNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\MessageBagNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\ToolCallMessageNormalizer;
use Symfony\AI\Platform\Contract;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Codex-specific Contract that produces Responses API request shape.
 *
 * Registers Codex-specific normalizers FIRST so that
 * ModelContractNormalizer instances (which check for CodexModel in context)
 * take priority over the unconditional default normalizers appended by
 * {@see Contract::create()}.
 *
 * Transforms:
 *  - MessageBag → {input: [...], instructions: "..."}
 *  - AssistantMessage → {role, type: 'message', content} or function_call items
 *  - ToolCallMessage → {type: 'function_call_output', call_id, output}
 *  - Tool → flat {type: 'function', name, description, parameters}
 *  - ToolCall (in input) → flat {call_id, name, arguments, type: 'function_call'}
 *
 * Following the OpenResponses bridge pattern.
 */
final class CodexContract extends Contract
{
    /**
     * @param NormalizerInterface[] $normalizers
     */
    public static function create(array $normalizers = []): Contract
    {
        // Use upstream OpenResponses normalizers for standard Responses API
        // shape, with Codex-specific normalizers taking priority where their
        // output differs:
        //   - CodexAssistantMessageNormalizer: typed {type:'output_text', text}
        //   - CodexUserMessageNormalizer: typed {type:'input_text', text} content
        //   - CodexToolNormalizer: adds strict:null to parameters
        //   - CodexToolCallNormalizer: adds id field alongside call_id
        // Upstream normalizers handle MessageBag, ToolCallMessage, and Text
        // content types identically to what Codex needs.
        $codexNormalizers = [
            new MessageBagNormalizer(),
            new CodexAssistantMessageNormalizer(),
            new ToolCallMessageNormalizer(),
            new CodexUserMessageNormalizer(),
            new TextNormalizer(),
            new CodexToolNormalizer(),
            new CodexToolCallNormalizer(),
            ...$normalizers,
        ];

        return parent::create($codexNormalizers);
    }
}
```

### Architectural risk: Can MessageBagNormalizer handle multiple items per message?

**Current situation:** `CodexContract` uses `Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\MessageBagNormalizer` (from `vendor/symfony/ai-open-responses-platform/Contract/Message/MessageBagNormalizer.php`). That normalizer's logic:

```php
foreach ($data->withoutSystemMessage()->getMessages() as $message) {
    $normalized = $this->normalizer->normalize($message, $format, $context);

    if ($message instanceof AssistantMessage && $message->hasToolCalls()) {
        $messages['input'] = array_merge($messages['input'], $normalized);
        continue;
    }

    $messages['input'][] = $normalized;
}
```

**Problem:** For non-tool-call assistant messages, it does `$messages['input'][] = $normalized`. If `$normalized` is an array of 2+ items (reasoning + message), this appends the entire array as a SINGLE item → `input: [[{reasoning...}, {message...}]]` which is wrong.

**Solution:** Replace the OpenResponses `MessageBagNormalizer` with a **Codex-specific `CodexMessageBagNormalizer`** that handles both the tool-call flattening AND the multi-item-per-message case.

### Target changes

#### 3A. Create new file: `src/Platform/Bridge/OpenAICodex/Contract/Message/CodexMessageBagNormalizer.php`

```php
<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Codex-specific MessageBag normalizer.
 *
 * Extends the OpenResponses pattern with two additional behaviors:
 * 1. Flattens multi-item normalizer output (reasoning + message) into the
 *    input array, not as a nested array.
 * 2. Skips messages that normalize to an empty array (thinking-only messages
 *    with no signature produce no input items).
 */
final class CodexMessageBagNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param MessageBag $data
     *
     * @return array{
     *     input: array<string, mixed>,
     *     instructions?: string,
     * }
     *
     * @throws ExceptionInterface
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $messages['input'] = [];

        foreach ($data->withoutSystemMessage()->getMessages() as $message) {
            $normalized = $this->normalizer->normalize($message, $format, $context);

            // Skip empty results (thinking-only messages with no signature)
            if (\is_array($normalized) && [] === $normalized) {
                continue;
            }

            // For assistant messages with tool calls, flatten (same as OpenResponses).
            // For any message that returns an array of items (reasoning + message),
            // also flatten. This handles CodexAssistantMessageNormalizer returning
            // [reasoning_item, message_item] for thinking+text responses.
            if (\is_array($normalized) && [] !== $normalized && isset($normalized[0])) {
                // Array-like (list) with numeric index 0 → flatten items
                $messages['input'] = array_merge($messages['input'], $normalized);
            } elseif (\is_array($normalized)) {
                // Single associative item — append as-is
                $messages['input'][] = $normalized;
            } else {
                // Unexpected type — append as-is (defensive)
                $messages['input'][] = $normalized;
            }
        }

        if ($data->getSystemMessage()) {
            $messages['instructions'] = $data->getSystemMessage()->getContent();
        }

        return $messages;
    }

    protected function supportedDataClass(): string
    {
        return MessageBag::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
```

#### 3B. Update `CodexContract.php`

Replace the import and normalizer:

Change:
```php
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\MessageBagNormalizer;
```
To:
```php
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexMessageBagNormalizer;
```

Change the normalizer list:
```php
        $codexNormalizers = [
            new CodexMessageBagNormalizer(),  // was: new MessageBagNormalizer()
            new CodexAssistantMessageNormalizer(),
```

---

## File 4: `src/Platform/Bridge/OpenAICodex/CodexModelClient.php` (168 lines)

### Current request body builder — lines 64–98

```php
        // Strip Hatfield/Symfony AI internal keys that are not valid Codex API fields.
        $bodyOptions = array_diff_key($options, array_flip(self::INTERNAL_OPTION_KEYS));

        // Merge payload (from contract) over options, with model name last
        // so CodexContract's payload keys (input, instructions) always win
        // over any top-level option keys.
        $jsonBody = array_merge($bodyOptions, ['model' => $model->getName()], $payload);

        // Apply Codex Responses API defaults for required fields that are
        // not explicitly set by the caller or the contract. These match the
        // pi-mono openai-codex-responses.ts buildRequestBody shape.
        $jsonBody['store'] ??= false;
        $jsonBody['stream'] ??= true;

        // text may already have 'format' from structured output; merge verbosity in.
        if (!isset($jsonBody['text'])) {
            $jsonBody['text'] = ['verbosity' => 'low'];
        } elseif (!isset($jsonBody['text']['verbosity'])) {
            $jsonBody['text']['verbosity'] = 'low';
        }

        $jsonBody['include'] ??= ['reasoning.encrypted_content'];
        $jsonBody['tool_choice'] ??= 'auto';
        $jsonBody['parallel_tool_calls'] ??= true;
```

### Current INTERNAL_OPTION_KEYS — lines 24–37

```php
    private const array INTERNAL_OPTION_KEYS = [
        '_agent_core_invocation',
        '_hatfield_reasoning',
        'tool_stream',
        'tools_ref',
        'turn_no',
        'run_id',
    ];
```

### Target changes

#### 4A. Add `prompt_cache_key` from `run_id`

After the `$bodyOptions = array_diff_key(...)` line and before the `$jsonBody = array_merge(...)`, add:

```php
        // Prompt caching: use run_id (session_id) as the cache key.
        // run_id is stripped from bodyOptions above but we extract it here
        // for prompt_cache_key. Pi-mono: prompt_cache_key: sessionId.
        $runId = $options['run_id'] ?? null;
        if (\is_string($runId) && '' !== $runId) {
            $jsonBody['prompt_cache_key'] = $runId;
        }
```

Full diff context (insert after line 65, before `$jsonBody = array_merge(...)`):

```php
        // Strip Hatfield/Symfony AI internal keys that are not valid Codex API fields.
        $bodyOptions = array_diff_key($options, array_flip(self::INTERNAL_OPTION_KEYS));

        // Prompt caching: use run_id (session_id) as the cache key.
        // run_id is stripped from bodyOptions above but we extract it here
        // for prompt_cache_key. Pi-mono: prompt_cache_key: sessionId.
        $runId = $options['run_id'] ?? null;

        // Merge payload (from contract) over options, with model name last
        // so CodexContract's payload keys (input, instructions) always win
        // over any top-level option keys.
        $jsonBody = array_merge($bodyOptions, ['model' => $model->getName()], $payload);

        // Apply Codex Responses API defaults for required fields that are
        // not explicitly set by the caller or the contract. These match the
        // pi-mono openai-codex-responses.ts buildRequestBody shape.
        $jsonBody['store'] ??= false;
        $jsonBody['stream'] ??= true;

        // Prompt cache key from session/run ID (added before defaults so
        // an explicit caller value takes precedence via ??=).
        if (\is_string($runId) && '' !== $runId) {
            $jsonBody['prompt_cache_key'] ??= $runId;
        }

        // text may already have 'format' from structured output; merge verbosity in.
```

#### 4B. Verify `include` already contains `reasoning.encrypted_content`

Current line 86: `$jsonBody['include'] ??= ['reasoning.encrypted_content'];`

**No change needed.** This is already correct per pi-mono.

#### 4C. Verify `instructions` vs `input`

The `instructions` (system prompt) is provided by the `CodexContract` payload (from `MessageBagNormalizer`), NOT by this client. **No change needed.**

---

## File 5: `src/Platform/Bridge/OpenAICodex/CodexSseStream.php` (92 lines)

### Current code — full file already read above

### Assessment

The `CodexSseStream` already:
1. Reads the full response body
2. Splits by SSE event boundaries
3. Extracts `data:` lines and decodes JSON
4. Yields decoded event arrays (`array<string, mixed>`)

**No changes needed.** The `ResultConverter::convertStream()` receives properly decoded event arrays from this stream.

---

## File 6: `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php` (850 lines)

### Current `buildAssistantMessage()` — lines 615–670

```php
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
```

### Target changes

**No changes to `buildAssistantMessage()` needed.**

The method already:
1. Captures `ThinkingSignature` deltas into `$thinkingSignature` ✓
2. Captures `ThinkingComplete` with its built-in signature ✓
3. Constructs `Thinking(content, signature)` with the signature ✓
4. The `Thinking` class (vendor) accepts both `content` and `signature` parameters ✓

The signature captured here is the **full reasoning item JSON** (from `response.output_item.added` or `response.output_item.done` in ResultConverter), stored as a string. This survives through:
- `AgentMessageNormalizer::extractThinkingDetails()` → reads `$part->getSignature()` → stores as `thinking_signature` in details ✓
- `AgentMessageConverter::buildAssistantMessage()` → reads `details['thinking_signature']` → reconstructs `Thinking(content, signature)` ✓

**The round-trip is already wired.** The only missing piece is that `ResultConverter::convertStream()` currently doesn't yield `ThinkingSignature` or `ThinkingComplete` with the signature. Fixing the ResultConverter (File 1) completes the chain.

---

## File 7: Thinking content type & persistence round-trip

### `vendor/symfony/ai-platform/src/Message/Content/Thinking.php` — full file

```php
final class Thinking implements ContentInterface
{
    public function __construct(
        private readonly string $content,
        private readonly ?string $signature = null,
    ) {
    }

    public function getContent(): string { return $this->content; }
    public function getSignature(): ?string { return $this->signature; }
}
```

### `src/AgentCore/Domain/Message/AgentMessageNormalizer.php` — `extractThinkingDetails()` — lines 219–248

```php
    private function extractThinkingDetails(AssistantMessage $assistantMessage): array
    {
        if (!$assistantMessage->hasThinking()) {
            return [];
        }

        $thinkingParts = $assistantMessage->getThinking();

        $thinkingContent = implode('', array_map(
            static fn (Thinking $t): string => $t->getContent(),
            $thinkingParts,
        ));

        $thinkingSignature = null;
        foreach ($thinkingParts as $part) {
            if (null !== $part->getSignature()) {
                $thinkingSignature = $part->getSignature();
            }
        }

        return array_filter([
            'thinking' => '' !== $thinkingContent ? $thinkingContent : null,
            'thinking_signature' => $thinkingSignature,
        ], static fn (mixed $value): bool => null !== $value);
    }
```

### `src/AgentCore/Infrastructure/SymfonyAi/AgentMessageConverter.php` — `buildAssistantMessage()` — lines 217–242

```php
    private function buildAssistantMessage(string $textContent, AgentMessage $message): AssistantMessage
    {
        $contentParts = [];

        if ('' !== $textContent) {
            $contentParts[] = new Text($textContent);
        }

        $thinkingContent = \is_string($message->details['thinking'] ?? null) ? $message->details['thinking'] : null;
        $thinkingSignature = \is_string($message->details['thinking_signature'] ?? null) ? $message->details['thinking_signature'] : null;

        if (null !== $thinkingContent || null !== $thinkingSignature) {
            $contentParts[] = new Thinking(
                content: $thinkingContent ?? '',
                signature: $thinkingSignature,
            );
        }

        $toolCalls = $this->assistantToolCalls($message);
        if (null !== $toolCalls) {
            foreach ($toolCalls as $toolCall) {
                $contentParts[] = $toolCall;
            }
        }

        return new AssistantMessage(...$contentParts);
    }
```

### Assessment

**The full round-trip is already implemented:**
1. **Stream → AssistantMessage:** ResultConverter yields `ThinkingSignature` and `ThinkingComplete` with signature → LlmPlatformAdapter builds `Thinking(content, signature)` ✓
2. **AssistantMessage → AgentMessage:** AgentMessageNormalizer extracts `thinking` and `thinking_signature` into `details` ✓
3. **AgentMessage → AssistantMessage (next turn):** AgentMessageConverter reads `details['thinking']` and `details['thinking_signature']` and rebuilds `Thinking(content, signature)` ✓
4. **AssistantMessage → Codex input:** CodexAssistantMessageNormalizer reads `Thinking` parts, extracts signature, and emits separate reasoning item ✓ (after our changes)

**No changes needed to Thinking.php, AgentMessageNormalizer, or AgentMessageConverter.** They already support the signature. The fix is purely in the ResultConverter (capturing and emitting the signature) and the normalizer (emitting the reasoning input item).

---

## File 8: Tests — `tests/Platform/Bridge/OpenAICodex/`

### Existing test files

| File | Lines | Key Methods |
|------|-------|-------------|
| `ResultConverterTest.php` | 674 | `testConvertTextResult`, `testConvertToolCallResult`, `testConvertMultipleMessagesIntoMultiPartResult`, `testConvertReasoningPlusMessageIntoMultiPartResult`, `testConvertReasoningEmitsOneThinkingResultPerSummaryChunk`, `testConvertReasoningWithoutSummaryIsDropped`, `testConvertRefusalResult`, `testContentFilterException`, `testThrowsAuthenticationExceptionOnInvalidApiKey`, `testThrowsExceptionWhenNoOutput`, `testThrowsBadRequestExceptionOnBadRequestResponse`, `testThrowsBadRequestExceptionOnBadRequestResponseWithNoResponseBody`, `testThrowsRateLimitExceededExceptionOn429`, `testThrowsDetailedErrorException`, `testStreamTransmitsUsageToResultMetadata`, `testStreamWithToolCalls`, `testStreamWithReasoningContent`, `testThrowsBadRequestWithCodeTypeParamOnStructuredError`, `testThrowsBadRequestWithBodyPreviewOnNonJsonResponse`, `testThrowsBadRequestWithAlternativeTopLevelErrorKeys`, `testThrowsBadRequestWithEmptyBodyFallsBackToClientError`, `testThrowsAuthenticationWithCodeTypeOnStructuredError`, `testThrowsRateLimitWithCodeTypeOnStructuredError` |
| `CodexContractTest.php` | 195 (approx) | `testCreateRequestPayload` (dataProvider: `user message only`, `system + user message`, `multi-turn conversation`, `system + conversation`, `with tool call in assistant message`), `testItDoesNotContainMessagesKey`, `testUserContentIsTypedInputText`, `testToolNormalizerIncludesStrictNull`, `testToolNormalizerOmitsStrictWhenNoParameters`, `testToolCallNormalizerIncludesIdAndCallId` |
| `CodexModelClientTest.php` | 285 (approx) | `testItSupportsCodexModel`, `testItDoesNotSupportOtherModels`, `testItIsExecutingTheCorrectRequest`, `testItUsesCustomResponsesPath`, `testItHandlesStructuredOutputOption`, `testItUsesCustomOriginator`, `testItStripsInternalHatfieldKeysFromBody`, `testItPreservesValidCodexApiKeysInBody`, `testItStripsInternalKeysWhilePreservingPayloadAndModel`, `testItIncludesCodexRequiredDefaultsInBody`, `testCodexDefaultsDoNotOverrideExplicitValues`, `testLogsRequestSummaryOnRequest` |
| `CodexSseStreamTest.php` | 118 | `testParsesSingleSseEvent`, `testParsesMultipleSseEvents`, `testSkipsEventWithDoneSentinel`, `testHandlesCrLfNewlines`, `testHandlesEmptyBody`, `testHandlesBodyWithOnlyComments`, `testThrowsOnInvalidJsonInData`, `testThrowsOnBodyExceedingMaxSize`, `testHandlesResponseWithToolCallEvent` |

### NEW tests to add

#### 8A. ResultConverterTest — streaming error handling

```php
    public function testStreamErrorEventThrowsRuntimeException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Partial'],
            ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Internal error']],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/server_error.*Internal error/');

        $streamResult = $converter->convert($raw, ['stream' => true]);
        iterator_to_array($streamResult->getContent());
    }

    public function testStreamResponseFailedThrowsRuntimeException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Partial'],
            ['type' => 'response.failed', 'response' => [
                'error' => ['code' => 'rate_limited', 'message' => 'Rate limited'],
            ]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rate_limited.*Rate limited/');

        $streamResult = $converter->convert($raw, ['stream' => true]);
        iterator_to_array($streamResult->getContent());
    }

    public function testStreamResponseIncompleteThrowsRuntimeException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Partial response'],
            ['type' => 'response.incomplete', 'response' => [
                'incomplete_details' => ['reason' => 'max_tokens'],
                'output' => [],
            ]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/incomplete.*max_tokens/');

        $streamResult = $converter->convert($raw, ['stream' => true]);
        iterator_to_array($streamResult->getContent());
    }

    public function testStreamWithoutResponseCompletedThrowsIncompleteStreamException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
            // No response.completed — stream just ends
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $this->expectException(IncompleteStreamException::class);
        $this->expectExceptionMessageMatches('/ended before response.completed/');

        $streamResult = $converter->convert($raw, ['stream' => true]);
        iterator_to_array($streamResult->getContent());
    }
```

Add import:
```php
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
```

#### 8B. ResultConverterTest — thinking-only stream with signature

```php
    public function testStreamThinkingOnlyWithSignature(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'encrypted_content' => 'enc_abc123',
                'status' => 'in_progress',
            ]],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me think'],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => ' about it', 'signature' => '{"type":"reasoning","id":"rs_1","encrypted_content":"enc_abc123"}'],
            ['type' => 'response.reasoning_summary_text.done'],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'encrypted_content' => 'enc_abc123',
                'summary' => [['type' => 'summary_text', 'text' => 'Let me think about it']],
            ]],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $streamResult = $converter->convert($raw, ['stream' => true]);

        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = iterator_to_array($streamResult->getContent());

        // Verify ThinkingStart, ThinkingDelta(s), ThinkingSignature, ThinkingComplete with signature
        $this->assertInstanceOf(ThinkingStart::class, $chunks[0]);
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[1]);
        $this->assertInstanceOf(ThinkingSignature::class, $chunks[2]);
        $this->assertSame('{"type":"reasoning","id":"rs_1","encrypted_content":"enc_abc123"}', $chunks[2]->getSignature());
        $this->assertInstanceOf(ThinkingComplete::class, $chunks[3]);
        $this->assertSame('Let me think about it', $chunks[3]->getThinking());
        // ThinkingComplete should carry the signature too (from output_item.done)
        $this->assertNotNull($chunks[3]->getSignature());
    }
```

#### 8C. ResultConverterTest — response.done normalization

```php
    public function testStreamResponseDoneNormalizedToCompleted(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
            ['type' => 'response.done', 'response' => ['output' => []]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $streamResult = $converter->convert($raw, ['stream' => true]);
        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = iterator_to_array($streamResult->getContent());
        $this->assertInstanceOf(TextDelta::class, $chunks[0]);
        $this->assertSame('Hello', $chunks[0]->getText());
        // No exception — response.done was normalized to response.completed
    }
```

#### 8D. CodexContractTest — thinking-only normalizer

```php
    public function testAssistantWithThinkingSignatureEmitsReasoningItem(): void
    {
        // Create an AssistantMessage with Thinking content carrying a signature
        $thinking = new \Symfony\AI\Platform\Message\Content\Thinking(
            content: 'Let me reason',
            signature: '{"type":"reasoning","id":"rs_1","encrypted_content":"enc_xyz"}',
        );
        $assistantMessage = new \Symfony\AI\Platform\Message\AssistantMessage($thinking);

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // Should return a reasoning item (single item, not a message)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertSame('reasoning', $result['type']);
        $this->assertSame('rs_1', $result['id']);
        $this->assertSame('enc_xyz', $result['encrypted_content']);
    }

    public function testAssistantWithTextAndThinkingSignatureEmitsBothItems(): void
    {
        $thinking = new \Symfony\AI\Platform\Message\Content\Thinking(
            content: 'Reasoning here',
            signature: '{"type":"reasoning","id":"rs_2","encrypted_content":"enc_abc"}',
        );
        $text = new \Symfony\AI\Platform\Message\Content\Text('The answer is 42');
        $assistantMessage = new \Symfony\AI\Platform\Message\AssistantMessage($thinking, $text);

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // Should return an array of 2 items: reasoning + message
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertSame('reasoning', $result[0]['type']);
        $this->assertSame('message', $result[1]['type']);
        $this->assertArrayHasKey('content', $result[1]);
    }

    public function testEmptyAssistantMessageProducesNoContentNull(): void
    {
        // AssistantMessage with no content — should produce empty array
        $assistantMessage = new \Symfony\AI\Platform\Message\AssistantMessage();

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // Empty array = nothing to emit (not content:null)
        $this->assertSame([], $result);
    }

    public function testThinkingOnlyWithoutSignatureProducesNoMessageItem(): void
    {
        // Thinking with no signature — no text — should produce empty array
        $thinking = new \Symfony\AI\Platform\Message\Content\Thinking(
            content: 'Reasoning without signature',
            signature: null,
        );
        $assistantMessage = new \Symfony\AI\Platform\Message\AssistantMessage($thinking);

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // No signature → no reasoning item. No text → no message item.
        // Result: empty array
        $this->assertSame([], $result);
    }
```

#### 8E. CodexModelClientTest — prompt_cache_key

```php
    public function testItSetsPromptCacheKeyFromRunId(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = \json_decode($options['body'], true);
            self::assertArrayHasKey('prompt_cache_key', $body);
            self::assertSame('session-abc-123', $body['prompt_cache_key']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            ['run_id' => 'session-abc-123'],
        );
    }

    public function testItDoesNotSetPromptCacheKeyWithoutRunId(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = \json_decode($options['body'], true);
            self::assertArrayNotHasKey('prompt_cache_key', $body);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );
    }
```

#### 8F. CodexContractTest — multi-item in input array

Add a new data provider case to `requestPayloadProvider`:

```php
        yield 'thinking-only with reasoning item' => [
            // MessageBag with an assistant message that has only Thinking with signature
            (function() {
                $thinking = new \Symfony\AI\Platform\Message\Content\Thinking(
                    content: 'Reasoning',
                    signature: '{"type":"reasoning","id":"rs_1","encrypted_content":"enc_xyz"}',
                );
                return new MessageBag(
                    Message::ofUser('Hello'),
                    new \Symfony\AI\Platform\Message\AssistantMessage($thinking),
                );
            })(),
            [
                'input' => [
                    ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hello']]],
                    ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'enc_xyz'],
                ],
            ],
        ];
```

Note: the exact expected output depends on how `json_decode` reconstructs the signature JSON. The reasoning item should appear as a separate input entry (not as a message with `content:null`).

---

## Architectural Assessment

### Can MessageBagNormalizer handle multiple items per message?

**No, not the OpenResponses MessageBagNormalizer.** It does `$messages['input'][] = $normalized` for non-tool-call messages, which means a multi-item array gets appended as a nested array. The fix requires the new `CodexMessageBagNormalizer` that checks if the result is a non-empty indexed array and flattens it with `array_merge`.

**This is the key architectural change:** we must replace the OpenResponses `MessageBagNormalizer` with a Codex-specific one. The new normalizer is straightforward — it's a 15-line change from the OpenResponses version (adding the array-flattening check and the empty-array skip).

### Deptrac boundary concerns

The `SymfonyAiPlatform` layer in `depfile.yaml` is a leaf layer (only depends on `SymfonyEventDispatcher` and `SymfonyHttpClient`). The `src/Platform/Bridge/OpenAICodex/` code uses:
- `Symfony\AI\Platform\...` types (same layer) ✓
- `Symfony\Component\Serializer\...` (via NormalizerAwareInterface) — this is `SymfonySerializer` which is a leaf layer ✓
- No dependencies on AgentCore, CodingAgent, or TUI ✓

**No Deptrac changes needed.** The new `CodexMessageBagNormalizer` and `CodexAssistantMessageNormalizer` changes stay within the `SymfonyAiPlatform` layer.

### Vendor Thinking class — can it hold a signature?

**Yes.** `vendor/symfony/ai-platform/src/Message/Content/Thinking.php` has a `?string $signature` constructor parameter and `getSignature(): ?string` method. The signature can hold any string, including full JSON. We store the full reasoning item JSON as the signature string, which survives:
1. Stream → `ThinkingSignature` delta → `LlmPlatformAdapter::buildAssistantMessage()` → `Thinking(content, signature)`
2. `AgentMessageNormalizer::extractThinkingDetails()` → `details['thinking_signature']`
3. `AgentMessageConverter::buildAssistantMessage()` → `Thinking(content, signature)` restored
4. `CodexAssistantMessageNormalizer` → reads signature, emits reasoning input item

**We do NOT need to store the signature in AgentMessage details separately** — it's already stored there as `thinking_signature`. The signature IS the full reasoning item JSON.

### Exception class choice

- `RuntimeException` — for `'error'` events, `'response.failed'`, and `'response.incomplete'` (matching OpenResponses bridge). Already imported.
- `IncompleteStreamException` — for streams ending without `response.completed`. Already imported in OpenResponses bridge, available in vendor.

---

## Summary of changes by file

| File | Change | Lines affected |
|------|--------|----------------|
| `ResultConverter.php` | Add error/failed/incomplete/stream-guards; capture reasoning signature; emit ThinkingSignature | Replace `convertStream()`, add 2 helpers |
| `CodexAssistantMessageNormalizer.php` | Return reasoning item + message item (or empty array) instead of content:null | Replace `normalize()` |
| `CodexMessageBagNormalizer.php` | **NEW FILE** — flatten multi-item results, skip empty arrays | ~60 lines |
| `CodexContract.php` | Swap `MessageBagNormalizer` → `CodexMessageBagNormalizer` | 1 import + 1 instantiation |
| `CodexModelClient.php` | Extract `run_id` → `prompt_cache_key` in request body | ~5 lines insert |
| `CodexSseStream.php` | No change | — |
| `LlmPlatformAdapter.php` | No change (already handles ThinkingSignature) | — |
| `AgentMessageNormalizer.php` | No change (already extracts signature) | — |
| `AgentMessageConverter.php` | No change (already restores signature) | — |
| Tests | 10+ new test methods across 3 test files | ~200 lines |