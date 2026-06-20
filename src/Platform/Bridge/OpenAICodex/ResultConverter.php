<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Bridge\OpenResponses\TokenUsageExtractor;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @phpstan-type OutputMessage array{content: array<Refusal|OutputText>, id: string, role: string, type: 'message'}
 * @phpstan-type OutputText array{type: 'output_text', text: string}
 * @phpstan-type Refusal array{type: 'refusal', refusal: string}
 * @phpstan-type FunctionCall array{id: string, arguments: string, call_id: string, name: string, type: 'function_call'}
 * @phpstan-type Thinking array{summary: list<array{type: string, text?: string}>, id: string}
 */
final class ResultConverter implements ResultConverterInterface
{
    private const KEY_OUTPUT = 'output';

    public function supports(Model $model): bool
    {
        return $model instanceof CodexModel;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $response = $result->getObject();

        if (401 === $response->getStatusCode()) {
            throw new AuthenticationException($this->extractErrorDiagnostics($response));
        }

        if (400 === $response->getStatusCode()) {
            throw new BadRequestException($this->extractErrorDiagnostics($response));
        }

        if (429 === $response->getStatusCode()) {
            throw new RateLimitExceededException(null, $this->extractErrorDiagnostics($response));
        }

        if (true === ($options['stream'] ?? false)) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();

        if (isset($data['error']['code']) && 'content_filter' === $data['error']['code']) {
            throw new ContentFilterException($data['error']['message'] ?? 'Content filter triggered');
        }

        if (isset($data['error'])) {
            throw new RuntimeException(\sprintf('Error "%s"-%s (%s): "%s".', $data['error']['code'] ?? '-', $data['error']['type'] ?? '-', $data['error']['param'] ?? '-', $data['error']['message'] ?? '-'));
        }

        if (!isset($data[self::KEY_OUTPUT])) {
            throw new RuntimeException('Response does not contain output.');
        }

        $results = $this->convertOutputArray($data[self::KEY_OUTPUT]);

        return 1 === \count($results) ? array_pop($results) : new MultiPartResult(array_values($results));
    }

    public function getTokenUsageExtractor(): TokenUsageExtractor
    {
        return new TokenUsageExtractor();
    }

    /**
     * Extract a privacy-safe diagnostic message from an HTTP error response.
     *
     * Returns the most informative available detail while keeping the result
     * safe for logging (truncated, no tokens/account IDs/prompts).
     */
    private function extractErrorDiagnostics(ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);

        $data = json_decode($body, true);

        if (null !== $data && isset($data['error']) && \is_array($data['error'])) {
            $error = $data['error'];
            $parts = [];
            if (isset($error['code']) && '' !== $error['code']) {
                $parts[] = (string) $error['code'];
            }
            if (isset($error['type']) && '' !== $error['type']) {
                $parts[] = (string) $error['type'];
            }
            if (isset($error['param']) && '' !== $error['param']) {
                $parts[] = (string) $error['param'];
            }

            $prefix = [] !== $parts ? '['.implode('/', $parts).']: ' : '';
            $message = $error['message'] ?? '-';

            return $prefix.$message;
        }

        // Alternative top-level error keys (Hydra, OAuth, or Codex-specific shapes).
        // Only match when 'error' is not an array (handled above).
        // Strings like {"error":"invalid_request"} are alternative, not structured.
        if (null !== $data) {
            $alt = $data['error_description']
                ?? $data['error_code']
                ?? $data['detail']
                ?? (\is_string($data['error'] ?? null) ? $data['error'] : null)
                ?? null;
            if (null !== $alt) {
                $preview = \is_string($alt) ? $alt : (string) json_encode($alt);

                return mb_substr($preview, 0, 500);
            }
        }

        // Non-JSON or empty body — include content type and truncated preview
        $contentType = '';
        try {
            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? '';
        } catch (\Throwable) {
            // Headers may not be available on mocked responses
        }

