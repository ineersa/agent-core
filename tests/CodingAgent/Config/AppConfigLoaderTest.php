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
            appRoot: '/app',
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
logging:
    path: .hatfield/logs
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
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd, 0755, true);

        $config = $this->loader->load($this->defaultsPath, $cwd);

        self::assertSame('cyberpunk', $config['tui']['theme']);
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testHomeSettingsOverrideDefaults(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd, 0755, true);

        // Create home settings that changes the theme
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: tokyo-night
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $cwd);

        self::assertSame('tokyo-night', $config['tui']['theme']);
        // Home should still have the default paths (merged, not replaced)
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testProjectSettingsOverrideHomeSettings(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd.'/.hatfield', 0755, true);

        // Home says tokyo-night
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: tokyo-night
YAML
        );

        // Project says nord
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $cwd);

        self::assertSame('nord', $config['tui']['theme']);
    }

    public function testMissingSettingsFilesAreIgnored(): void
    {
        $cwd = $this->tmpDir.'/project_no_hatfield';
        @mkdir($cwd, 0755, true);
        // No .hatfield/ created — should not error

        $config = $this->loader->load($this->defaultsPath, $cwd);

        self::assertSame('cyberpunk', $config['tui']['theme']);
    }

    public function testNestedMergePreservesUnchangedKeys(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd.'/.hatfield', 0755, true);

        // Project only sets theme, not theme_paths
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $cwd);

        // Theme overridden
        self::assertSame('nord', $config['tui']['theme']);
        // theme_paths still from defaults (not wiped)
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testListArraysReplaceNotMerge(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd.'/.hatfield', 0755, true);

        // Project specifies a new theme_paths list — should replace defaults
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme_paths:
        - '/custom/themes'
        - '.hatfield/custom'
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $cwd);

        // theme_paths from project (not merged with defaults)
        self::assertCount(2, $config['tui']['theme_paths']);
        self::assertContains('/custom/themes', $config['tui']['theme_paths']);
        self::assertNotContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testSessionsPathResolved(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd, 0755, true);

        $config = $this->loader->load($this->defaultsPath, $cwd);

        self::assertArrayHasKey('path', $config['sessions']);
        self::assertStringContainsString('.hatfield/sessions', (string) $config['sessions']['path']);
    }

    public function testLoggingPathResolvedToCwd(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd, 0755, true);

        $config = $this->loader->load($this->defaultsPath, $cwd);

        self::assertArrayHasKey('path', $config['logging']);
        $logPath = (string) $config['logging']['path'];
        self::assertStringContainsString($cwd, $logPath);
        self::assertStringContainsString('.hatfield/logs', $logPath);
    }

    public function testLoggingPathNotKernelProjectDir(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd, 0755, true);

        $config = $this->loader->load($this->defaultsPath, $cwd);

        $logPath = (string) $config['logging']['path'];
        // Must NOT contain the app install dir — logs are project-local
        self::assertStringNotContainsString('/app', $logPath);
        // Must contain the actual project CWD
        self::assertStringContainsString($cwd, $logPath);
    }

    // ── overlayConfig() unit tests (no file I/O) ──────────────────────────

    public function testOverlayConfigScalarOverride(): void
    {
        $base = ['theme' => 'cyberpunk', 'version' => 1];
        $over = ['theme' => 'nord'];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertSame('nord', $result['theme']);
        self::assertSame(1, $result['version']);
    }

    public function testOverlayConfigScalarWinsNotArray(): void
    {
        // This is the core reason array_merge_recursive() is unsuitable:
        // scalar overrides must win, not become an array of two values.
        $base = ['theme' => 'cyberpunk'];
        $over = ['theme' => 'nord'];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertIsString($result['theme']);
        self::assertSame('nord', $result['theme']);
    }

    public function testOverlayConfigNestedAssociativeDeepOverlay(): void
    {
        $base = [
            'tui' => [
                'theme' => 'cyberpunk',
                'options' => [
                    'animations' => true,
                    'fps' => 60,
                ],
            ],
        ];

        $over = [
            'tui' => [
                'theme' => 'nord',
                'options' => [
                    'fps' => 30,
                ],
            ],
        ];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertSame('nord', $result['tui']['theme']);
        // Deeper associative key not touched by overlay survives
        self::assertTrue($result['tui']['options']['animations']);
        // Deeper associative key in overlay replaces base value
        self::assertSame(30, $result['tui']['options']['fps']);
    }

    public function testOverlayConfigListReplacesEntirely(): void
    {
        $base = ['paths' => ['/default/a', '/default/b', '/default/c']];
        $over = ['paths' => ['/project/x']];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertCount(1, $result['paths']);
        self::assertSame('/project/x', $result['paths'][0]);
    }

    public function testOverlayConfigListDoesNotIndexMerge(): void
    {
        // array_replace_recursive() would do index-based partial replacement
        // where $base[0] gets replaced but $base[1] survives. Our overlay
        // must replace the whole list.
        $base = ['items' => ['A', 'B', 'C']];
        $over = ['items' => ['X']];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertSame(['X'], $result['items']);
    }

    public function testOverlayConfigNullOverridesValue(): void
    {
        $base = ['key' => 'present'];
        $over = ['key' => null];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertNull($result['key']);
    }

    public function testOverlayConfigNewKeyAdded(): void
    {
        $base = ['existing' => true];
        $over = ['new_key' => 'added'];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertTrue($result['existing']);
        self::assertSame('added', $result['new_key']);
    }

    public function testOverlayConfigBoolOverride(): void
    {
        $base = ['enabled' => false];
        $over = ['enabled' => true];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertTrue($result['enabled']);
    }

    public function testOverlayConfigIntOverride(): void
    {
        $base = ['limit' => 100];
        $over = ['limit' => 50];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertSame(50, $result['limit']);
    }

    public function testOverlayConfigMixedTypeOverride(): void
    {
        // Higher layer can change the type of a key completely.
        $base = ['key' => 'string'];
        $over = ['key' => ['nested' => 'value']];

        $result = $this->loader->overlayConfig($base, $over);

        self::assertIsArray($result['key']);
        self::assertSame('value', $result['key']['nested']);
    }

    // ── Integration-style tests via load() ─────────────────────────────────

    public function testDeepNestedMergePreservesUnchangedDeepKeys(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd.'/.hatfield', 0755, true);

        // Project overrides only tui.theme — tui.theme_paths from defaults survive
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: gruvbox-dark
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $cwd);

        self::assertSame('gruvbox-dark', $config['tui']['theme']);
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testHomeThenProjectLayeredOverlay(): void
    {
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd.'/.hatfield', 0755, true);

        // Home overrides theme, adds custom list
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: home-theme
    theme_paths:
        - '/home/custom'
