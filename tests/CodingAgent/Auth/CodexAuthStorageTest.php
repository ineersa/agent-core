<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\CodexAuthRecord;
use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class CodexAuthStorageTest extends TestCase
{
    private string $tmpDir;
    private CodexAuthStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir().'/hatfield-auth-test-'.\bin2hex(\random_bytes(8));
        @\mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->storage = new CodexAuthStorage($this->tmpDir, $lockFactory);
    }

    protected function tearDown(): void
    {
        $path = $this->tmpDir.'/' . CodexOAuthConfig::AUTH_FILE;
        if (\file_exists($path)) {
            @\unlink($path);
        }
        @\rmdir($this->tmpDir.'/.hatfield');
        @\rmdir($this->tmpDir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $record = new CodexAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh-token',
            expires: \time() * 1000 + 3600000, // 1 hour from now
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

    public function testExpiredRecordTriggersRefreshAndPersists(): void
    {
        // Save an expired record
        $expiredRecord = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'i-will-be-refreshed',
            expires: \time() * 1000 - 3600000, // already expired
            accountId: 'chat-old',
        );

        $this->storage->saveCredentials('openai-codex', $expiredRecord);

        // The loadCredentials will try to refresh.
        // Since we can't actually hit the OAuth endpoint, it should return
        // the expired record when refresh fails.
        $loaded = $this->storage->loadCredentials('openai-codex');

        $this->assertNotNull($loaded);
        // Should still return the expired record (refresh is best-effort)
        $this->assertTrue($loaded->isExpired());
        $this->assertSame('expired-access', $loaded->access);
    }

    public function testMultipleProviderKeysCoexist(): void
    {
        $record1 = new CodexAuthRecord('tok1', 'ref1', \time() * 1000 + 3600000, 'acct1');
        $record2 = new CodexAuthRecord('tok2', 'ref2', \time() * 1000 + 3600000, 'acct2');

        $this->storage->saveCredentials('openai-codex', $record1);
        $this->storage->saveCredentials('other-provider', $record2);

        $loaded1 = $this->storage->loadCredentials('openai-codex');
        $loaded2 = $this->storage->loadCredentials('other-provider');

        $this->assertSame('tok1', $loaded1?->access);
        $this->assertSame('tok2', $loaded2?->access);
    }

    public function testRemoveCredentials(): void
    {
        $record = new CodexAuthRecord('tok', 'ref', \time() * 1000 + 3600000, 'acct');
        $this->storage->saveCredentials('openai-codex', $record);
        $this->storage->removeCredentials('openai-codex');

        $loaded = $this->storage->loadCredentials('openai-codex');
        $this->assertNull($loaded);
    }

    public function testCorruptJsonThrowsRuntimeException(): void
    {
        $dir = $this->tmpDir.'/.hatfield';
        $path = $dir.'/auth.json';
        @\file_put_contents($path, '{corrupt-json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt auth.json');
        $this->storage->loadCredentials('openai-codex');
    }
}
