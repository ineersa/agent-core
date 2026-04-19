<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Domain\Tool\PlatformInvocationResult;

/**
 * SymfonyPlatformInvoker acts as an adapter for the Symfony AI platform, translating generic agent inputs into platform-specific invocations. It handles the execution of model calls and extracts structured usage metadata from the platform's deferred results.
 */
final readonly class SymfonyPlatformInvoker
{
    /**
     * Initializes the invoker with an optional Symfony AI platform instance.
     */
    public function __construct(
        private ?object $platform = null,
    ) {
    }

    /**
     * Invokes the specified model with input, tools, and cancellation support.
     *
     * @param array<string, mixed>       $options
     * @param list<array<string, mixed>> $toolDefinitions
     */
    public function invoke(
        string $model,
        array|string|object $input,
        array $toolDefinitions = [],
        array $options = [],
        ?CancellationTokenInterface $cancelToken = null,
    ): PlatformInvocationResult {
        $cancelToken ??= new NullCancellationToken();

        if (null === $this->platform || !method_exists($this->platform, 'invoke')) {
            throw new \RuntimeException('Symfony AI platform service is not configured. Register Symfony\\AI\\Platform\\PlatformInterface in the container.');
        }

        $effectiveOptions = $options;
        $effectiveOptions['stream'] = true;

        if ([] !== $toolDefinitions && !\array_key_exists('tools', $effectiveOptions)) {
            $effectiveOptions['tools'] = $toolDefinitions;
        }

        $deferredResult = $this->platform->invoke($model, $input, $effectiveOptions);

        $reducer = new StreamDeltaReducer();
        $aborted = false;

        try {
            foreach ($this->streamFrom($deferredResult) as $delta) {
                if ($cancelToken->isCancellationRequested()) {
                    $aborted = true;
                    break;
                }

                $reducer->consume($delta);
            }
        } catch (\Throwable $exception) {
            $usage = $this->extractUsage($deferredResult);

            return new PlatformInvocationResult(
                assistantMessage: null,
                deltas: $reducer->deltas(),
                usage: $usage,
                stopReason: 'error',
                error: [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );
        }

        $usage = array_replace($this->extractUsage($deferredResult), $reducer->usage());
        $assistantMessage = $reducer->assistantMessage();

        return new PlatformInvocationResult(
            assistantMessage: $assistantMessage,
            deltas: $reducer->deltas(),
            usage: $usage,
            stopReason: $aborted ? 'aborted' : ($reducer->hasToolCalls() ? 'tool_call' : null),
            error: null,
        );
    }

    /**
     * Streams results from a deferred platform result object.
     *
     * @return iterable<mixed>
     */
    private function streamFrom(object $deferredResult): iterable
    {
        if (method_exists($deferredResult, 'asStream')) {
            return $deferredResult->asStream();
        }

        if (method_exists($deferredResult, 'getResult')) {
            $result = $deferredResult->getResult();
            if (\is_object($result) && method_exists($result, 'getContent')) {
                $content = $result->getContent();
                if (is_iterable($content)) {
                    return $content;
                }
            }
        }

        throw new \RuntimeException('Symfony AI deferred result does not expose a stream via asStream().');
    }

    /**
     * Extracts usage metrics from a deferred platform result object.
     *
     * @return array<string, int|float>
     */
    private function extractUsage(object $deferredResult): array
    {
        $metadata = $this->metadataFrom($deferredResult);
        if (null === $metadata) {
            return [];
        }

        if (\is_array($metadata) && \is_array($metadata['token_usage'] ?? null)) {
            return $metadata['token_usage'];
        }

        $tokenUsage = null;

        if (\is_object($metadata) && method_exists($metadata, 'get')) {
            $tokenUsage = $metadata->get('token_usage');
        }

        if (\is_array($metadata)) {
            $tokenUsage = $metadata['token_usage'] ?? null;
        }

        if (!\is_object($tokenUsage)) {
            return [];
        }

        return array_filter([
            'input_tokens' => $this->numericFrom($tokenUsage, 'getPromptTokens'),
            'output_tokens' => $this->numericFrom($tokenUsage, 'getCompletionTokens'),
            'thinking_tokens' => $this->numericFrom($tokenUsage, 'getThinkingTokens'),
            'tool_tokens' => $this->numericFrom($tokenUsage, 'getToolTokens'),
            'total_tokens' => $this->numericFrom($tokenUsage, 'getTotalTokens'),
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * Extracts metadata from a deferred platform result object.
     *
     * @return array<string, mixed>|object|null
     */
    private function metadataFrom(object $deferredResult): array|object|null
    {
        if (method_exists($deferredResult, 'getMetadata')) {
            return $deferredResult->getMetadata();
        }

        if (method_exists($deferredResult, 'getResult')) {
            $result = $deferredResult->getResult();
            if (\is_object($result) && method_exists($result, 'getMetadata')) {
                return $result->getMetadata();
            }
        }

        return null;
    }

    /**
     * Retrieves a numeric value from a platform result using a method name.
     */
    private function numericFrom(object $value, string $method): int|float|null
    {
        if (!method_exists($value, $method)) {
            return null;
        }

        $raw = $value->{$method}();

        if (\is_int($raw) || \is_float($raw)) {
            return $raw;
        }

        return null;
    }
}
