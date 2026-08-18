<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\GrokAuthRecord;
use Ineersa\CodingAgent\Auth\GrokAuthStorage;
use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use Ineersa\CodingAgent\Auth\GrokOAuthService;
use Ineersa\CodingAgent\Auth\GrokTokenRefresher;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class GrokOAuthServiceTest extends TestCase
{
    private GrokAuthStorage $storage;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-grok-oauth-service-test');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->storage = new GrokAuthStorage($this->tmpDir, $lockFactory);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testConstructWithStorage(): void
    {
        $service = new GrokOAuthService($this->storage, $this->failingRefresher());
        $this->assertInstanceOf(GrokOAuthService::class, $service);
    }

    public function testConstructAcceptsNullRefresher(): void
    {
        $service = new GrokOAuthService($this->storage);
        $this->assertInstanceOf(GrokOAuthService::class, $service);
    }

    public function testRefreshCredentialsThrowsWhenNoStoredCredentials(): void
    {
        $service = new GrokOAuthService($this->storage, $this->failingRefresher());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stored Grok credentials found');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsNoStoredCredentialsMentionsAuthHint(): void
    {
        $service = new GrokOAuthService($this->storage, $this->failingRefresher());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GrokOAuthConfig::authCommandHint());

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsThrowsWhenRefresherNotConfigured(): void
    {
        $this->storage->saveCredentials('grok-cli', new GrokAuthRecord(
            access: 'expired-access',
            refresh: 'expired-refresh-token',
            expires: time() - 3600,
        ));

        $service = new GrokOAuthService($this->storage); // no refresher

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no refresher configured');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsFailureMentionsAuthHint(): void
    {
        $this->storage->saveCredentials('grok-cli', new GrokAuthRecord(
            access: 'access',
            refresh: 'refresh',
            expires: time() + 3600,
        ));

        $service = new GrokOAuthService($this->storage, $this->failingRefresher());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GrokOAuthConfig::authCommandHint());

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsPersistsFreshRecord(): void
    {
        $this->storage->saveCredentials('grok-cli', new GrokAuthRecord(
            access: 'stale-access',
            refresh: 'stale-refresh',
            expires: time() + 60,
        ));

        $fresh = new GrokAuthRecord(
            access: 'fresh-access',
            refresh: 'fresh-refresh',
            expires: time() + 3600,
        );

        $refresher = new class($fresh) extends GrokTokenRefresher {
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

        $service = new GrokOAuthService($this->storage, $refresher);
        $result = $service->refreshCredentials();

        $this->assertSame('stale-refresh', $refresher->seenRefresh);
        $this->assertSame('fresh-access', $result->access);
        $this->assertSame('fresh-refresh', $result->refresh);

        $loaded = $this->storage->loadCredentialsRaw('grok-cli');
        $this->assertNotNull($loaded);
        $this->assertSame('fresh-access', $loaded->access);
        $this->assertSame('fresh-refresh', $loaded->refresh);
    }

    public function testRefreshCredentialsWithCustomProviderKeyIsIsolated(): void
    {
        $this->storage->saveCredentials('grok-cli-work', new GrokAuthRecord(
            access: 'work-access',
            refresh: 'work-refresh',
            expires: time() + 3600,
        ));

        $service = new GrokOAuthService($this->storage, $this->failingRefresher());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stored Grok credentials found');

        $service->refreshCredentials();
    }

    private function failingRefresher(): GrokTokenRefresher
    {
        return new class extends GrokTokenRefresher {
            public function refresh(string $refreshToken): GrokAuthRecord
            {
                throw new \RuntimeException('Simulated refresh failure.');
            }
        };
    }
}
