<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Http\HttpStatus;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;

final class CodexWebSocketModelClient implements ModelClientInterface
{
    private const float DEFAULT_CONNECT_TIMEOUT_SECONDS = 30.0;
    private const float DEFAULT_IDLE_TIMEOUT_SECONDS = 120.0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CodexWebSocketConnectorInterface $connector,
        private readonly CodexWebSocketUrlResolver $urlResolver,
        private readonly CodexWebSocketHandshakeHeadersFactory $handshakeHeadersFactory,
        private readonly CodexRequestBodyFactory $requestBodyFactory,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private string $accessToken,
        private readonly string $accountId,
        private readonly string $responsesPath = '/codex/responses',
        private readonly string $originator = 'hatfield',
        ?LoggerInterface $logger = null,
        /** @var (\Closure(): ?string)|null */
        private readonly ?\Closure $accessTokenRefresher = null,
        private readonly float $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        private readonly float $idleTimeoutSeconds = self::DEFAULT_IDLE_TIMEOUT_SECONDS,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Model $model): bool
    {
        return $model instanceof CodexModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, but a string was given to "%s".', self::class));
        }

        [$requestId, $options] = CodexCorrelationRequestId::resolve($options, $payload);
        $jsonBody = $this->requestBodyFactory->build($model, $payload, $options);
        $websocketUrl = $this->urlResolver->resolve($this->baseUrl, $this->responsesPath);

        $this->logRequestSummary($model, $jsonBody, $websocketUrl);

        $connection = $this->connectWithOptional401Refresh($model, $websocketUrl, $requestId);

        try {
            // Protocol frame type must win: merge body first, then force response.create.
            $frame = json_encode(
                array_merge($jsonBody, ['type' => 'response.create']),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );
            $connection->sendText($frame);
        } catch (\Throwable $e) {
            $this->closeConnectionQuietly($connection);

            throw new \RuntimeException('Codex WebSocket request frame could not be sent.', previous: $e);
        }

        return new RawWebSocketResult($connection, $this->idleTimeoutSeconds, $this->logger);
    }

    /**
     * @return array<string, string>
     */
    private function buildHandshakeHeaders(string $requestId): array
    {
        return $this->handshakeHeadersFactory->create(
            $this->accessToken,
            $this->accountId,
            $this->originator,
            $requestId,
        );
    }

    private function connectWithOptional401Refresh(Model $model, string $websocketUrl, string $requestId): WebsocketConnection
    {
        try {
            return $this->connector->connect(
                $websocketUrl,
                $this->buildHandshakeHeaders($requestId),
                $this->connectTimeoutSeconds,
            );
        } catch (WebsocketConnectException $e) {
            if (HttpStatus::UNAUTHORIZED !== $e->getResponse()->getStatus() || null === $this->accessTokenRefresher) {
                throw $this->toHandshakeRuntimeException($e);
            }

            $fresh = $this->refreshAccessTokenOnce($model);
            if (null === $fresh || $fresh === $this->accessToken) {
                throw $this->toHandshakeRuntimeException($e);
            }

            $this->accessToken = $fresh;
            $retryRequestId = CodexCorrelationRequestId::generate();

            $this->logger->info('codex.token.refreshed_on_401', [
                'event_type' => 'codex.token.refreshed_on_401',
                'component' => 'codex_websocket_model_client',
                'attempt' => 1,
            ]);

            try {
                return $this->connector->connect(
                    $websocketUrl,
                    $this->buildHandshakeHeaders($retryRequestId),
                    $this->connectTimeoutSeconds,
                );
            } catch (WebsocketConnectException $retry) {
                throw $this->toHandshakeRuntimeException($retry);
            }
        }
    }

    private function refreshAccessTokenOnce(?Model $model): ?string
    {
        try {
            return ($this->accessTokenRefresher)();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.token.refresh_failed', [
                'event_type' => 'codex.token.refresh_failed',
                'component' => 'codex_websocket_model_client',
                'model' => $model?->getName(),
                'attempt' => 1,
                'exception_class' => $e::class,
            ]);

            return null;
        }
    }

    private function closeConnectionQuietly(WebsocketConnection $connection): void
    {
        try {
            $connection->close();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.websocket.close_failed', [
                'event_type' => 'codex.websocket.close_failed',
                'component' => 'codex_websocket_model_client',
                'exception_class' => $e::class,
            ]);
        }
    }

    private function toHandshakeRuntimeException(WebsocketConnectException $e): \RuntimeException
    {
        $status = $e->getResponse()->getStatus();

        return new \RuntimeException(\sprintf('Codex WebSocket handshake failed with HTTP %d.', $status), previous: $e);
    }

    /**
     * Privacy-safe outgoing request summary: structural metadata only.
     *
     * @param array<string, mixed> $jsonBody
     */
    private function logRequestSummary(Model $model, array $jsonBody, string $websocketUrl): void
    {
        $input = $jsonBody['input'] ?? [];
        $inputCount = \is_array($input) ? \count($input) : 0;
        $tools = $jsonBody['tools'] ?? [];

        $inputTypes = [];
        if (\is_array($input)) {
            foreach ($input as $item) {
                if (isset($item['type']) && \is_string($item['type'])) {
                    $inputTypes[$item['type']] = true;
                }
                if (isset($item['role']) && \is_string($item['role'])) {
                    $inputTypes['role:'.$item['role']] = true;
                }
            }
        }

        $requestUrlPath = parse_url($websocketUrl, \PHP_URL_PATH);
        $requestUrlPath = \is_string($requestUrlPath) && '' !== $requestUrlPath
            ? $requestUrlPath
            : $this->responsesPath;

        $this->logger->info('llm.provider.request_prepared', [
            'event_type' => 'llm.provider.request_prepared',
            'transport' => 'websocket',
            'request_url_path' => $requestUrlPath,
            'model' => $model->getName(),
            'body_keys' => implode(', ', array_keys($jsonBody)),
            'input_count' => $inputCount,
            'input_types' => [] !== $inputTypes ? implode(', ', array_keys($inputTypes)) : 'none',
            'tool_count' => \is_array($tools) ? \count($tools) : 0,
            'has_instructions' => isset($jsonBody['instructions']),
            'has_reasoning' => isset($jsonBody['reasoning']),
            'has_include' => isset($jsonBody['include']),
            'has_text' => isset($jsonBody['text']),
            'has_store' => isset($jsonBody['store']),
            'has_stream' => isset($jsonBody['stream']),
            'originator' => $this->originator,
        ]);
    }
}
