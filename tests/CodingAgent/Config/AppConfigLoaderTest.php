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
    private string|false $originalCwd;

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
YAML
        );

        $this->originalCwd = getcwd();
    }

    protected function tearDown(): void
    {
        // Restore original working directory
        if (false !== $this->originalCwd) {
            chdir($this->originalCwd);
        }

        // Clean up temp directory
        $this->removeDir($this->tmpDir);
    }

    private function chdirToProject(string $projectCwd): void
    {
        mkdir($projectCwd, 0755, true);
        chdir($projectCwd);
    }

    public function testLoadDefaultsOnly(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);

        $config = $this->loader->load($this->defaultsPath);

        self::assertSame('cyberpunk', $config['tui']['theme']);
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testHomeSettingsOverrideDefaults(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);

        // Create home settings that changes the theme
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: tokyo-night
YAML
        );

        $config = $this->loader->load($this->defaultsPath);

        self::assertSame('tokyo-night', $config['tui']['theme']);
        // Home should still have the default paths (merged, not replaced)
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testProjectSettingsOverrideHomeSettings(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);
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

        $config = $this->loader->load($this->defaultsPath);

        self::assertSame('nord', $config['tui']['theme']);
    }

    public function testMissingSettingsFilesAreIgnored(): void
    {
        $projectCwd = $this->tmpDir.'/project_no_hatfield';
        $this->chdirToProject($projectCwd);
        // No .hatfield/ created — should not error

        $config = $this->loader->load($this->defaultsPath);

        self::assertSame('cyberpunk', $config['tui']['theme']);
    }

    public function testNestedMergePreservesUnchangedKeys(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        // Project only sets theme, not theme_paths
        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        $config = $this->loader->load($this->defaultsPath);

        // Theme overridden
        self::assertSame('nord', $config['tui']['theme']);
        // theme_paths still from defaults (not wiped)
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testListArraysReplaceNotMerge(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        // Project specifies a new theme_paths list — should replace defaults
        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme_paths:
        - '/custom/themes'
        - '.hatfield/custom'
YAML
        );

        $config = $this->loader->load($this->defaultsPath);

        // theme_paths from project (not merged with defaults)
        self::assertCount(2, $config['tui']['theme_paths']);
        self::assertContains('/custom/themes', $config['tui']['theme_paths']);
        self::assertNotContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testSessionsPathResolved(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);

        $config = $this->loader->load($this->defaultsPath);

        self::assertArrayHasKey('path', $config['sessions']);
        self::assertStringContainsString('.hatfield/sessions', (string) $config['sessions']['path']);
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
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        // Project overrides only tui.theme — tui.theme_paths from defaults survive
        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: gruvbox-dark
YAML
        );

        $config = $this->loader->load($this->defaultsPath);

        self::assertSame('gruvbox-dark', $config['tui']['theme']);
        self::assertNotEmpty($config['tui']['theme_paths']);
        self::assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testHomeThenProjectLayeredOverlay(): void
    {
        $projectCwd = $this->tmpDir.'/project';
        $this->chdirToProject($projectCwd);
        mkdir($projectCwd.'/.hatfield', 0755, true);

        // Home overrides theme, adds custom list
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: home-theme
    theme_paths:
        - '/home/custom'
YAML
        );

        // Project only overrides theme again — leaves home's theme_paths in place
        file_put_contents($projectCwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: project-theme
YAML
        );

        $config = $this->loader->load($this->defaultsPath);

        // Project scalar wins
        self::assertSame('project-theme', $config['tui']['theme']);
        // Home's list replaced defaults; project didn't touch list, so home's list survives
        self::assertCount(1, $config['tui']['theme_paths']);
        self::assertContains('/home/custom', $config['tui']['theme_paths']);
        self::assertNotContains('/app/config/themes', $config['tui']['theme_paths']);
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
