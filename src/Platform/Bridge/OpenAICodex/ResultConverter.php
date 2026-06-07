<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
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
