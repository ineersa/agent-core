<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Utility;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Utility\Clipboard;
use PHPUnit\Framework\TestCase;

final class ClipboardTest extends TestCase
{
    public function testFindsOptionalBackendWithoutWhich(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('clipboard-lookup');
        $path = getenv('PATH');
        $env = $_ENV;
        $server = $_SERVER;
        try {
            // An isolated PATH contains our backend, but no `which` executable.
            file_put_contents($directory.'/wl-copy', "#!/bin/sh\nexit 0\n");
            chmod($directory.'/wl-copy', 0o755);
            putenv('PATH='.$directory);
            $_ENV['PATH'] = $_SERVER['PATH'] = $directory;
            $lookup = new \ReflectionMethod(Clipboard::class, 'commandExists');

            $this->assertTrue($lookup->invoke(null, 'wl-copy'));
            $this->assertFalse($lookup->invoke(null, 'xclip'));
        } finally {
            putenv(false === $path ? 'PATH' : 'PATH='.$path);
            $_ENV = $env;
            $_SERVER = $server;
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
