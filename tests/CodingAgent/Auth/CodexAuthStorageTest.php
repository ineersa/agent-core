<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\GrokAuthRecord;
use Ineersa\CodingAgent\Auth\GrokAuthStorage;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class CodexAuthStorageTest extends TestCase
{
    private string $tmpDir;
    private CodexAuthStorage $storage;
    private CodexTokenRefresher $refresher;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-auth-test');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->storage = new CodexAuthStorage($this->tmpDir, $lockFactory);
        $this->refresher = new CodexTokenRefresher();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $record = new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh-token',
            expires: time() + 3600, // 1 hour from now (seconds)
            accountId: 'chat-abc123',
        );

        $this->storage->saveCredentials($record);

        $path = $this->tmpDir.'/'.CodexAuthStorage::AUTH_FILE;
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777, 'auth.json must be published with mode 0600');
        $this->assertSame([], glob($this->tmpDir.'/.hatfield/*.tmp.*') ?: [], 'No temp files should remain after save');

        $loaded = $this->storage->loadCredentials();

        $this->assertNotNull($loaded);
        $this->assertSame('test-access-token', $loaded->access);
        $this->assertSame('test-refresh-token', $loaded->refresh);
        $this->assertSame('chat-abc123', $loaded->accountId);
        $this->assertFalse($loaded->isExpired());
    }

    public function testMissingFileReturnsNull(): void
    {
        $loaded = $this->storage->loadCredentials();
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

        $this->storage->saveCredentials($expiredRecord);

        $loaded = $this->storage->loadCredentials();

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

        $storageWithRefresh->saveCredentials($expiredRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired and could not be refreshed');

        $storageWithRefresh->loadCredentials();
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
        $storageWithRefresh->saveCredentials($expiredRecord);

        // loadCredentialsRaw should return the raw record without attempting refresh
        $raw = $storageWithRefresh->loadCredentialsRaw();

        $this->assertNotNull($raw);
        $this->assertSame('expired-access-raw', $raw->access);
        $this->assertTrue($raw->isExpired());
    }

    public function testCodexSavePreservesGrokCredentials(): void
    {
        $lockFactory = new LockFactory(new FlockStore($this->tmpDir));
        $grok = new GrokAuthStorage($this->tmpDir, $lockFactory);
        $grok->saveCredentials(new GrokAuthRecord('grok-access', 'grok-refresh', time() + 3600));
        $this->storage->saveCredentials(new CodexAuthRecord('codex-access', 'codex-refresh', time() + 3600, 'account'));

        $this->assertSame('grok-access', $grok->loadCredentialsRaw()?->access);
        $this->assertSame('codex-access', $this->storage->loadCredentialsRaw()?->access);
    }

    public function testCorruptJsonThrowsRuntimeException(): void
    {
        $dir = $this->tmpDir.'/.hatfield';
        $path = $dir.'/auth.json';
        @file_put_contents($path, '{corrupt-json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt auth.json');
        $this->storage->loadCredentials();
    }

    public function testLoadCredentialsRawReturnsNullOnMissingFile(): void
    {
        $raw = $this->storage->loadCredentialsRaw();
        $this->assertNull($raw);
    }

    public function testExpiredRecordWithFailingRefresherShowsLoginHint(): void
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

        $storageWithRefresh->saveCredentials($expiredRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bin/console auth:codex');
        $storageWithRefresh->loadCredentials();
    }
}