YAML
        );

        // Project only overrides theme again — leaves home's theme_paths in place
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: project-theme
YAML
        );

        $config = $this->loader->load($this->defaultsPath, $cwd);

        // Project scalar wins
        self::assertSame('project-theme', $config['tui']['theme']);
        // Home's list replaced defaults; project didn't touch list, so home's list survives
        self::assertCount(1, $config['tui']['theme_paths']);
        self::assertContains('/home/custom', $config['tui']['theme_paths']);
        self::assertNotContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    // ──────────────────────────────────────────────
    //  Path map declarative tests
    // ──────────────────────────────────────────────

    public function testPathMapResolvesAllEntries(): void
    {
        // Given a config with all path-bearing keys
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd, 0755, true);

        $config = $this->loader->load($this->defaultsPath, $cwd);

        // All path-bearing keys should be resolved (not containing placeholders)
        // theme_paths is a list — each entry should be resolved
        foreach ($config['tui']['theme_paths'] as $path) {
            self::assertStringNotContainsString('%', $path);
            self::assertStringNotContainsString('~', $path);
        }

        // sessions.path should be an absolute path
        self::assertStringStartsWith('/', $config['sessions']['path']);

        // logging.path should be resolved
        self::assertStringStartsWith('/', $config['logging']['path']);
    }

    public function testPathMapHandlesMissingKeysGracefully(): void
    {
        // Given a minimal config without any path keys
        $cwd = $this->tmpDir.'/project';
        @mkdir($cwd, 0755, true);

        // Load defaults WITHOUT path keys
        $minimalDefaults = $this->tmpDir.'/minimal-defaults.yaml';
        file_put_contents($minimalDefaults, <<<'YAML'
tui:
    theme: cyberpunk
YAML
        );

        // Should not throw despite missing sessions.path, logging.path, etc.
        $config = $this->loader->load($minimalDefaults, $cwd);

        // Path keys that don't exist in YAML should be absent from result
        // but the loader should not throw or crash.
        self::assertArrayNotHasKey('sessions', $config);
        self::assertArrayNotHasKey('logging', $config);
        self::assertSame('cyberpunk', $config['tui']['theme']);
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
