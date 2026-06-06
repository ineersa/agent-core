<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\CodexAuthRecord;
use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Auth\CodexOAuthService;
use Ineersa\CodingAgent\Auth\CodexTokenRefresher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class CodexOAuthServiceTest extends TestCase
{
    private CodexAuthStorage $storage;
    private CodexTokenRefresher $refresher;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = \sys_get_temp_dir() . '/hatfield-oauth-test-' . \bin2hex(\random_bytes(8));
        @\mkdir($this->tmpDir . '/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->storage = new CodexAuthStorage($this->tmpDir, $lockFactory);
        $this->refresher = new CodexTokenRefresher();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $path = $this->tmpDir . '/' . CodexOAuthConfig::AUTH_FILE;
        if (\file_exists($path)) {
            @\unlink($path);
        }
        @\rmdir($this->tmpDir . '/.hatfield');
        @\rmdir($this->tmpDir);
    }

    public function testConstructWithStorage(): void
    {
        $service = new CodexOAuthService($this->storage, $this->refresher);
        $this->assertInstanceOf(CodexOAuthService::class, $service);
    }

    public function testConstructAcceptsNullRefresher(): void
    {
        $service = new CodexOAuthService($this->storage);
        $this->assertInstanceOf(CodexOAuthService::class, $service);
    }

    public function testRefreshCredentialsThrowsWhenNoStoredCredentials(): void
    {
        $service = new CodexOAuthService($this->storage, $this->refresher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stored Codex credentials found');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsThrowsWhenRefresherNotConfigured(): void
    {
        $expired = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'expired-refresh-token',
            expires: \time() - 3600,
            accountId: 'expired-account',
        );
        $this->storage->saveCredentials('openai-codex', $expired);

        $service = new CodexOAuthService($this->storage); // no refresher

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no refresher configured');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsWithStoredExpiredDataAttemptsRefresh(): void
    {
        $expired = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'expired-refresh-token',
            expires: \time() - 3600,
            accountId: 'expired-account',
        );
        $this->storage->saveCredentials('openai-codex', $expired);

        $service = new CodexOAuthService($this->storage, $this->refresher);

        // Will hit the network and fail — expect RuntimeException
        $this->expectException(\RuntimeException::class);

        $service->refreshCredentials();
    }

    public function testCodexAuthRecordIsExpiredWithSeconds(): void
    {
        // Record expires 30 seconds from now (Unix seconds)
        $record = new CodexAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: \time() + 30,
            accountId: 'acct',
        );

        $this->assertTrue($record->isExpired(60), 'buffer 60 > 30 remaining = expired');
        $this->assertFalse($record->isExpired(0), 'no buffer, 30 remaining = not expired');
    }
}
