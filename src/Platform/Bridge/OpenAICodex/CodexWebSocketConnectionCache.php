<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Websocket\Client\WebsocketConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Process-scoped cache of Codex WebSocket connections keyed by session correlation identity.
 */
final class CodexWebSocketConnectionCache
{
    /** @var array<string, CodexWebSocketCacheEntry> */
    private array $entries = [];

    /** @var array<string, string> */
    private array $idleTimerIds = [];

    private readonly LoggerInterface $logger;

    private readonly ClockInterface $clock;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? new NativeClock();
    }

    public function acquire(
        CodexWebSocketCompatibilityFingerprint $identity,
        CodexWebSocketCacheSettings $settings,
        callable $connect,
    ): CodexWebSocketCacheLease {
        $sessionKey = $identity->sessionKey;
        $existing = $this->entries[$sessionKey] ?? null;

        if (null !== $existing) {
            $this->cancelIdleTimer($sessionKey);

            // Busy wins: never close, expire, or replace an actively streaming cached socket.
            if ($existing->busy) {
                $connection = $connect();
                $this->logger->info('codex.websocket.cache.busy_one_shot', [
                    'event_type' => 'codex.websocket.cache.busy_one_shot',
                    'component' => 'codex_websocket_connection_cache',
                    'session_key_length' => \strlen($sessionKey),
                ]);

                return new CodexWebSocketCacheLease($connection, false, false, true, null);
            }

            if ($existing->identity->fingerprint !== $identity->fingerprint) {
                $this->closeEntry($existing, 'identity_mismatch');
                unset($this->entries[$sessionKey]);
                $existing = null;
            } elseif ($this->isExpired($existing, $settings)) {
                $this->closeEntry($existing, 'max_age');
                unset($this->entries[$sessionKey]);
                $existing = null;
            } else {
                $existing->busy = true;
                $this->logger->info('codex.websocket.cache.reused', [
                    'event_type' => 'codex.websocket.cache.reused',
                    'component' => 'codex_websocket_connection_cache',
                    'session_key_length' => \strlen($sessionKey),
                ]);

                return new CodexWebSocketCacheLease($existing->connection, true, true, false, $existing);
            }
        }

        $connection = $connect();
        $entry = new CodexWebSocketCacheEntry(
            $connection,
            $identity,
            $this->clock->now()->getTimestamp(),
            $settings,
        );
        $this->entries[$sessionKey] = $entry;

        $this->logger->info('codex.websocket.cache.created', [
            'event_type' => 'codex.websocket.cache.created',
            'component' => 'codex_websocket_connection_cache',
            'session_key_length' => \strlen($sessionKey),
        ]);

        return new CodexWebSocketCacheLease($connection, false, true, false, $entry);
    }

    public function release(CodexWebSocketCacheLease $lease, bool $keepInCache): void
    {
        if ($lease->oneShot || !$lease->cached || null === $lease->entry) {
            $this->closeConnection($lease->connection, 'one_shot');

            return;
        }

        $sessionKey = $lease->entry->identity->sessionKey;
        if (($this->entries[$sessionKey] ?? null) !== $lease->entry) {
            $this->closeConnection($lease->connection, 'stale_entry');

            return;
        }

        if (!$keepInCache) {
            $this->closeEntry($lease->entry, 'release_discard');
            unset($this->entries[$sessionKey]);
            $this->cancelIdleTimer($sessionKey);

            return;
        }

        $lease->entry->busy = false;
        $this->scheduleIdleExpiry($sessionKey, $lease->entry, $lease->entry->settings);
    }

    public function invalidateEntry(?CodexWebSocketCacheEntry $entry, string $reason): void
    {
        if (null === $entry) {
            return;
        }

        $sessionKey = $entry->identity->sessionKey;
        if (($this->entries[$sessionKey] ?? null) !== $entry) {
            $this->closeConnection($entry->connection, $reason);

            return;
        }

        $entry->continuation = null;
        $this->closeEntry($entry, $reason);
        unset($this->entries[$sessionKey]);
        $this->cancelIdleTimer($sessionKey);

        $this->logger->info('codex.websocket.cache.reset', [
            'event_type' => 'codex.websocket.cache.reset',
            'component' => 'codex_websocket_connection_cache',
            'reason' => $reason,
            'session_key_length' => \strlen($sessionKey),
        ]);
    }

    public function closeSession(string $sessionKey): void
    {
        $entry = $this->entries[$sessionKey] ?? null;
        if (null === $entry) {
            return;
        }

        $this->invalidateEntry($entry, 'session_close');
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->entries) as $sessionKey) {
            $this->closeSession($sessionKey);
        }
    }

    private function isExpired(CodexWebSocketCacheEntry $entry, CodexWebSocketCacheSettings $settings): bool
    {
        $now = $this->clock->now()->getTimestamp();

        return ($now - $entry->createdAt) >= $settings->maxAgeSeconds;
    }

    private function scheduleIdleExpiry(string $sessionKey, CodexWebSocketCacheEntry $entry, CodexWebSocketCacheSettings $settings): void
    {
        $this->cancelIdleTimer($sessionKey);
        $ttl = $settings->idleTtlSeconds;
        $this->idleTimerIds[$sessionKey] = EventLoop::delay((float) $ttl, function () use ($sessionKey, $entry): void {
            unset($this->idleTimerIds[$sessionKey]);
            if (($this->entries[$sessionKey] ?? null) !== $entry) {
                return;
            }
            if ($entry->busy) {
                return;
            }
            $this->invalidateEntry($entry, 'idle_ttl');
            $this->logger->info('codex.websocket.cache.expired', [
                'event_type' => 'codex.websocket.cache.expired',
                'component' => 'codex_websocket_connection_cache',
                'reason' => 'idle_ttl',
                'session_key_length' => \strlen($sessionKey),
            ]);
        });
    }

    private function cancelIdleTimer(string $sessionKey): void
    {
        $timerId = $this->idleTimerIds[$sessionKey] ?? null;
        if (null === $timerId) {
            return;
        }
        EventLoop::cancel($timerId);
        unset($this->idleTimerIds[$sessionKey]);
    }

    private function closeEntry(CodexWebSocketCacheEntry $entry, string $reason): void
    {
        $entry->continuation = null;
        $this->closeConnection($entry->connection, $reason);
    }

    private function closeConnection(WebsocketConnection $connection, string $reason): void
    {
        try {
            $connection->close();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.websocket.close_failed', [
                'event_type' => 'codex.websocket.close_failed',
                'component' => 'codex_websocket_connection_cache',
                'reason' => $reason,
                'exception_class' => $e::class,
            ]);
        }
    }
}
