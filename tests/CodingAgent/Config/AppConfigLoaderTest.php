<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use PHPUnit\Framework\TestCase;

class AppConfigLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private AppConfigLoader $loader;
    private SettingsPathResolver $pathResolver;
    private string $defaultsPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield_test_'.bin2hex(random_bytes(8));
        $this->homeDir = $this->tmpDir.'/home/user';

        mkdir($this->homeDir, 0755, true);
        mkdir($this->homeDir.'/.hatfield', 0755, true);

        $this->pathResolver = new SettingsPathResolver(
            projectDir: '/app',
            homeDir: $this->homeDir,
        );
        $this->loader = new AppConfigLoader($this->pathResolver);

        // Create a defaults file
        $this->defaultsPath = $this->tmpDir.'/defaults.yaml';
        file_put_contents($this->defaultsPath, <<<'YAML'
tui:
    theme: cyberpunk
    theme_paths:
        - '%kernel.project_dir%/config/themes'
        - '.hatfield/themes'
        - '~/.hatfield/themes'
sessions:
    path: '.hatfield/sessions'
YAML
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDir($this->tmpDir);
    }

    public function testLoadDefaultsOnly(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);

        $config = $this->loader->load($this->defaultsPath, $projectCwd);

        self::assertSame('cyberpunk', $config->tui->theme);
        self::assertNotEmpty($config->tui->themePaths);
        self::assertContains('/app/config/themes', $config->tui->themePaths);
    }

    public function testHomeSettingsOverrideDefaults(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);

        // Create home settings that changes the theme
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: tokyo-night
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $projectCwd);

        self::assertSame('tokyo-night', $config->tui->theme);
        // Home should still have the default paths (merged, not replaced)
        self::assertNotEmpty($config->tui->themePaths);
        self::assertContains('/app/config/themes', $config->tui->themePaths);
    }

    public function testProjectSettingsOverrideHomeSettings(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        // Home says tokyo-night
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: tokyo-night
YAML
        );

        // Project says nord
        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $projectCwd);

        self::assertSame('nord', $config->tui->theme);
    }

    public function testMissingSettingsFilesAreIgnored(): void
    {
        $projectCwd = $this->tmpDir.'/project_no_hatfield';
        mkdir($projectCwd, 0755, true);
        // No .hatfield/ created — should not error

        $config = $this->loader->load($this->defaultsPath, $projectCwd);

        self::assertSame('cyberpunk', $config->tui->theme);
    }

    public function testNestedMergePreservesUnchangedKeys(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        // Project only sets theme, not theme_paths
        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $projectCwd);

        // Theme overridden
        self::assertSame('nord', $config->tui->theme);
        // theme_paths still from defaults (not wiped)
        self::assertNotEmpty($config->tui->themePaths);
        self::assertContains('/app/config/themes', $config->tui->themePaths);
    }

    public function testListArraysReplaceNotMerge(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        // Project specifies a new theme_paths list — should replace defaults
        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme_paths:
        - '/custom/themes'
        - '.hatfield/custom'
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $projectCwd);

        // theme_paths from project (not merged with defaults)
        self::assertCount(2, $config->tui->themePaths);
        self::assertContains('/custom/themes', $config->tui->themePaths);
        self::assertNotContains('/app/config/themes', $config->tui->themePaths);
    }

    public function testSessionsPathResolved(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        mkdir($projectCwd, 0755, true);

        $config = $this->loader->load($this->defaultsPath, $projectCwd);

        self::assertArrayHasKey('path', $config->sessions);
        self::assertStringContainsString('.hatfield/sessions', (string) $config->sessions['path']);
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
