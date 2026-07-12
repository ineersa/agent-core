<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Websocket\Client\WebsocketConnection;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectorInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketHandshakeHeadersFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketModelClient;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketUrlResolver;
use Symfony\AI\Platform\Bridge\OpenAICodex\RawWebSocketResult;

final class CodexWebSocketModelClientTest extends TestCase
{
    public function testSendsResponseCreateFrameWithSharedBodyMapping(): void
    {
        $captured = new \stdClass();
        $captured->url = '';
        $captured->headers = [];
        $captured->frame = '';

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('sendText')
            ->willReturnCallback(static function (string $data) use ($captured): void {
                $captured->frame = $data;
            });

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->once())
            ->method('connect')
            ->willReturnCallback(static function (string $url, array $headers, float $timeout) use ($connection, $captured): WebsocketConnection {
                $captured->url = $url;
                $captured->headers = $headers;

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
        );

        $result = $client->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'hi']]],
            ['temperature' => 0.5],
        );

        $this->assertInstanceOf(RawWebSocketResult::class, $result);
        $this->assertSame('wss://chatgpt.com/backend-api/codex/responses', $captured->url);
        $this->assertSame('Bearer access', $captured->headers['Authorization']);
        $this->assertSame('responses_websockets=2026-02-06', $captured->headers['OpenAI-Beta']);
        $this->assertSame('acct-1', $captured->headers['chatgpt-account-id']);
        $this->assertSame($captured->headers['session-id'], $captured->headers['x-client-request-id']);

        $frame = json_decode($captured->frame, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('response.create', $frame['type']);
        $this->assertSame('gpt-5.6-luna', $frame['model']);
        $this->assertSame('hi', $frame['input'][0]['content']);
        $this->assertFalse($frame['store']);
        $this->assertTrue($frame['stream']);
    }

    public function testSendFailureClosesConnection(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('sendText')
            ->willThrowException(new \RuntimeException('send failed'));
        $connection->expects($this->once())->method('close');

        $connector = $this->createStub(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturn($connection);

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
        );

        try {
            $client->request(
                new CodexModel('gpt-5.6-luna'),
                ['input' => [['role' => 'user', 'content' => 'hi']]],
            );
            $this->fail('Expected transport exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket request frame could not be sent.', $e->getMessage());
        }
    }
}
