<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Websocket\Client\WebsocketConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheSettings;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCompatibilityFingerprint;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;

#[AllowMockObjectsWithoutExpectations]
final class CodexWebSocketConnectionCacheTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testReusesIdleConnectionForSameSessionIdentity(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings(idleTtlSeconds: 300, maxAgeSeconds: 3300);
        $identity = $this->identity('0194aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee', 'token-a');

        $connectCount = 0;
        $connection = $this->createMock(WebsocketConnection::class);

        $lease1 = $cache->acquire($identity, $settings, static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });
        $this->assertFalse($lease1->reused);
        $cache->release($lease1, true);

        $lease2 = $cache->acquire($identity, $settings, static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });
        $this->assertTrue($lease2->reused);
        $this->assertSame(1, $connectCount);
    }

    public function testBusyLeaseCreatesOneShotWithoutSecondCacheEntry(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $identity = $this->identity('0194bbbb-bbbb-7ccc-8ddd-eeeeeeeeeeee', 'token-a');

        $primary = $this->createMock(WebsocketConnection::class);
        $oneShot = $this->createMock(WebsocketConnection::class);
        $oneShot->expects($this->once())->method('close');

        $leaseBusy = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $primary);
        $this->assertFalse($leaseBusy->reused);

        $leaseOneShot = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $oneShot);
        $this->assertTrue($leaseOneShot->oneShot);
        $cache->release($leaseOneShot, false);

        $cache->release($leaseBusy, true);
        $leaseAfter = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $primary);
        $this->assertTrue($leaseAfter->reused);
    }

    public function testIdentityMismatchReplacesCachedEntry(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $sessionKey = '0194cccc-bbbb-7ccc-8ddd-eeeeeeeeeeee';

        $first = $this->createMock(WebsocketConnection::class);
        $first->expects($this->once())->method('close');
        $second = $this->createMock(WebsocketConnection::class);

        $lease1 = $cache->acquire($this->identity($sessionKey, 'token-a'), $settings, static fn (): WebsocketConnection => $first);
        $cache->release($lease1, true);

        $lease2 = $cache->acquire($this->identity($sessionKey, 'token-b'), $settings, static fn (): WebsocketConnection => $second);
        $this->assertFalse($lease2->reused);
    }

    public function testCloseAllClosesOwnedConnections(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())->method('close');

        $lease = $cache->acquire($this->identity('0194dddd-bbbb-7ccc-8ddd-eeeeeeeeeeee', 'token-a'), $settings, static fn (): WebsocketConnection => $connection);
        $cache->release($lease, true);
        $cache->closeAll();
    }

    private function identity(string $sessionKey, string $token): CodexWebSocketCompatibilityFingerprint
    {
        self::assertUuidVersion7($sessionKey);

        return CodexWebSocketCompatibilityFingerprint::fromContext(
            $sessionKey,
            'openai-codex',
            'gpt-5.6-luna',
            'https://chatgpt.com/backend-api',
            '/codex/responses',
            'acct-1',
            $token,
        );
    }
}
