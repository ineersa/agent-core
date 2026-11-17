<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Support;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\TestCase;

abstract class OmTestCase extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createProjectTempDir('om-client');
        $this->assertSame($this->directory, realpath($this->directory));
        $this->assertFalse(is_link($this->directory));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    protected function settings(): OmSettings
    {
        return OmSettings::fromArray(['librarian' => ['url' => 'http://librarian.test:8093/prefix/_mcp']]);
    }

    protected function api(bool $child = false, bool $worktree = false): ExtensionApiInterface
    {
        $api = $this->createMock(ExtensionApiInterface::class);
        $api->method('getCwd')->willReturn($this->directory.'/.');
        $api->method('getSettings')->willReturn(['librarian' => ['url' => 'http://librarian.test:8093/prefix/_mcp']]);
        $api->method('isChildRun')->willReturn($child);
        $api->expects($this->never())->method('sessionEvents');
        $exec = $this->createStub(ExecInterface::class);
        if (!is_dir($this->directory.'/.git')) {
            mkdir($this->directory.'/.git');
        }
        if (!$worktree) {
            $exec->method('exec')->willReturn(new ExecResultDTO($this->directory.'/.git'."\n".$this->directory.'/.git', '', 0));
        }
        if ($worktree) {
            mkdir($this->directory.'/common');
            mkdir($this->directory.'/common/worktree');
            $exec->method('exec')->willReturn(new ExecResultDTO($this->directory.'/common/worktree'."\n".$this->directory.'/common', '', 0));
        }
        $api->method('exec')->willReturn($exec);

        return $api;
    }
}
