<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\CodexAuthRecord;
use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Auth\CodexTokenRefresher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class CodexAuthStorageTest extends TestCase
{
    private string $tmpDir;
    private CodexAuthStorage $storage;
    private CodexTokenRefresher $refresher;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-auth-test-'.bin2hex(random_bytes(8));
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->storage = new CodexAuthStorage($this->tmpDir, $lockFactory);
        $this->refresher = new CodexTokenRefresher();
    }

    protected function tearDown(): void
    {
        $path = $this->tmpDir.'/'.CodexOAuthConfig::AUTH_FILE;
        if (file_exists($path)) {
            @unlink($path);
        }
        @rmdir($this->tmpDir.'/.hatfield');
        @rmdir($this->tmpDir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $record = new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh-token',
            expires: time() + 3600, // 1 hour from now (seconds)
            accountId: 'chat-abc123',
        );

        $this->storage->saveCredentials('openai-codex', $record);

        $loaded = $this->storage->loadCredentials('openai-codex');

        $this->assertNotNull($loaded);
        $this->assertSame('test-access-token', $loaded->access);
        $this->assertSame('test-refresh-token', $loaded->refresh);
        $this->assertSame('chat-abc123', $loaded->accountId);
        $this->assertFalse($loaded->isExpired());
    }

    public function testMissingFileReturnsNull(): void
    {
        $loaded = $this->storage->loadCredentials('openai-codex');
        $this->assertNull($loaded);
    }

    public function testExpiredRecordWithoutRefresherReturnsExpired(): void
    {
        // Save an expired record — no refresher is configured in $this->storage,
        // so loadCredentials returns it without attempting refresh.
        $expiredRecord = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'i-will-be-refreshed',
            expires: time() - 3600, // already expired (seconds)
            accountId: 'chat-old',
        );

        $this->storage->saveCredentials('openai-codex', $expiredRecord);

        $loaded = $this->storage->loadCredentials('openai-codex');

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isExpired());
        $this->assertSame('expired-access', $loaded->access);
    }

    public function testExpiredRecordWithRefresherThrowsOnRefreshFailure(): void
    {
        // Storage WITH a refresher configured
        $storageWithRefresh = new CodexAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $this->refresher);

        $expiredRecord = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'invalid-refresh-token',
            expires: time() - 3600,
            accountId: 'chat-old',
        );

        $storageWithRefresh->saveCredentials('openai-codex', $expiredRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired and could not be refreshed');

        $storageWithRefresh->loadCredentials('openai-codex');
    }

    public function testLoadCredentialsRawReturnsExpiredWithoutRefresh(): void
    {
        $expiredRecord = new CodexAuthRecord(
            access: 'expired-access-raw',
            refresh: 'some-refresh',
            expires: time() - 3600,
            accountId: 'chat-raw',
        );

        $storageWithRefresh = new CodexAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $this->refresher);
        $storageWithRefresh->saveCredentials('openai-codex', $expiredRecord);

        // loadCredentialsRaw should return the raw record without attempting refresh
        $raw = $storageWithRefresh->loadCredentialsRaw('openai-codex');

        $this->assertNotNull($raw);
        $this->assertSame('expired-access-raw', $raw->access);
        $this->assertTrue($raw->isExpired());
    }

    public function testMultipleProviderKeysCoexist(): void
    {
        $record1 = new CodexAuthRecord('tok1', 'ref1', time() + 3600, 'acct1');
        $record2 = new CodexAuthRecord('tok2', 'ref2', time() + 3600, 'acct2');

        $this->storage->saveCredentials('openai-codex', $record1);
        $this->storage->saveCredentials('other-provider', $record2);

        $loaded1 = $this->storage->loadCredentials('openai-codex');
        $loaded2 = $this->storage->loadCredentials('other-provider');

        $this->assertSame('tok1', $loaded1?->access);
        $this->assertSame('tok2', $loaded2?->access);
    }

    public function testRemoveCredentials(): void
    {
        $record = new CodexAuthRecord('tok', 'ref', time() + 3600, 'acct');
        $this->storage->saveCredentials('openai-codex', $record);
        $this->storage->removeCredentials('openai-codex');

        $loaded = $this->storage->loadCredentials('openai-codex');
        $this->assertNull($loaded);
    }

    public function testCorruptJsonThrowsRuntimeException(): void
    {
        $dir = $this->tmpDir.'/.hatfield';
        $path = $dir.'/auth.json';
        @file_put_contents($path, '{corrupt-json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt auth.json');
        $this->storage->loadCredentials('openai-codex');
    }

    public function testLoadCredentialsRawReturnsNullOnMissingFile(): void
    {
        $raw = $this->storage->loadCredentialsRaw('nonexistent');
        $this->assertNull($raw);
    }

    public function testExpiredProfileRecordWithFailingRefresherShowsProfileHint(): void
    {
        // Create a refresher that always throws so we can assert the error message
        // without depending on the network.
        $failingRefresher = new class extends CodexTokenRefresher {
            public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
            {
                throw new \RuntimeException('Simulated refresh failure.');
            }
        };

        $storageWithRefresh = new CodexAuthStorage(
            $this->tmpDir,
            new LockFactory(new FlockStore($this->tmpDir)),
            $failingRefresher,
        );

        $expiredRecord = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'invalid-refresh-token',
            expires: time() - 3600,
            accountId: 'chat-old',
        );

        $storageWithRefresh->saveCredentials('openai-codex-work', $expiredRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--auth-profile=work');

        $storageWithRefresh->loadCredentials('openai-codex-work');
    }

    public function testExpiredDefaultRecordWithFailingRefresherShowsNoProfileHint(): void
    {
        $failingRefresher = new class extends CodexTokenRefresher {
            public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
            {
                throw new \RuntimeException('Simulated refresh failure.');
            }
        };

        $storageWithRefresh = new CodexAuthStorage(
            $this->tmpDir,
            new LockFactory(new FlockStore($this->tmpDir)),
            $failingRefresher,
        );

        $expiredRecord = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'invalid-refresh-token',
            expires: time() - 3600,
            accountId: 'chat-old',
        );

        $storageWithRefresh->saveCredentials('openai-codex', $expiredRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired and could not be refreshed');

        try {
            $storageWithRefresh->loadCredentials('openai-codex');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('--auth-profile=', $e->getMessage());
            throw $e;
        }
    }
}
