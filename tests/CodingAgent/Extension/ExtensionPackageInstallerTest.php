<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageInstallException;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Offline Composer proof via PATH shim {@see Support/fake-composer.php}.
 *
 * Production discovers `composer` with Symfony ExecutableFinder. Tests place a
 * shim named `composer` first on PATH; no live Packagist or host composer.json.
 */
final class ExtensionPackageInstallerTest extends TestCase
{
    private string $tmpDir = '';
    private string $homeDir = '';
    private string $projectDir = '';
    private string $pathDir = '';
    private string $originalPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('ext-pkg-installer');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        $this->pathDir = $this->tmpDir.'/bin';
        TestDirectoryIsolation::ensureDirectory($this->homeDir);
        TestDirectoryIsolation::ensureDirectory($this->projectDir);
        TestDirectoryIsolation::ensureDirectory($this->pathDir);

        $fakeComposer = \dirname(__DIR__).'/Extension/Support/fake-composer.php';
        $this->assertFileExists($fakeComposer);
        $shim = $this->pathDir.'/composer';
        file_put_contents($shim, "#!/usr/bin/env bash\nexec ".escapeshellarg(\PHP_BINARY).' '.escapeshellarg($fakeComposer)." \"$@\"\n");
        chmod($shim, 0755);

        $this->originalPath = (string) getenv('PATH');
        $path = $this->pathDir.(\PATH_SEPARATOR.($this->originalPath ?: '/usr/bin:/bin'));
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    }

    protected function tearDown(): void
    {
        putenv('FAKE_COMPOSER_FAIL_MARKER');
        unset($_ENV['FAKE_COMPOSER_FAIL_MARKER'], $_SERVER['FAKE_COMPOSER_FAIL_MARKER']);
        putenv('PATH='.$this->originalPath);
        $_ENV['PATH'] = $this->originalPath;
        $_SERVER['PATH'] = $this->originalPath;
        if ('' !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testInstallsIntoSelectedProjectRootAndPreservesExistingManifestEntries(): void
    {
        $root = $this->projectDir.'/.hatfield/extensions';
        TestDirectoryIsolation::ensureDirectory($root);
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'ineersa/hatfield-extensions',
            'require' => [
                'php' => '>=8.3',
                'ineersa/existing-extension' => '@dev',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'existing-extension',
                ],
            ],
            'config' => [
                'sort-packages' => true,
                // Intentionally wrong; installer must force false before require.
                'prepend-autoloader' => true,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        TestDirectoryIsolation::ensureDirectory(\dirname($settingsPath));
        file_put_contents($settingsPath, "tui:\n    theme: cyberpunk\n");

        $installer = $this->installer();
        $result = $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );

        $this->assertFalse($result->alreadyInstalled);
        $this->assertSame($root, $result->root);
        $this->assertTrue($installer->isInstalled(SettingsLayerEnum::Project, $this->projectDir, 'ineersa/hatfield-ext-file-rewind'));

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('@dev', $manifest['require']['ineersa/existing-extension']);
        $this->assertSame('0.0.42', $manifest['require']['ineersa/hatfield-ext-file-rewind']);
        $this->assertSame([['type' => 'path', 'url' => 'existing-extension']], $manifest['repositories']);
        $this->assertFalse($manifest['config']['prepend-autoloader']);
        $this->assertFileExists($root.'/vendor/autoload.php');
        $this->assertFileExists($root.'/vendor/composer/installed.json');

        // Installer must not mutate settings/enablement.
        $this->assertSame("tui:\n    theme: cyberpunk\n", file_get_contents($settingsPath));
        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/extensions/composer.json');
    }

    public function testInstallsIntoUserRootWhenRequested(): void
    {
        $installer = $this->installer();
        $result = $installer->ensureInstalled(
            SettingsLayerEnum::User,
            $this->projectDir,
            'ineersa/hatfield-ext-task-workflow',
            '0.0.42',
        );

        $userRoot = $this->homeDir.'/.hatfield/extensions';
        $this->assertSame($userRoot, $result->root);
        $this->assertTrue($installer->isInstalled(SettingsLayerEnum::User, $this->projectDir, 'ineersa/hatfield-ext-task-workflow'));
        $this->assertFileExists($userRoot.'/composer.json');
        $this->assertFileDoesNotExist($this->projectDir.'/.hatfield/extensions/composer.json');

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($userRoot.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertFalse($manifest['config']['prepend-autoloader']);
    }

    public function testAlreadyInstalledIsReusableWithoutComposerRequire(): void
    {
        $installer = $this->installer();
        $first = $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-observational-memory',
            '0.0.42',
        );
        $this->assertFalse($first->alreadyInstalled);

        $installedPath = $this->projectDir.'/.hatfield/extensions/vendor/composer/installed.json';
        $beforeInstalled = file_get_contents($installedPath);

        $second = $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-observational-memory',
            '0.0.42',
        );

        $this->assertTrue($second->alreadyInstalled);
        $this->assertSame($beforeInstalled, file_get_contents($installedPath));
    }

    public function testManifestOnlyPackageIsNotTreatedAsInstalled(): void
    {
        $root = $this->projectDir.'/.hatfield/extensions';
        TestDirectoryIsolation::ensureDirectory($root.'/vendor/composer');
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'ineersa/hatfield-extensions',
            'require' => [
                'php' => '>=8.3',
                'ineersa/hatfield-ext-file-rewind' => '0.0.42',
                'ineersa/other-extension' => '1.0.0',
            ],
            'config' => [
                'prepend-autoloader' => false,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        // Autoload exists because another package was installed, but selected package is absent
        // from installed.json metadata.
        file_put_contents($root.'/vendor/autoload.php', "<?php\n");
        file_put_contents($root.'/vendor/composer/installed.json', json_encode([
            'packages' => [
                [
                    'name' => 'ineersa/other-extension',
                    'version' => '1.0.0',
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $installer = $this->installer();
        $this->assertFalse($installer->isInstalled(SettingsLayerEnum::Project, $this->projectDir, 'ineersa/hatfield-ext-file-rewind'));

        $result = $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );
        $this->assertFalse($result->alreadyInstalled);
        $this->assertTrue($installer->isInstalled(SettingsLayerEnum::Project, $this->projectDir, 'ineersa/hatfield-ext-file-rewind'));
    }

    public function testAlreadyInstalledCorrectsUnsafePrependAutoloaderWithoutRequire(): void
    {
        $root = $this->projectDir.'/.hatfield/extensions';
        TestDirectoryIsolation::ensureDirectory($root.'/vendor/composer');
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'ineersa/hatfield-extensions',
            'require' => [
                'php' => '>=8.3',
                'ineersa/hatfield-ext-task-workflow' => '0.0.42',
            ],
            'config' => [
                'prepend-autoloader' => true,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($root.'/vendor/autoload.php', "<?php\n// stale\n");
        file_put_contents($root.'/vendor/composer/installed.json', json_encode([
            'packages' => [
                [
                    'name' => 'ineersa/hatfield-ext-task-workflow',
                    'version' => '0.0.42',
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $installer = $this->installer();
        $result = $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-task-workflow',
            '0.0.42',
        );

        $this->assertTrue($result->alreadyInstalled);
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertFalse($manifest['config']['prepend-autoloader']);
        $autoload = (string) file_get_contents($root.'/vendor/autoload.php');
        $this->assertStringContainsString('dump-autoload', $autoload);
        $this->assertStringNotContainsString('stale', $autoload);
        // Package set unchanged (no require).
        $this->assertSame(
            [
                [
                    'name' => 'ineersa/hatfield-ext-task-workflow',
                    'version' => '0.0.42',
                ],
            ],
            json_decode((string) file_get_contents($root.'/vendor/composer/installed.json'), true, 512, \JSON_THROW_ON_ERROR)['packages'],
        );
    }

    public function testFailedInstallDoesNotWriteEnablementOrSettings(): void
    {
        $settingsWriter = new SettingsOverrideWriter(
            new SettingsPathResolver($this->projectDir, $this->homeDir),
            PropertyAccess::createPropertyAccessor(),
            new Filesystem(),
        );
        $settingsWriter->set(SettingsLayerEnum::Project, $this->projectDir, 'tui.theme', 'cyberpunk');
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $before = (string) file_get_contents($settingsPath);

        $failMarker = $this->tmpDir.'/fail-composer';
        file_put_contents($failMarker, '1');
        putenv('FAKE_COMPOSER_FAIL_MARKER='.$failMarker);
        $_ENV['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;
        $_SERVER['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;

        $installer = $this->installer();

        try {
            $installer->ensureInstalled(
                SettingsLayerEnum::Project,
                $this->projectDir,
                'ineersa/hatfield-ext-file-rewind',
                '0.0.42',
            );
            $this->fail('Expected ExtensionPackageInstallException');
        } catch (ExtensionPackageInstallException $e) {
            $this->assertStringContainsString('Composer require failed', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($settingsPath));
        $this->assertFalse($installer->isInstalled(SettingsLayerEnum::Project, $this->projectDir, 'ineersa/hatfield-ext-file-rewind'));
        $root = $this->projectDir.'/.hatfield/extensions';
        if (is_file($root.'/composer.json')) {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('ineersa/hatfield-ext-file-rewind', $manifest['require'] ?? []);
        }
    }

    public function testFailedRequireStillLeavesPrependAutoloaderDisabled(): void
    {
        $root = $this->projectDir.'/.hatfield/extensions';
        TestDirectoryIsolation::ensureDirectory($root);
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'ineersa/hatfield-extensions',
            'require' => [
                'php' => '>=8.3',
            ],
            'config' => [
                'prepend-autoloader' => true,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        TestDirectoryIsolation::ensureDirectory(\dirname($settingsPath));
        file_put_contents($settingsPath, "tui:\n    theme: cyberpunk\n");
        $before = (string) file_get_contents($settingsPath);

        $failMarker = $this->tmpDir.'/fail-composer-prepend';
        file_put_contents($failMarker, '1');
        putenv('FAKE_COMPOSER_FAIL_MARKER='.$failMarker);
        $_ENV['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;
        $_SERVER['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;

        try {
            $this->installer()->ensureInstalled(
                SettingsLayerEnum::Project,
                $this->projectDir,
                'ineersa/hatfield-ext-file-rewind',
                '0.0.42',
            );
            $this->fail('Expected ExtensionPackageInstallException');
        } catch (ExtensionPackageInstallException $e) {
            $this->assertStringContainsString('Composer require failed', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($settingsPath));
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertFalse($manifest['config']['prepend-autoloader']);
        $this->assertArrayNotHasKey('ineersa/hatfield-ext-file-rewind', $manifest['require'] ?? []);
    }

    public function testComposer1ListInstalledJsonIsNotTreatedAsInstalled(): void
    {
        $root = $this->projectDir.'/.hatfield/extensions';
        TestDirectoryIsolation::ensureDirectory($root.'/vendor/composer');
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'ineersa/hatfield-extensions',
            'require' => [
                'php' => '>=8.3',
                'ineersa/hatfield-ext-task-workflow' => '0.0.42',
            ],
            'config' => [
                'prepend-autoloader' => false,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($root.'/vendor/autoload.php', "<?php\n");
        // Composer 1 emitted a bare package list; Hatfield requires packages[].
        file_put_contents($root.'/vendor/composer/installed.json', json_encode([
            [
                'name' => 'ineersa/hatfield-ext-task-workflow',
                'version' => '0.0.42',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $this->assertFalse(
            $this->installer()->isInstalled(
                SettingsLayerEnum::Project,
                $this->projectDir,
                'ineersa/hatfield-ext-task-workflow',
            ),
        );
    }

    public function testMalformedManifestPropagatesTypedError(): void
    {
        $root = $this->projectDir.'/.hatfield/extensions';
        TestDirectoryIsolation::ensureDirectory($root);
        file_put_contents($root.'/composer.json', '{not-json');

        $this->expectException(ExtensionPackageInstallException::class);
        $this->expectExceptionMessage('Invalid composer.json');
        $this->installer()->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );
    }

    public function testDefaultsLayerIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->installer()->ensureInstalled(
            SettingsLayerEnum::Defaults,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );
    }

    private function installer(): ExtensionPackageInstaller
    {
        return new ExtensionPackageInstaller(
            pathResolver: new SettingsPathResolver($this->projectDir, $this->homeDir),
            filesystem: new Filesystem(),
        );
    }
}
