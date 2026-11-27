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
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class GrokOAuthServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-grok-oauth-service-test');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testRefreshCredentialsThrowsWhenNoStoredCredentials(): void
    {
        $service = new GrokOAuthService($this->storage($this->failingRefresher()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stored Grok credentials found');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsNoStoredCredentialsMentionsAuthHint(): void
    {
        $service = new GrokOAuthService($this->storage($this->failingRefresher()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GrokOAuthConfig::authCommandHint());

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsFailureMentionsAuthHint(): void
    {
        $storage = $this->storage($this->failingRefresher());
        $storage->saveCredentials(new GrokAuthRecord(
            access: 'access',
            refresh: 'refresh',
            expires: time() + 3600,
        ));

        $service = new GrokOAuthService($storage);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GrokOAuthConfig::authCommandHint());

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsPersistsFreshRecord(): void
    {
        $fresh = new GrokAuthRecord(
            access: 'fresh-access',
            refresh: 'fresh-refresh',
            expires: time() + 3600,
        );

        $refresher = $this->createMock(GrokTokenRefresher::class);
        $refresher->expects($this->once())->method('refresh')->with('stale-refresh')->willReturn($fresh);
        $storage = $this->storage($refresher);
        $storage->saveCredentials(new GrokAuthRecord(
            access: 'stale-access',
            refresh: 'stale-refresh',
            expires: time() + 60,
        ));

        $service = new GrokOAuthService($storage);
        $result = $service->refreshCredentials();

        $this->assertSame('fresh-access', $result->access);
        $this->assertSame('fresh-refresh', $result->refresh);

        $loaded = $storage->loadCredentialsRaw();
        $this->assertNotNull($loaded);
        $this->assertSame('fresh-access', $loaded->access);
        $this->assertSame('fresh-refresh', $loaded->refresh);
    }

    private function storage(GrokTokenRefresher $refresher): GrokAuthStorage
    {
        return new GrokAuthStorage($this->tmpDir, new LockFactory(new FlockStore($this->tmpDir)), $refresher, new NullLogger());
    }

    private function failingRefresher(): GrokTokenRefresher
    {
        $refresher = $this->createStub(GrokTokenRefresher::class);
        $refresher->method('refresh')->willThrowException(new \RuntimeException('Simulated refresh failure.'));

        return $refresher;
    }
}