        $preview = mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 200);
        if ('' === $preview) {
            // Empty body: use standard HTTP reason phrases
            return match ($statusCode) {
                400 => 'Bad Request',
                401 => 'Unauthorized',
                429 => 'Rate limit exceeded',
                default => \sprintf('HTTP %d', $statusCode),
            };
        }

        if ('' !== $contentType) {
            return \sprintf('%s: "%s"', $contentType, $preview);
        }

        return \sprintf('"%s"', $preview);
    }

    /**
     * @param array<OutputMessage|FunctionCall|Thinking> $output
     *
     * @return ResultInterface[]
     */
    private function convertOutputArray(array $output): array
    {
        [$toolCallResult, $output] = $this->extractFunctionCalls($output);

        $results = [];
        foreach ($output as $item) {
            foreach ($this->processOutputItem($item) as $result) {
                $results[] = $result;
            }
        }
        if (null !== $toolCallResult) {
            $results[] = $toolCallResult;
        }

        return $results;
    }

    /**
     * @param OutputMessage|Thinking $item
     *
     * @return iterable<ResultInterface>
     */
    private function processOutputItem(array $item): iterable
    {
        $type = $item['type'] ?? null;

        return match ($type) {
            'message' => $this->convertOutputMessage($item),
            'reasoning' => $this->convertReasoning($item),
            default => throw new RuntimeException(\sprintf('Unsupported output type "%s".', $type)),
        };
    }

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

            // Per-server discovery starting log — events without a type field that
            // only carry response.* are not response events. The stream must produce
            // types to be considered a real response.
            if ('' !== $type) {
                $sawResponseEvent = true;
            }

            // Mid-stream error event — throw immediately.
            // Fixes the silent mid-turn death bug: previously these events were
            // silently ignored, producing a null assistant message and an HTTP 400
            // on the subsequent turn.
            if ('error' === $type) {
                throw new RuntimeException($this->generateErrorMessage($this->extractStreamError($event)));
            }

            // response.failed — the response was rejected by the server.
            if ('response.failed' === $type) {
                $response = \is_array($event['response'] ?? null) ? $event['response'] : [];

                throw new RuntimeException($this->generateErrorMessage($this->extractStreamError($response)));
            }

            // response.incomplete — context limit or other truncation.
            if ('response.incomplete' === $type) {
                $reason = $event['response']['incomplete_details']['reason'] ?? 'unknown';
                if (!\is_string($reason) || '' === $reason) {
                    $reason = 'unknown';
                }

                // Yield any partial tool calls accumulated so far before throwing,
                // so the caller can still process partial results.
                if ([] !== $toolCalls) {
                    yield new ToolCallComplete(array_values($toolCalls));
                }

                throw new RuntimeException(\sprintf('Codex stream ended incomplete (%s).', $reason));
            }

            // response.done — normalize to completed (Codex API uses both variants).
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

            // Reasoning summary delta — accumulate thinking text from the
            // Codex reasoning_summary_text stream events.
            if ('response.reasoning_summary_text.delta' === $type && isset($event['delta'])) {
                if (null === $currentThinking) {
                    $currentThinking = '';
                    yield new ThinkingStart();
                }
                $currentThinking .= $event['delta'];
                yield new ThinkingDelta($event['delta']);
            }

            // Reasoning summary done — emit ThinkingComplete.
            // The signature is captured from output_item.added or output_item.done
            // events (below), NOT from this event.
            if ('response.reasoning_summary_text.done' === $type) {
                yield new ThinkingComplete($currentThinking ?? '', $currentThinkingSignature);
                $currentThinking = null;
                $currentThinkingSignature = null;
            }

            // output_item.added — capture the full reasoning item JSON when a
            // reasoning item is added, for later replay as a separate input item.
            // The 'item' carries encrypted_content for round-trip reasoning.
            if ('response.output_item.added' === $type && \is_array($event['item'] ?? null)) {
                $item = $event['item'];
                if ('reasoning' === ($item['type'] ?? null)) {
                    $currentThinkingSignature = json_encode(
                        $item,
                        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                    );
                }
            }

            // output_item.done — capture the completed reasoning item signature
            // (authoritative, may include summary). Also collect tool calls
            // incrementally for fallback when the response.completed output array
            // is empty.
            if ('response.output_item.done' === $type && \is_array($event['item'] ?? null)) {
                $item = $event['item'];
                if ('reasoning' === ($item['type'] ?? null)) {
                    $currentThinkingSignature = json_encode(
                        $item,
                        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                    );
                } elseif ('function_call' === ($item['type'] ?? null)) {
                    $toolCall = $this->convertFunctionCall($item);
                    $toolCalls[$toolCall->getId()] = $toolCall;
                }
            }

            // response.completed — emit final tool calls from the canonical
            // output array (primary path), or from incrementally collected
            // output_item.done items (fallback).
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

        // Stream ended without response.completed or response.done — the
        // event loop finished cleanly but no terminal event was received.
        if ($sawResponseEvent && !$sawResponseCompleted) {
            throw new IncompleteStreamException('Codex stream ended before response.completed.');
        }
    }

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

    /**
     * @param OutputMessage $output
     *
     * @return \Generator<TextResult>
     */
    private function convertOutputMessage(array $output): \Generator
    {
        $content = $output['content'] ?? [];
        if ([] === $content) {
            return;
        }

        // Responses API messages contain exactly one content block per message.
        $content = array_pop($content);
        if ('refusal' === $content['type']) {
            yield new TextResult(\sprintf('Model refused to generate output: %s', $content['refusal']));

            return;
        }

        yield new TextResult($content['text']);
    }

    /**
     * @param FunctionCall $toolCall
     *
     * @throws \JsonException
     */
    private function convertFunctionCall(array $toolCall): ToolCall
    {
        $arguments = json_decode($toolCall['arguments'], true, flags: \JSON_THROW_ON_ERROR);

        return new ToolCall($toolCall['id'], $toolCall['name'], $arguments);
    }

    /**
     * Extract structured error diagnostics from a stream event or response.
     *
     * Works with both top-level error events ({"type":"error","error":{...}})
     * and response.failed events ({"type":"response.failed","response":{"error":{...}}}).
     *
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

    /**
     * Build a privacy-safe error message from extracted stream error fields,
     * following the same format as the non-stream error path.
     *
     * @param array{code?: string|null, type?: string|null, param?: string|null, message?: string|null} $error
     */
    private function generateErrorMessage(array $error): string
    {
        return \sprintf(
            'Error "%s"-%s (%s): "%s".',
            $error['code'] ?? '-',
            $error['type'] ?? '-',
            $error['param'] ?? '-',
            $error['message'] ?? '-',
        );
    }

    /**
     * @param Thinking $item
     *
     * @return \Generator<ThinkingResult>
     */
    private function convertReasoning(array $item): \Generator
    {
        foreach ($item['summary'] ?? [] as $entry) {
            if ('' !== ($entry['text'] ?? '')) {
                yield new ThinkingResult($entry['text']);
            }
        }
    }
}
