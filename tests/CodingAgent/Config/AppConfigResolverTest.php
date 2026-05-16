<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppConfigResolver;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use PHPUnit\Framework\TestCase;

class AppConfigResolverTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private AppConfigResolver $resolver;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield_resolver_test_'.bin2hex(random_bytes(8));
        $this->homeDir = $this->tmpDir.'/home/user';

        mkdir($this->homeDir, 0755, true);
        mkdir($this->homeDir.'/.hatfield', 0755, true);

        // Create a defaults file in a temp location
        $defaultsPath = $this->tmpDir.'/config/hatfield.defaults.yaml';
        mkdir($this->tmpDir.'/config', 0755, true);
        file_put_contents($defaultsPath, <<<'YAML'
tui:
    theme: cyberpunk
    theme_paths:
        - '%kernel.project_dir%/config/themes'
sessions:
    path: '.hatfield/sessions'
YAML
        );

        $pathResolver = new SettingsPathResolver(
            appRoot: '/app',
            homeDir: $this->homeDir,
        );
        $loader = new AppConfigLoader($pathResolver);

        $resources = new AppResourceLocator($this->tmpDir);

        $this->resolver = new AppConfigResolver(
            loader: $loader,
            resources: $resources,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testResolveReturnsCyberpunkByDefault(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);

        $config = $this->resolver->resolve($projectCwd);

        self::assertSame('cyberpunk', $config->tui->theme);
    }

    public function testResolveWithHomeSettings(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);

        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        $config = $this->resolver->resolve($projectCwd);

        self::assertSame('nord', $config->tui->theme);
    }

    public function testResolveWithProjectSettingsWinsOverHome(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: gruvbox-dark
YAML
        );

        $config = $this->resolver->resolve($projectCwd);

        self::assertSame('gruvbox-dark', $config->tui->theme);
    }

    public function testResolveUsesCwdWhenEmpty(): void
    {
        // When no cwd passed, uses process cwd
        // We can only test it doesn't crash and returns defaults
        $config = $this->resolver->resolve('');

        self::assertNotNull($config);
        self::assertSame('cyberpunk', $config->tui->theme);
    }

    public function testResolveCacheIsUsed(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);

        $first = $this->resolver->resolve($projectCwd);
        $second = $this->resolver->resolve($projectCwd);

        // Same object from cache
        self::assertSame($first, $second);
    }

    public function testClearCacheForcesReload(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);

        $first = $this->resolver->resolve($projectCwd);
        $this->resolver->clearCache();
        $second = $this->resolver->resolve($projectCwd);

        // Different objects after cache clear
        self::assertNotSame($first, $second);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
