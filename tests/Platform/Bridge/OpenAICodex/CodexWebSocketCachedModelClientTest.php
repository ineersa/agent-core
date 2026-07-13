<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectorInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketHandshakeHeadersFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketModelClient;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketUrlResolver;
use Symfony\AI\Platform\Bridge\OpenAICodex\RawWebSocketResult;

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
}
