<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\GrokAuthRecord;
use Ineersa\CodingAgent\Auth\GrokAuthStorage;
use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use Ineersa\CodingAgent\Auth\GrokTokenRefresher;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class GrokAuthStorageTest extends TestCase
{
    private string $tmpDir;
    private GrokAuthStorage $storage;
    private GrokTokenRefresher $refresher;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-grok-auth-test');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->storage = new GrokAuthStorage($this->tmpDir, $lockFactory);
        $this->refresher = new GrokTokenRefresher();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $record = new GrokAuthRecord(
            access: 'test-access-token',
            refresh: 'test-refresh-token',
            expires: time() + 3600,
        );

        $this->storage->saveCredentials('grok-cli', $record);

        $path = $this->tmpDir.'/'.GrokOAuthConfig::AUTH_FILE;
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777, 'auth.json must be published with mode 0600');
        $this->assertSame([], glob($this->tmpDir.'/.hatfield/*.tmp.*') ?: [], 'No temp files should remain after save');

        $loaded = $this->storage->loadCredentials('grok-cli');

        $this->assertNotNull($loaded);
        $this->assertSame('test-access-token', $loaded->access);
        $this->assertSame('test-refresh-token', $loaded->refresh);
        $this->assertFalse($loaded->isExpired());
    }

    public function testMissingFileReturnsNull(): void
    {
        $this->assertNull($this->storage->loadCredentials('grok-cli'));
    }

    public function testExpiredRecordWithoutRefresherReturnsExpired(): void
    {
        $expiredRecord = new GrokAuthRecord(
            access: 'expired-access',
            refresh: 'i-will-be-refreshed',
            expires: time() - 3600,
        );

        $this->storage->saveCredentials('grok-cli', $expiredRecord);

        $loaded = $this->storage->loadCredentials('grok-cli');

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isExpired());
        $this->assertSame('expired-access', $loaded->access);
    }

    public function testExpiredRecordWithRefresherThrowsOnRefreshFailure(): void
    {
        $storageWithRefresh = new GrokAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $this->refresher);

        $expiredRecord = new GrokAuthRecord(
            access: 'expired-access',
            refresh: 'invalid-refresh-token',
            expires: time() - 3600,
        );

        $storageWithRefresh->saveCredentials('grok-cli', $expiredRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired and could not be refreshed');

        $storageWithRefresh->loadCredentials('grok-cli');
    }

    public function testLoadCredentialsRawReturnsExpiredWithoutRefresh(): void
    {
        $expiredRecord = new GrokAuthRecord(
            access: 'expired-access-raw',
            refresh: 'some-refresh',
            expires: time() - 3600,
        );

        $storageWithRefresh = new GrokAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $this->refresher);
        $storageWithRefresh->saveCredentials('grok-cli', $expiredRecord);

        $raw = $storageWithRefresh->loadCredentialsRaw('grok-cli');
        $this->assertNotNull($raw);
        $this->assertTrue($raw->isExpired());
        $this->assertSame('expired-access-raw', $raw->access);
    }

    public function testRemoveCredentials(): void
    {
        $this->storage->saveCredentials('grok-cli', new GrokAuthRecord(
            access: 'a',
            refresh: 'r',
            expires: time() + 100,
        ));
        $this->storage->removeCredentials('grok-cli');
        $this->assertNull($this->storage->loadCredentials('grok-cli'));
    }

    public function testCoexistsWithCodexKeyInSameFile(): void
    {
        $path = $this->tmpDir.'/'.GrokOAuthConfig::AUTH_FILE;
        file_put_contents($path, json_encode([
            'openai-codex' => [
                'type' => 'oauth',
                'access' => 'codex-a',
                'refresh' => 'codex-r',
                'expires' => time() + 100,
                'accountId' => 'acct',
            ],
        ], \JSON_THROW_ON_ERROR));
        chmod($path, 0600);

        $this->storage->saveCredentials('grok-cli', new GrokAuthRecord(
            access: 'grok-a',
            refresh: 'grok-r',
            expires: time() + 100,
        ));

        $data = json_decode((string) file_get_contents($path), true, 8, \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('openai-codex', $data);
        $this->assertArrayHasKey('grok-cli', $data);
        $this->assertSame('codex-a', $data['openai-codex']['access']);
        $this->assertSame('grok-a', $data['grok-cli']['access']);
    }
}
