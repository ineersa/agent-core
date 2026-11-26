<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\Exception\ExtensionSetupRefusalException;
use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Extension\ExtensionUpdateService;
use Ineersa\CodingAgent\Tests\Support\ExtensionUpdateFixture;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ExtensionsSetupFlowTest extends PerMethodIsolatedKernelTestCase
{
    protected const bool ISOLATE_HOME = true;

    // This fixture declares an altered copy of a real extension class. PHP cannot
    // unload it: isolate both incoming declarations and leakage to later tests.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testObservationalMemoryConfigureAndUninstallUseSelectedScope(): void
    {
        $package = 'ineersa/hatfield-ext-observational-memory';
        $class = 'Ineersa\\HatfieldExt\\ObservationalMemory\\ObservationalMemoryExtension';
        $source = \dirname(__DIR__, 4).'/.hatfield/extensions/observational-memory';
        $fixture = new ExtensionUpdateFixture();
        $roots = [];
        try {
            foreach ([$this->isolatedCwd(), $this->isolatedHome()] as $base) {
                $scope = $base.'/.hatfield';
                $this->assertSame($scope, realpath($scope));
                $this->assertFileDoesNotExist($scope.'/extensions');
                $this->assertFalse(is_link($scope.'/extensions'));
                $this->assertFalse(is_link($scope.'/settings.yaml'));
                $root = $scope.'/extensions';
                $roots[] = $root;
                $repo = $root.'/packages/om';
                $fixture->filesystem->mirror($source.'/src', $repo.'/src');
                if ($base === $this->isolatedCwd()) {
                    $entry = $repo.'/src/ObservationalMemoryExtension.php';
                    $fixture->filesystem->dumpFile($entry, str_replace('return new ObservationalMemoryConfiguration();', <<<'PHP'
                        return new class implements ConfigurationInterface {
                            public function getConfigTreeBuilder(): \Symfony\Component\Config\Definition\Builder\TreeBuilder
                            {
                                return new \Symfony\Component\Config\Definition\Builder\TreeBuilder('safe_guard');
                            }
                        };
                        PHP, file_get_contents($entry)));
                }
                $fixture->filesystem->dumpFile($repo.'/composer.json', json_encode([
                    'name' => $package, 'version' => '1.0.0',
                    'autoload' => ['psr-4' => ['Ineersa\\HatfieldExt\\ObservationalMemory\\' => 'src/']],
                    'extra' => ['hatfield' => ['extension' => $class]],
                ], \JSON_THROW_ON_ERROR));
                $fixture->filesystem->dumpFile($root.'/composer.json', json_encode([
                    'require' => [$package => '*'],
                    'config' => ['autoloader-suffix' => 'SetupFixture'.md5($root), 'prepend-autoloader' => false],
                    'repositories' => [
                        'packagist.org' => false,
                        'fixture' => ['type' => 'path', 'url' => 'packages/om', 'options' => ['symlink' => false]],
                    ],
                ], \JSON_THROW_ON_ERROR));
                (new Process([$fixture->composer, 'install', '--no-interaction', '--no-scripts', '--no-plugins'], $root, ExtensionUpdateService::environment($root), timeout: 10))->mustRun();
                $fixture->filesystem->dumpFile($scope.'/settings.yaml', Yaml::dump([
                    'extensions' => ['enabled' => [$class, SafeGuardExtension::class], 'settings' => ['observational_memory' => ['librarian' => ['url' => 'https://fixture.invalid']], 'safe_guard' => ['auto_deny_in_noninteractive' => true]]],
                    'forks' => ['extensions' => ['always_on' => [$class]]],
                    'agents' => ['extensions' => ['always_on' => [$class]]],
                ], 8));
            }
            $flow = self::getContainer()->get(ExtensionsSetupFlow::class);
            $projectSettings = $this->isolatedCwd().'/.hatfield/settings.yaml';
            $projectBefore = file_get_contents($projectSettings);
            try {
                $flow->select($package);
                $this->fail('A provider must not claim another extension’s settings root.');
            } catch (ExtensionSetupRefusalException $error) {
                $this->assertStringContainsString('reserved settings root', $error->getMessage());
            }
            $this->assertSame($projectBefore, file_get_contents($projectSettings));
            $flow->layer = SettingsLayerEnum::User;
            try {
                $flow->select($package);
                $this->fail('The already declared project class must not be mistaken for the user copy.');
            } catch (ExtensionSetupRefusalException $error) {
                $this->assertStringContainsString('Another copy', $error->getMessage());
            }
            $homeSettings = $this->isolatedHome().'/.hatfield/settings.yaml';
            $before = file_get_contents($homeSettings);
            $flow->layer = SettingsLayerEnum::Project;
            $manifestBefore = file_get_contents($roots[0].'/composer.json');
            $lockBefore = file_get_contents($roots[0].'/composer.lock');
            $metadataBefore = file_get_contents($roots[0].'/vendor/composer/installed.json');
            $this->assertTrue(chmod($projectSettings, 0000));
            try {
                $flow->uninstall($package);
                $this->fail('Unreadable settings must refuse removal.');
            } catch (ExtensionSetupRefusalException $error) {
                $this->assertStringContainsString('readable regular file', $error->getMessage());
            } finally {
                $this->assertTrue(chmod($projectSettings, 0600));
            }
            $this->assertSame($projectBefore, file_get_contents($projectSettings));
            $this->assertSame($manifestBefore, file_get_contents($roots[0].'/composer.json'));
            $this->assertSame($lockBefore, file_get_contents($roots[0].'/composer.lock'));
            $this->assertSame($metadataBefore, file_get_contents($roots[0].'/vendor/composer/installed.json'));
            $storedData = $this->isolatedCwd().'/.hatfield/fixture-memory';
            $this->assertFalse(is_link($storedData));
            $fixture->filesystem->dumpFile($storedData, 'fixture data');
            $flow->uninstall($package);
            $this->assertSame('fixture data', file_get_contents($storedData));
            $settings = Yaml::parseFile($projectSettings);
            $this->assertSame([SafeGuardExtension::class], $settings['extensions']['enabled']);
            $this->assertSame(['safe_guard' => ['auto_deny_in_noninteractive' => true]], $settings['extensions']['settings']);
            $this->assertSame([], $settings['forks']['extensions']['always_on']);
            $this->assertSame([], $settings['agents']['extensions']['always_on']);
            $this->assertSame($before, file_get_contents($homeSettings));
            $this->assertDirectoryExists($roots[1].'/vendor/'.$package);
            $this->assertDirectoryDoesNotExist($roots[0].'/vendor/'.$package);
            $flow->layer = SettingsLayerEnum::User;
            $fixture->filesystem->remove($homeSettings);
            $homeManifest = file_get_contents($roots[1].'/composer.json');
            foreach (['directory', 'dangling link'] as $kind) {
                if ('directory' === $kind) {
                    $fixture->filesystem->mkdir($homeSettings);
                } else {
                    $fixture->filesystem->symlink($homeSettings.'.absent', $homeSettings);
                }
                try {
                    $flow->uninstall($package);
                    $this->fail('An existing '.$kind.' is not absent settings.');
                } catch (ExtensionSetupRefusalException $error) {
                    $this->assertStringContainsString('Nothing was removed', $error->getMessage());
                    $this->assertSame($homeManifest, file_get_contents($roots[1].'/composer.json'));
                    $this->assertDirectoryExists($roots[1].'/vendor/'.$package);
                } finally {
                    $fixture->filesystem->remove($homeSettings);
                }
            }
            $flow->uninstall($package);
            $this->assertFileDoesNotExist($homeSettings);
            $this->assertDirectoryDoesNotExist($roots[1].'/vendor/'.$package);
            $this->assertSame($settings, Yaml::parseFile($projectSettings));
        } finally {
            foreach ($roots as $root) {
                (\Composer\Autoload\ClassLoader::getRegisteredLoaders()[$root.'/vendor'] ?? null)?->unregister();
            }
            $fixture->close();
        }
    }

    public function testFreshProjectOffersBuiltinsAndSupportedOptionalPackages(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);

        $this->assertSame([
            'safe_guard' => ['label' => 'SafeGuard (built in)', 'installed' => true, 'class' => SafeGuardExtension::class],
            'scheduling' => ['label' => 'Scheduling (built in)', 'installed' => true, 'class' => SchedulingExtension::class],
            'hatfield_360' => ['label' => 'Hatfield 360 (built in)', 'installed' => true, 'class' => Hatfield360Extension::class],
            'ineersa/hatfield-ext-task-workflow' => ['label' => 'Task Workflow', 'installed' => false, 'class' => null],
            'ineersa/hatfield-ext-observational-memory' => ['label' => 'Observational Memory', 'installed' => false, 'class' => null],
        ], $flow->packages());
    }

    public function testBundledHatfield360CanBeEnabledInUserSettings(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);
        $writer = self::getContainer()->get(SettingsOverrideWriter::class);
        $flow->layer = SettingsLayerEnum::User;
        $path = $writer->layerPath($flow->layer, $this->isolatedCwd());
        $this->assertStringStartsWith($this->isolatedCwd().'/', $path);
        $this->assertSame(\dirname($path), realpath(\dirname($path)));
        $this->assertFalse(is_link($path));
        $this->assertTrue($flow->isBuiltIn('hatfield_360'));

        $flow->select('hatfield_360');
        $this->assertSame('false', $flow->members['parent']);
        $this->assertSame([], $flow->nodes);
        $flow->members['parent'] = 'true';
        $this->assertTrue($flow->save([]));
        $this->assertContains(Hatfield360Extension::class, Yaml::parseFile($path)['extensions']['enabled']);
    }

    public function testFutureOfficialSuggestionsRemainVisibleWithoutAnUninstallIdentity(): void
    {
        $fixture = new ExtensionUpdateFixture();
        try {
            $manifest = $fixture->directory.'/composer.json';
            $this->assertSame($fixture->directory, realpath(\dirname($manifest)));
            $this->assertFalse(is_link($manifest));
            $package = 'ineersa/hatfield-ext-future';
            $fixture->filesystem->dumpFile($manifest, json_encode(['suggest' => [$package => 'Future Extension', 'vendor/unrelated' => 'Unrelated']], \JSON_THROW_ON_ERROR));
            $container = self::getContainer();
            $flow = new ExtensionsSetupFlow(
                $container->get(AppConfig::class),
                $container->get(AppConfigLoader::class),
                new AppResourceLocator($fixture->directory),
                $container->get(SettingsOverrideWriter::class),
                $container->get(ExtensionPackageInstaller::class),
                $container->get(ExtensionManager::class),
                [],
            );
            $rows = $flow->packages();
            $this->assertSame(['label' => 'Future Extension', 'installed' => false, 'class' => null], $rows[$package]);
            $this->assertArrayNotHasKey('vendor/unrelated', $rows);
            $this->expectException(ExtensionSetupRefusalException::class);
            $this->expectExceptionMessage('No trusted uninstall identity');
            $flow->uninstall($package);
        } finally {
            $fixture->close();
        }
    }

    public function testDisablingParentWithDefaultChildSelectionsIsRefused(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);
        $flow->select('safe_guard');
        $this->assertSame(['parent' => 'true', 'forks' => 'always_on', 'agents' => 'true'], $flow->members);
        $flow->members['parent'] = 'false';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Enable the parent extension before selecting it for forks or agents.');
        $flow->save([]);
    }
}
