<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\JsonBodyEncodingTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI-compatible completions client that keeps {@see LlmEventSourceHttpClient}
 * as the request framing layer.
 *
 * Vendor {@see \Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient} wraps
 * any non-{@see \Symfony\Component\HttpClient\EventSourceHttpClient} transport with
 * the reconnecting EventSource client, which swallows cancel/idle transport
 * failures for reconnectionTime. This client preserves Hatfield's no-reconnect
 * SSE framing instead.
 */
final class LlmCompletionsModelClient implements ModelClientInterface
{
    use JsonBodyEncodingTrait;

    private readonly HttpClientInterface $httpClient;
    private readonly string $baseUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        string $baseUrl,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly string $path = '/v1/chat/completions',
    ) {
        $this->httpClient = $httpClient instanceof LlmEventSourceHttpClient
            ? $httpClient
            : new LlmEventSourceHttpClient($httpClient);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function supports(Model $model): bool
    {
        return $model instanceof CompletionsModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, but a string was given to "%s".', self::class));
        }

        if ($options['stream'] ?? false) {
            if (!\array_key_exists('stream_options', $options)) {
                $options['stream_options'] = ['include_usage' => true];
            }
        }

        if ([] !== ($options['tools'] ?? [])) {
            $options['tool_choice'] ??= 'auto';
        }

        unset($options['cacheRetention']);

        return new RawHttpResult($this->httpClient->request('POST', $this->baseUrl.$this->path, [
            'auth_bearer' => $this->apiKey,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $this->encodeJsonBody(array_merge($options, $payload)),
        ]));
    }
}
