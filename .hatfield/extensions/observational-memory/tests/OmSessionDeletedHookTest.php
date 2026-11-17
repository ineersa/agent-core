<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\DTO\AfterSessionDeletedHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\OmSessionDeletedHook;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OmSessionDeletedHookTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createOsTempDir('om-deletion');
        $this->assertSame($this->directory, realpath($this->directory));
        $this->assertFalse(is_link($this->directory));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    public function testCapturedTargetProjectIsUsedWithoutCurrentSessionLookup(): void
    {
        $exec = $this->createMock(ExecInterface::class);
        $exec->expects($this->never())->method('exec');
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame('DELETE', $method);
            self::assertStringEndsWith('/sessions/hatfield/42', $url);
            self::assertContains('X-Librarian-Project: '.$this->directory, $options['headers']);

            return new MockResponse('{"ok":true,"contract_version":1,"deleted":false}');
        });
        $hook = new OmSessionDeletedHook(new LibrarianSettingsDTO('https://example.test/_mcp', 1), $exec, new NullLogger(), $http);
        $hook->onAfterSessionDeleted(new AfterSessionDeletedHookContextDTO('42', $this->directory.'/.', null));
        $hook->onAfterSessionDeleted(new AfterSessionDeletedHookContextDTO('child', $this->directory, '42'));
        $this->assertSame(1, $calls);
    }

    public function testLinkedTargetWorktreeDoesNotSendDelete(): void
    {
        mkdir($this->directory.'/metadata');
        mkdir($this->directory.'/metadata/worktree');
        file_put_contents($this->directory.'/.git', 'gitdir: metadata/worktree');
        $exec = $this->createStub(ExecInterface::class);
        $exec->method('exec')->willReturn(new ExecResultDTO($this->directory.'/metadata/worktree'."\n".$this->directory.'/metadata', '', 0));
        $http = new MockHttpClient();
        (new OmSessionDeletedHook(new LibrarianSettingsDTO('https://example.test/_mcp', 1), $exec, new NullLogger(), $http))->onAfterSessionDeleted(new AfterSessionDeletedHookContextDTO('42', $this->directory, null));
        $this->assertSame(0, $http->getRequestsCount());
    }
}
