<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheSettings;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectorInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketHandshakeHeadersFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketModelClient;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketUrlResolver;
use Symfony\AI\Platform\Bridge\OpenAICodex\RawWebSocketResult;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
final class CodexWebSocketCachedModelClientTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testSecondCompatibleRequestReusesConnectionAndSendsDelta(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee';
        self::assertUuidVersion7($cacheKey);

        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: new CodexWebSocketConnectionCache(),
        );

        $options = ['provider_cache_key' => $cacheKey];
        $firstPayload = ['input' => [['role' => 'user', 'content' => 'first']]];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), $firstPayload, $options);
        $this->assertInstanceOf(RawWebSocketResult::class, $first);
        iterator_to_array($first->getDataStream());

        $secondPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
        ];
        $second = $client->request(new CodexModel('gpt-5.6-luna'), $secondPayload, $options);
        iterator_to_array($second->getDataStream());

        $this->assertSame(1, $connectCount);
        $this->assertCount(2, $frames);
        $secondFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('resp_cached_1', $secondFrame['previous_response_id']);
        $this->assertCount(1, $secondFrame['input']);
        $this->assertSame('second', $secondFrame['input'][0]['content']);
    }

    /**
     * Session-33 regression: when a tool-call turn streams function_call via
     * response.output_item.done but the terminal response.output is empty, the
     * cached continuation baseline must still own that function_call so the
     * next previous_response_id request sends only the matching function_call_output.
     */
    public function testToolContinuationDeltaOmitsReplayOfStreamedFunctionCallWhenTerminalOutputEmpty(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-ffffffffffff';
        self::assertUuidVersion7($cacheKey);

        $functionCall = [
            'type' => 'function_call',
            'id' => 'fc_streamed_1',
            'call_id' => 'fc_streamed_1',
            'name' => 'hatfield_docs',
            'arguments' => '{"path":"docs/settings.md"}',
        ];
        $functionCallOutput = [
            'type' => 'function_call_output',
            'call_id' => 'fc_streamed_1',
            'output' => '{"ok":true}',
        ];

        $inboundQueues = [
            [
                [
                    'type' => 'response.output_item.done',
                    'item' => $functionCall,
                ],
                [
                    'type' => 'response.completed',
                    'response' => [
                        'id' => 'resp_tool_1',
                        // Empty/absent terminal output is the failure mode from session 33.
                        'output' => [],
                    ],
                ],
            ],
            [
                [
                    'type' => 'response.completed',
                    'response' => [
                        'id' => 'resp_tool_2',
                        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => 'done']],
                    ],
                ],
            ],
        ];

        $connectCount = 0;
        $frames = [];
        $connection = $this->createQueuedStreamingConnection($frames, $inboundQueues);

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: new CodexWebSocketConnectionCache(),
        );

        $options = ['provider_cache_key' => $cacheKey];
        $firstPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'read docs'],
            ],
        ];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), $firstPayload, $options);
        iterator_to_array($first->getDataStream());

        $secondPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'read docs'],
                $functionCall,
                $functionCallOutput,
            ],
        ];
        $second = $client->request(new CodexModel('gpt-5.6-luna'), $secondPayload, $options);
        iterator_to_array($second->getDataStream());

        $this->assertSame(1, $connectCount);
        $this->assertCount(2, $frames);

        $secondFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('resp_tool_1', $secondFrame['previous_response_id'] ?? null);
        // Explicitly prove the prior function_call was not replayed in the delta.
        $this->assertSame([$functionCallOutput], $secondFrame['input']);
    }

    public function testPostIdleExpirySendsFullContextWithoutPreviousResponseId(): void
    {
        $cacheKey = '0194ffff-bbbb-7ccc-8ddd-444444444444';
        self::assertUuidVersion7($cacheKey);

        $clock = new MockClock(new \DateTimeImmutable('2026-07-13 20:44:00'));
        $cache = new CodexWebSocketConnectionCache(clock: $clock);
        $settings = new CodexWebSocketCacheSettings(idleTtlSeconds: 300, maxAgeSeconds: 3300);

        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: $cache,
            cacheSettings: $settings,
        );

        $options = ['provider_cache_key' => $cacheKey];
        $firstPayload = ['input' => [['role' => 'user', 'content' => 'first']]];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), $firstPayload, $options);
        iterator_to_array($first->getDataStream());

        $clock->sleep(308);

        $secondPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'after-idle'],
            ],
        ];
        $second = $client->request(new CodexModel('gpt-5.6-luna'), $secondPayload, $options);
        iterator_to_array($second->getDataStream());

        $this->assertSame(2, $connectCount);
        $this->assertCount(2, $frames);
        $secondFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('previous_response_id', $secondFrame);
        $this->assertCount(3, $secondFrame['input']);
        $this->assertSame('after-idle', $secondFrame['input'][2]['content']);
    }

    public function testPlainWebsocketStillOpensConnectionPerRequest(): void
    {
        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);
        $connection->expects($this->exactly(2))->method('close');

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::Websocket,
        );

        foreach ([1, 2] as $_) {
            $result = $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'hi']]]);
            iterator_to_array($result->getDataStream());
        }

        $this->assertSame(2, $connectCount);
    }

    public function testBusyOneShotSendFailureClosesConnectionOnce(): void
    {
        $cacheKey = '0194aaaa-bbbb-7ccc-8ddd-aaaaaaaaaaaa';
        self::assertUuidVersion7($cacheKey);

        $frames = [];
        $primary = $this->createStreamingConnection($frames);
        $oneShot = $this->createMock(WebsocketConnection::class);
        $oneShot->expects($this->once())->method('close');
        $oneShot->expects($this->once())->method('sendText')->willThrowException(new \RuntimeException('send failed'));

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnOnConsecutiveCalls($primary, $oneShot);

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: new CodexWebSocketConnectionCache(),
        );

        $options = ['provider_cache_key' => $cacheKey];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'first']]], $options);

        try {
            $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'second']]], $options);
            $this->fail('Expected send failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket request frame could not be sent.', $e->getMessage());
        }

        iterator_to_array($first->getDataStream());
    }

    public function testGeneratedCorrelationBypassesCacheOn401AndAlignsPromptCacheKey(): void
    {
        $connectCalls = [];
        $sentFrame = '';
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('sendText')->willReturnCallback(static function (string $data) use (&$sentFrame): void {
            $sentFrame = $data;
        });
        $connection->method('receive')->willReturnCallback(static function (): WebsocketMessage {
            return WebsocketMessage::fromText(json_encode(['type' => 'response.completed', 'response' => ['id' => 'r1', 'output' => []]], \JSON_THROW_ON_ERROR));
        });

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(function (string $url, array $headers, float $timeout) use (&$connectCalls, $connection) {
            $connectCalls[] = $headers;
            if (1 === \count($connectCalls)) {
                throw $this->websocketConnectException(401);
            }

            return $connection;
        });

        $cache = new CodexWebSocketConnectionCache();
        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'stale',
            'acct-1',
            accessTokenRefresher: static fn (): string => 'fresh',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: $cache,
        );

        $result = $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'hi']]]);
        iterator_to_array($result->getDataStream());

        $this->assertCount(2, $connectCalls);
        $this->assertNotSame($connectCalls[0]['session-id'], $connectCalls[1]['session-id']);
        $frame = json_decode($sentFrame, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($connectCalls[1]['session-id'], $frame['prompt_cache_key']);

        $reflection = new \ReflectionClass($cache);
        $prop = $reflection->getProperty('entries');
        $this->assertSame([], $prop->getValue($cache));
    }

    /**
     * @param list<string> $frames
     */
    private function createStreamingConnection(array &$frames): WebsocketConnection
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('sendText')->willReturnCallback(static function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        });
        $connection->method('receive')->willReturnCallback(static function (): WebsocketMessage {
            return WebsocketMessage::fromText(json_encode([
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_cached_1',
                    'output' => [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        return $connection;
    }

    /**
     * @param list<string>                                    $frames
     * @param list<list<array<string, mixed>>>                $inboundQueues per request stream
     */
    private function createQueuedStreamingConnection(array &$frames, array $inboundQueues): WebsocketConnection
    {
        $requestIndex = -1;
        $eventIndex = 0;

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('sendText')->willReturnCallback(static function (string $frame) use (&$frames, &$requestIndex, &$eventIndex): void {
            $frames[] = $frame;
            ++$requestIndex;
            $eventIndex = 0;
        });
        $connection->method('receive')->willReturnCallback(static function () use (&$requestIndex, &$eventIndex, $inboundQueues): WebsocketMessage {
            if ($requestIndex < 0 || !isset($inboundQueues[$requestIndex])) {
                self::fail('receive() without a matching sendText() request stream');
            }

            $queue = $inboundQueues[$requestIndex];
            if (!isset($queue[$eventIndex])) {
                self::fail('receive() exhausted inbound queue for request '.$requestIndex);
            }

            $event = $queue[$eventIndex];
            ++$eventIndex;

            return WebsocketMessage::fromText(json_encode($event, \JSON_THROW_ON_ERROR));
        });

        return $connection;
    }

    private function websocketConnectException(int $status): WebsocketConnectException
    {
        $request = new Request('wss://chatgpt.com/backend-api/codex/responses');
        $response = new Response('1.1', $status, null, [], null, $request);

        return new WebsocketConnectException('upgrade failed', $response);
    }
}
