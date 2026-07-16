<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SettingsResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

class SettingsResolverTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private SettingsResolver $resolver;
    private SettingsPathResolver $pathResolver;
    private string $defaultsPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('hatfield_resolver');
        $this->homeDir = $this->tmpDir.'/home/user';

        TestDirectoryIsolation::ensureDirectory($this->homeDir);
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');

        $this->pathResolver = new SettingsPathResolver(
            appRoot: '/app',
            homeDir: $this->homeDir,
        );
        $this->resolver = new SettingsResolver($this->pathResolver);

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
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testLoadDefaultsOnly(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertSame('cyberpunk', $config['tui']['theme']);
        $this->assertNotEmpty($config['tui']['theme_paths']);
        $this->assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testHomeSettingsOverrideDefaults(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        // Create home settings that changes the theme
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: tokyo-night
YAML
        );

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertSame('tokyo-night', $config['tui']['theme']);
        // Home should still have the default paths (merged, not replaced)
        $this->assertNotEmpty($config['tui']['theme_paths']);
        $this->assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testProjectSettingsOverrideHomeSettings(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

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

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertSame('nord', $config['tui']['theme']);
    }

    public function testMissingSettingsFilesAreIgnored(): void
    {
        $cwd = $this->tmpDir.'/project_no_hatfield';
        TestDirectoryIsolation::ensureDirectory($cwd);
        // No .hatfield/ created — should not error

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertSame('cyberpunk', $config['tui']['theme']);
    }

    public function testNestedMergePreservesUnchangedKeys(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

        // Project only sets theme, not theme_paths
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: nord
YAML
        );

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        // Theme overridden
        $this->assertSame('nord', $config['tui']['theme']);
        // theme_paths still from defaults (not wiped)
        $this->assertNotEmpty($config['tui']['theme_paths']);
        $this->assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testListArraysReplaceNotMerge(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

        // Project specifies a new theme_paths list — should replace defaults
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme_paths:
        - '/custom/themes'
        - '.hatfield/custom'
YAML
        );

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        // theme_paths from project (not merged with defaults)
        $this->assertCount(2, $config['tui']['theme_paths']);
        $this->assertContains('/custom/themes', $config['tui']['theme_paths']);
        $this->assertNotContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testSessionsPathResolved(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertArrayHasKey('path', $config['sessions']);
        $this->assertStringContainsString('.hatfield/sessions', (string) $config['sessions']['path']);
    }

    public function testLoggingPathResolvedToCwd(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertArrayHasKey('path', $config['logging']);
        $logPath = (string) $config['logging']['path'];
        $this->assertStringContainsString($cwd, $logPath);
        $this->assertStringContainsString('.hatfield/logs', $logPath);
    }

    public function testLoggingPathNotKernelProjectDir(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $logPath = (string) $config['logging']['path'];
        // Must NOT contain the app install dir — logs are project-local
        $this->assertStringNotContainsString('/app', $logPath);
        // Must contain the actual project CWD
        $this->assertStringContainsString($cwd, $logPath);
    }

    // ── overlayConfig() unit tests (no file I/O) ──────────────────────────

    public function testOverlayConfigScalarOverride(): void
    {
        $base = ['theme' => 'cyberpunk', 'version' => 1];
        $over = ['theme' => 'nord'];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertSame('nord', $result['theme']);
        $this->assertSame(1, $result['version']);
    }

    public function testOverlayConfigScalarWinsNotArray(): void
    {
        // This is the core reason array_merge_recursive() is unsuitable:
        // scalar overrides must win, not become an array of two values.
        $base = ['theme' => 'cyberpunk'];
        $over = ['theme' => 'nord'];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertIsString($result['theme']);
        $this->assertSame('nord', $result['theme']);
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

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertSame('nord', $result['tui']['theme']);
        // Deeper associative key not touched by overlay survives
        $this->assertTrue($result['tui']['options']['animations']);
        // Deeper associative key in overlay replaces base value
        $this->assertSame(30, $result['tui']['options']['fps']);
    }

    public function testOverlayConfigListReplacesEntirely(): void
    {
        $base = ['paths' => ['/default/a', '/default/b', '/default/c']];
        $over = ['paths' => ['/project/x']];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertCount(1, $result['paths']);
        $this->assertSame('/project/x', $result['paths'][0]);
    }

    public function testOverlayConfigListDoesNotIndexMerge(): void
    {
        // array_replace_recursive() would do index-based partial replacement
        // where $base[0] gets replaced but $base[1] survives. Our overlay
        // must replace the whole list.
        $base = ['items' => ['A', 'B', 'C']];
        $over = ['items' => ['X']];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertSame(['X'], $result['items']);
    }

    public function testOverlayConfigNullOverridesValue(): void
    {
        $base = ['key' => 'present'];
        $over = ['key' => null];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertNull($result['key']);
    }

    public function testOverlayConfigNewKeyAdded(): void
    {
        $base = ['existing' => true];
        $over = ['new_key' => 'added'];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertTrue($result['existing']);
        $this->assertSame('added', $result['new_key']);
    }

    public function testOverlayConfigBoolOverride(): void
    {
        $base = ['enabled' => false];
        $over = ['enabled' => true];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertTrue($result['enabled']);
    }

    public function testOverlayConfigIntOverride(): void
    {
        $base = ['limit' => 100];
        $over = ['limit' => 50];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertSame(50, $result['limit']);
    }

    public function testOverlayConfigMixedTypeOverride(): void
    {
        // Higher layer can change the type of a key completely.
        $base = ['key' => 'string'];
        $over = ['key' => ['nested' => 'value']];

        $result = $this->resolver->overlayConfig($base, $over);

        $this->assertIsArray($result['key']);
        $this->assertSame('value', $result['key']['nested']);
    }

    // ── Integration-style tests via load() ─────────────────────────────────

    public function testDeepNestedMergePreservesUnchangedDeepKeys(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

        // Project overrides only tui.theme — tui.theme_paths from defaults survive
        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: gruvbox-dark
YAML
        );

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertSame('gruvbox-dark', $config['tui']['theme']);
        $this->assertNotEmpty($config['tui']['theme_paths']);
        $this->assertContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    public function testProjectExtensionsEnabledListReplacesDefaults(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
        - Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension
YAML
        );

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        $this->assertSame(
            [
                'Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension',
                'Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension',
            ],
            $config['extensions']['enabled'],
        );
    }

    public function testHomeThenProjectLayeredOverlay(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

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

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        // Project scalar wins
        $this->assertSame('project-theme', $config['tui']['theme']);
        // Home's list replaced defaults; project didn't touch list, so home's list survives
        $this->assertCount(1, $config['tui']['theme_paths']);
        $this->assertContains('/home/custom', $config['tui']['theme_paths']);
        $this->assertNotContains('/app/config/themes', $config['tui']['theme_paths']);
    }

    // ──────────────────────────────────────────────
    //  Path map declarative tests
    // ──────────────────────────────────────────────

    public function testPathMapResolvesAllEntries(): void
    {
        // Given a config with all path-bearing keys
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $config = $resolution->effective;

        // All path-bearing keys should be resolved (not containing placeholders)
        // theme_paths is a list — each entry should be resolved
        foreach ($config['tui']['theme_paths'] as $path) {
            $this->assertStringNotContainsString('%', $path);
            $this->assertStringNotContainsString('~', $path);
        }

        // sessions.path should be an absolute path
        $this->assertStringStartsWith('/', $config['sessions']['path']);

        // logging.path should be resolved
        $this->assertStringStartsWith('/', $config['logging']['path']);
    }

    public function testPathMapHandlesMissingKeysGracefully(): void
    {
        // Given a minimal config without any path keys
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        // Load defaults WITHOUT path keys
        $minimalDefaults = $this->tmpDir.'/minimal-defaults.yaml';
        file_put_contents($minimalDefaults, <<<'YAML'
tui:
    theme: cyberpunk
YAML
        );

        // Should not throw despite missing sessions.path, logging.path, etc.
        $resolution = $this->resolver->resolve($minimalDefaults, $cwd);
        $config = $resolution->effective;

        // Path keys that don't exist in YAML should be absent from result
        // but the loader should not throw or crash.
        $this->assertArrayNotHasKey('sessions', $config);
        $this->assertArrayNotHasKey('logging', $config);
        $this->assertSame('cyberpunk', $config['tui']['theme']);
    }

    public function testResolveDoesNotCreateUserSettingsFile(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $homeFile = $this->homeDir.'/.hatfield/settings.yaml';
        $this->assertFileDoesNotExist($homeFile);

        $this->resolver->resolve($this->defaultsPath, $cwd);

        $this->assertFileDoesNotExist($homeFile);
    }

    public function testResolutionExposesRawLayers(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', 'tui:
    theme: home-theme
');
        file_put_contents($cwd.'/.hatfield/settings.yaml', 'tui:
    theme: project-theme
');

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);

        $this->assertSame('cyberpunk', $resolution->defaultsRaw['tui']['theme']);
        $this->assertSame('home-theme', $resolution->userRaw['tui']['theme']);
        $this->assertSame('project-theme', $resolution->projectRaw['tui']['theme']);
        $this->assertSame('project-theme', $resolution->effective['tui']['theme']);
    }

    public function testFreshResolveAfterDiskChange(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

        $first = $this->resolver->resolve($this->defaultsPath, $cwd);
        $this->assertSame('cyberpunk', $first->effective['tui']['theme']);

        file_put_contents($cwd.'/.hatfield/settings.yaml', 'tui:
    theme: changed
');

        $second = $this->resolver->resolve($this->defaultsPath, $cwd);
        $this->assertSame('changed', $second->effective['tui']['theme']);
    }

    public function testGetValueReportsScalarSourceLayers(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', 'tui:
    theme: home-theme
');
        file_put_contents($cwd.'/.hatfield/settings.yaml', 'tui:
    theme: project-theme
');

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);

        $defaultsTheme = $resolution->getValue('tui.theme');
        $this->assertTrue($defaultsTheme->exists);
        $this->assertSame('project-theme', $defaultsTheme->value);
        $this->assertSame(SettingsLayerEnum::Project, $defaultsTheme->layer);

        file_put_contents($cwd.'/.hatfield/settings.yaml', 'logging:
    level: debug
');
        $resolution2 = $this->resolver->resolve($this->defaultsPath, $cwd);
        $homeOnly = $resolution2->getValue('tui.theme');
        $this->assertSame(SettingsLayerEnum::User, $homeOnly->layer);

        @unlink($this->homeDir.'/.hatfield/settings.yaml');
        @unlink($cwd.'/.hatfield/settings.yaml');
        $resolution3 = $this->resolver->resolve($this->defaultsPath, $cwd);
        $fromDefaults = $resolution3->getValue('tui.theme');
        $this->assertSame(SettingsLayerEnum::Defaults, $fromDefaults->layer);
    }

    public function testGetValueListSourceIsWholeListPath(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');
        file_put_contents($cwd.'/.hatfield/settings.yaml', "tui:
    theme_paths:
        - '/only/project'
");

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $paths = $resolution->getValue('tui.theme_paths');

        $this->assertTrue($paths->exists);
        $this->assertSame(SettingsLayerEnum::Project, $paths->layer);
        $this->assertCount(1, $paths->value);
    }

    public function testGetValueExplicitNullInOverlay(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');
        file_put_contents($cwd.'/.hatfield/settings.yaml', 'tui:
    theme: null
');

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $theme = $resolution->getValue('tui.theme');

        $this->assertTrue($theme->exists);
        $this->assertNull($theme->value);
        $this->assertSame(SettingsLayerEnum::Project, $theme->layer);
    }

    public function testCompositeGroupDoesNotClaimSingleLayer(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
tui:
    theme: project-only-theme
YAML
        );

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);

        $group = $resolution->getValue('tui');
        $this->assertTrue($group->exists);
        $this->assertTrue($group->composite);
        $this->assertNull($group->layer);
        $this->assertIsArray($group->value);
        $this->assertSame('project-only-theme', $group->value['theme']);

        $theme = $resolution->getValue('tui.theme');
        $this->assertFalse($theme->composite);
        $this->assertSame(SettingsLayerEnum::Project, $theme->layer);
        $this->assertSame('project-only-theme', $theme->value);

        $paths = $resolution->getValue('tui.theme_paths');
        $this->assertFalse($paths->composite);
        $this->assertSame(SettingsLayerEnum::Defaults, $paths->layer);
        $this->assertNotEmpty($paths->value);
    }

    public function testDottedPathRejectsControlCharacters(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $missing = $resolution->getValue("tui.theme\x00evil");

        $this->assertFalse($missing->exists);
    }

    public function testGetValueMissingPath(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $missing = $resolution->getValue('no.such.path');

        $this->assertFalse($missing->exists);
        $this->assertNull($missing->layer);
    }

    public function testGetValueUnknownUserKey(): void
    {
        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', 'future_feature:
    enabled: true
');

        $resolution = $this->resolver->resolve($this->defaultsPath, $cwd);
        $value = $resolution->getValue('future_feature.enabled');

        $this->assertTrue($value->exists);
        $this->assertTrue($value->value);
        $this->assertSame(SettingsLayerEnum::User, $value->layer);
    }
}
