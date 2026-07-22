<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use PHPUnit\Framework\TestCase;

class SettingsPathResolverTest extends TestCase
{
    private string $appRoot;
    private string $homeDir;
    private SettingsPathResolver $resolver;

    protected function setUp(): void
    {
        $this->appRoot = '/app';
        $this->homeDir = '/home/user';
        $this->resolver = new SettingsPathResolver(
            appRoot: $this->appRoot,
            homeDir: $this->homeDir,
        );
    }

    public function testResolveProjectDirPlaceholder(): void
    {
        $result = $this->resolver->resolve('%kernel.project_dir%/config/themes', '/tmp');
        $this->assertSame('/app/config/themes', $result);
    }

    public function testResolveTilde(): void
    {
        $result = $this->resolver->resolve('~/.hatfield/themes', '/tmp');
        $this->assertSame('/home/user/.hatfield/themes', $result);
    }

    public function testResolveRelativePathUsesBaseDir(): void
    {
        $result = $this->resolver->resolve('.hatfield/themes', '/tmp/project');
        $this->assertSame('/tmp/project/.hatfield/themes', $result);
    }

    public function testAbsolutePathPassesThrough(): void
    {
        $result = $this->resolver->resolve('/etc/some/path', '/tmp');
        $this->assertSame('/etc/some/path', $result);
    }

    public function testEmptyStringPassesThrough(): void
    {
        $result = $this->resolver->resolve('', '/tmp');
        $this->assertSame('', $result);
    }

    public function testResolveListResolvesAll(): void
    {
        $paths = [
            '%kernel.project_dir%/config/themes',
            '~/.hatfield/themes',
            '.hatfield/themes',
        ];
        $result = $this->resolver->resolveList($paths, '/tmp/project');

        $this->assertSame([
            '/app/config/themes',
            '/home/user/.hatfield/themes',
            '/tmp/project/.hatfield/themes',
        ], $result);
    }

    public function testGetAppRoot(): void
    {
        $this->assertSame('/app', $this->resolver->getAppRoot());
    }

    public function testGetHomeDir(): void
    {
        $this->assertSame('/home/user', $this->resolver->getHomeDir());
    }
}
