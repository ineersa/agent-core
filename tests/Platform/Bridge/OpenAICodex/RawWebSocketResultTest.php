<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\CancelledException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCachedStreamContext;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheEntry;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheLease;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheSettings;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCompatibilityFingerprint;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketResultHandle;
use Symfony\AI\Platform\Bridge\OpenAICodex\RawWebSocketResult;

final class RawWebSocketResultTest extends TestCase
{
    public function testStreamsDecodedEventsAndClosesAfterTerminalWithoutExtraReceive(): void
    {
        $messages = [
            WebsocketMessage::fromText(json_encode(['type' => 'response.output_text.delta', 'delta' => 'hi'], \JSON_THROW_ON_ERROR)),
            WebsocketMessage::fromText(json_encode(['type' => 'response.completed', 'response' => ['output' => []]], \JSON_THROW_ON_ERROR)),
        ];
        $index = 0;

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->exactly(2))
            ->method('receive')
            ->willReturnCallback(static function () use (&$index, $messages): WebsocketMessage {
                if ($index >= \count($messages)) {
                    self::fail('receive() must not be called after terminal event');
                }

                return $messages[$index++];
            });
        $connection->expects($this->once())->method('close');

        $raw = new RawWebSocketResult($connection, 5.0);
        $events = iterator_to_array($raw->getDataStream());

        $this->assertCount(2, $events);
        $this->assertSame('response.output_text.delta', $events[0]['type']);
        $this->assertInstanceOf(CodexWebSocketResultHandle::class, $raw->getObject());
    }

    public function testIdleTimeoutMapsToExplicitTransportException(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('receive')
            ->willThrowException(new CancelledException());
        $connection->expects($this->once())->method('close');

        $raw = new RawWebSocketResult($connection, 0.01);

        try {
            iterator_to_array($raw->getDataStream());
            $this->fail('Expected idle timeout exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket idle timeout.', $e->getMessage());
            $this->assertInstanceOf(CancelledException::class, $e->getPrevious());
        }
    }

    public function testNonTextFrameIsProtocolError(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('receive')->willReturn(WebsocketMessage::fromBinary('binary'));
        $connection->expects($this->once())->method('close');

        $raw = new RawWebSocketResult($connection, 5.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex WebSocket frame was not a text message.');

        iterator_to_array($raw->getDataStream());
    }

    public function testAbortAndFinallyCloseConnectionOnce(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->never())->method('receive');
        $connection->expects($this->once())->method('close');

        $logger = new TestLogger();
        $raw = new RawWebSocketResult($connection, 5.0, $logger);
        $raw->abort();
        iterator_to_array($raw->getDataStream());
    }

    public function testCloseFailureLogsStructuredEvent(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->never())->method('receive');
        $connection->expects($this->once())->method('close')->willThrowException(new \RuntimeException('close failed'));

        $logger = new TestLogger();
        $raw = new RawWebSocketResult($connection, 5.0, $logger);
        $raw->abort();

        $this->assertCount(1, $logger->records);
        $this->assertSame('codex.websocket.close_failed', $logger->records[0]['message']);
        $this->assertSame('codex.websocket.close_failed', $logger->records[0]['context']['event_type']);
        $this->assertSame('raw_websocket_result', $logger->records[0]['context']['component']);
        $this->assertSame(\RuntimeException::class, $logger->records[0]['context']['exception_class']);
    }

    public function testCachedStreamFailureInvalidatesWithoutRetainingConnection(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $identity = CodexWebSocketCompatibilityFingerprint::fromContext(
            '0194bbbb-bbbb-7ccc-8ddd-bbbbbbbbbbbb',
            'openai-codex',
            'gpt-5.6-luna',
            'https://chatgpt.com/backend-api',
            '/codex/responses',
            'acct-1',
        );
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('receive')->willReturn(WebsocketMessage::fromText(json_encode(['type' => 'response.failed'], \JSON_THROW_ON_ERROR)));
        $connection->expects($this->once())->method('close');

        $entry = new CodexWebSocketCacheEntry($connection, $identity, time(), $settings);
        $entry->continuation = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['input' => []],
            'resp-old',
            [],
        );
        $lease = new CodexWebSocketCacheLease($connection, true, true, false, $entry);
        $context = new CodexWebSocketCachedStreamContext($cache, $lease, ['input' => []]);

        $reflection = new \ReflectionClass($cache);
        $prop = $reflection->getProperty('entries');
        $prop->setValue($cache, [$identity->sessionKey => $entry]);

        $raw = new RawWebSocketResult($connection, 5.0, cachedStreamContext: $context);

        try {
            iterator_to_array($raw->getDataStream());
            $this->fail('Expected stream failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('non-success terminal', $e->getMessage());
        }

        $this->assertNull($entry->continuation);
        $this->assertSame([], $prop->getValue($cache));
    }

    public function testDestructorClosesConnectionWhenStreamNeverConsumed(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->never())->method('receive');
        $connection->expects($this->once())->method('close');

        $raw = new RawWebSocketResult($connection, 5.0);
        unset($raw);
    }
}
