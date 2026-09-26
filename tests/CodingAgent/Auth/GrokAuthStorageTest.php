<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\GrokAuthRecord;
use Ineersa\CodingAgent\Auth\GrokAuthStorage;
use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use Ineersa\CodingAgent\Auth\GrokTokenRefresher;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class GrokAuthStorageTest extends TestCase
{
    private string $tmpDir;
    private GrokAuthStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-grok-auth-test');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->storage = new GrokAuthStorage($this->tmpDir, $lockFactory);
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

        $this->storage->saveCredentials($record);

        $path = $this->tmpDir.'/'.GrokOAuthConfig::AUTH_FILE;
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777, 'auth.json must be published with mode 0600');
        $this->assertSame([], glob($this->tmpDir.'/.hatfield/*.tmp.*') ?: [], 'No temp files should remain after save');

        $loaded = $this->storage->loadCredentials();

        $this->assertNotNull($loaded);
        $this->assertSame('test-access-token', $loaded->access);
        $this->assertSame('test-refresh-token', $loaded->refresh);
        $this->assertFalse($loaded->isExpired());
    }

    public function testMissingFileReturnsNull(): void
    {
        $this->assertNull($this->storage->loadCredentials());
    }

    public function testCodexAndGrokWritesPreserveEachOther(): void
    {
        $lockFactory = new LockFactory(new FlockStore($this->tmpDir));
        $codex = new CodexAuthFileStore($this->tmpDir.'/'.GrokOAuthConfig::AUTH_FILE, $lockFactory);
        $codex->saveCredentials(new CodexAuthRecord('codex-one', 'codex-refresh', time() + 3600, 'account'));
        $this->storage->saveCredentials(new GrokAuthRecord('grok-one', 'grok-refresh', time() + 3600));
        $codex->saveCredentials(new CodexAuthRecord('codex-two', 'codex-refresh', time() + 3600, 'account'));

        $this->assertSame('codex-two', $codex->loadCredentialsRaw()?->access);
        $this->assertSame('grok-one', $this->storage->loadCredentialsRaw()?->access);
    }

    public function testExpiredRecordWithoutRefresherReturnsExpired(): void
    {
        $expiredRecord = new GrokAuthRecord(
            access: 'expired-access',
            refresh: 'i-will-be-refreshed',
            expires: time() - 3600,
        );

        $this->storage->saveCredentials($expiredRecord);

        $loaded = $this->storage->loadCredentials();

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isExpired());
        $this->assertSame('expired-access', $loaded->access);
    }

    public function testExpiredRecordWithRefresherThrowsOnRefreshFailure(): void
    {
        $failingRefresher = new class extends GrokTokenRefresher {
            public function refresh(string $refreshToken): GrokAuthRecord
            {
                throw new \RuntimeException('Simulated refresh failure.');
            }
        };

        $storageWithRefresh = new GrokAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $failingRefresher);

        $expiredRecord = new GrokAuthRecord(
            access: 'expired-access',
            refresh: 'invalid-refresh-token',
            expires: time() - 3600,
        );

        $storageWithRefresh->saveCredentials($expiredRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired and could not be refreshed');

        $storageWithRefresh->loadCredentials();
    }

    public function testExpiredRecordWithRefresherReturnsRefreshedRecord(): void
    {
        $fresh = new GrokAuthRecord(
            access: 'fresh-access',
            refresh: 'fresh-refresh',
            expires: time() + 3600,
        );

        $succeedingRefresher = new class($fresh) extends GrokTokenRefresher {
            public string $seenRefresh = '';

            public function __construct(private GrokAuthRecord $fresh)
            {
                parent::__construct();
            }

            public function refresh(string $refreshToken): GrokAuthRecord
            {
                $this->seenRefresh = $refreshToken;

                return $this->fresh;
            }
        };

        $storageWithRefresh = new GrokAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $succeedingRefresher);

        $expiredRecord = new GrokAuthRecord(
            access: 'expired-access',
            refresh: 'i-will-be-refreshed',
            expires: time() - 3600,
        );

        $storageWithRefresh->saveCredentials($expiredRecord);

        $loaded = $storageWithRefresh->loadCredentials();

        $this->assertSame('i-will-be-refreshed', $succeedingRefresher->seenRefresh);
        $this->assertNotNull($loaded);
        $this->assertSame('fresh-access', $loaded->access);
        $this->assertSame('fresh-refresh', $loaded->refresh);
        $this->assertFalse($loaded->isExpired());

        // Persisted under the lock — subsequent raw load must see the refreshed record.
        $raw = $storageWithRefresh->loadCredentialsRaw();
        $this->assertNotNull($raw);
        $this->assertSame('fresh-access', $raw->access);
    }

    public function testLoadCredentialsRawReturnsExpiredWithoutRefresh(): void
    {
        $expiredRecord = new GrokAuthRecord(
            access: 'expired-access-raw',
            refresh: 'some-refresh',
            expires: time() - 3600,
        );

        $failingRefresher = new class extends GrokTokenRefresher {
            public function refresh(string $refreshToken): GrokAuthRecord
            {
                throw new \RuntimeException('Should not be called by loadCredentialsRaw.');
            }
        };

        $storageWithRefresh = new GrokAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $failingRefresher);
        $storageWithRefresh->saveCredentials($expiredRecord);

        $raw = $storageWithRefresh->loadCredentialsRaw();
        $this->assertNotNull($raw);
        $this->assertTrue($raw->isExpired());
        $this->assertSame('expired-access-raw', $raw->access);
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

        $this->storage->saveCredentials(new GrokAuthRecord(
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
