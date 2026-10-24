<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\Exception\ExtensionsSetupValidationException;
use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Tests\Support\ExtensionsSetupTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: ExtensionsSetupFlow installs into the selected scope only, writes
 * enablement/settings after successful install, preserves unrelated keys, keeps
 * user scope free of project-only values, and validates child membership against
 * parent enablement through the unified save path.
 */
#[CoversClass(ExtensionsSetupFlow::class)]
final class ExtensionsSetupFlowTest extends TestCase
{
    private string $tmpDir = '';
    private string $homeDir = '';
    private string $projectDir = '';
    private string $catalogPath = '';
    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('extensions_setup_flow');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/config');
        file_put_contents($this->tmpDir.'/config/hatfield.defaults.yaml', ExtensionsSetupTestSupport::defaultsYaml());
        $this->catalogPath = $this->tmpDir.'/config/hatfield-provided-extensions.yaml';
        file_put_contents($this->catalogPath, ExtensionsSetupTestSupport::catalogYaml());
        $shim = ExtensionsSetupTestSupport::installFakeComposerOnPath($this->tmpDir);
        $this->originalPath = $shim['originalPath'];
    }

    protected function tearDown(): void
    {
        ExtensionsSetupTestSupport::restorePath($this->originalPath);
        if ('' !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
    }

    #[Test]
    public function projectSaveInstallsPackageWritesEnablementAndPreservesUnrelatedKeys(): void
    {
        $projectSettings = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($projectSettings, <<<'YAML'
tui:
    theme: cyberpunk
extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
        - Acme\Unrelated\KeepMeExtension
    settings:
        unrelated_extension:
            keep: true
YAML);

        $flow = $this->flow(project: true);
        $this->assertTrue($flow->needsInstallConfirmation('file-rewind'));
        $flow->install('file-rewind');
        $this->assertFalse($flow->needsInstallConfirmation('file-rewind'));

        $state = $flow->formStateFor('file-rewind');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = true;
        $state['agentsAlwaysOn'] = false;
        $state['settings'] = [
            'enabled' => true,
            'max_retained_turns' => 42,
            'git_timeout_seconds' => 15,
        ];
        $flow->save($state);

        $parsed = Yaml::parseFile($projectSettings);
        $this->assertSame('cyberpunk', $parsed['tui']['theme']);
        $this->assertTrue($parsed['extensions']['settings']['unrelated_extension']['keep']);
        $this->assertContains(ExtensionsSetupTestSupport::FILE_REWIND, $parsed['extensions']['enabled']);
        $this->assertContains('Acme\\Unrelated\\KeepMeExtension', $parsed['extensions']['enabled']);
        $this->assertSame(42, $parsed['extensions']['settings']['file_rewind']['max_retained_turns']);
        $this->assertContains(ExtensionsSetupTestSupport::FILE_REWIND, $parsed['forks']['extensions']['enabled']);
        $this->assertFileExists($this->projectDir.'/.hatfield/extensions/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/settings.yaml');
    }

    #[Test]
    public function userScopeDoesNotImportProjectOnlyOverridesAndCannotReuseProjectPackage(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/settings.yaml', <<<'YAML'
extensions:
    settings:
        task_workflow:
            task_root: /only/in/project
YAML);

        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-task-workflow',
            '0.0.42',
        );

        $flow = $this->flow(project: false);
        $state = $flow->formStateFor('task-workflow');
        $this->assertSame('', $state['settings']['task_root']);
        $this->assertTrue($flow->needsInstallConfirmation('task-workflow'));

        $flow->install('task-workflow');
        $state = $flow->formStateFor('task-workflow');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings'] = ['task_root' => '/user/board'];
        $flow->save($state);

        $userParsed = Yaml::parseFile($this->homeDir.'/.hatfield/settings.yaml');
        $this->assertSame('/user/board', $userParsed['extensions']['settings']['task_workflow']['task_root']);
        $this->assertSame('/only/in/project', Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml')['extensions']['settings']['task_workflow']['task_root']);
        $this->assertFileExists($this->homeDir.'/.hatfield/extensions/vendor/autoload.php');
    }

    #[Test]
    public function interactiveScopeSwitchWritesUserYamlWithoutProjectOnlyValues(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/settings.yaml', <<<'YAML'
extensions:
    settings:
        safe_guard:
            allow_write_outside_cwd:
                - /only/project/path
YAML);

        $flow = $this->flow(project: true);
        $this->assertTrue($flow->isProjectScope());
        $flow->setProjectScope(false);
        $this->assertFalse($flow->isProjectScope());
        $this->assertStringContainsString('user', $flow->scopeLabel());

        $state = $flow->formStateFor('safe-guard');
        $this->assertSame([], $state['settings']['allow_write_outside_cwd']);
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['allow_write_outside_cwd'] = ['/user/allowed'];
        $flow->save($state);

        $userParsed = Yaml::parseFile($this->homeDir.'/.hatfield/settings.yaml');
        $this->assertSame(['/user/allowed'], $userParsed['extensions']['settings']['safe_guard']['allow_write_outside_cwd']);
        $projectParsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame(['/only/project/path'], $projectParsed['extensions']['settings']['safe_guard']['allow_write_outside_cwd']);
        $this->assertArrayNotHasKey('/only/project/path', array_flip($userParsed['extensions']['settings']['safe_guard']['allow_write_outside_cwd']));
    }

    #[Test]
    public function projectCanReuseUserInstalledPackageWithoutComposerRequire(): void
    {
        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::User,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );

        $flow = $this->flow(project: true);
        $this->assertFalse($flow->needsInstallConfirmation('file-rewind'));
        $state = $flow->formStateFor('file-rewind');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $flow->save($state);

        $this->assertFileDoesNotExist($this->projectDir.'/.hatfield/extensions/composer.json');
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertContains(ExtensionsSetupTestSupport::FILE_REWIND, $parsed['extensions']['enabled']);
    }

    #[Test]
    public function failedInstallLeavesSettingsUnchanged(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($settingsPath, "tui:\n    theme: cyberpunk\n");
        $before = (string) file_get_contents($settingsPath);

        $failMarker = $this->tmpDir.'/fail-composer';
        file_put_contents($failMarker, '1');
        putenv('FAKE_COMPOSER_FAIL_MARKER='.$failMarker);
        $_ENV['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;
        $_SERVER['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;

        $flow = $this->flow(project: true);
        try {
            $flow->install('observational-memory');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Composer require failed', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($settingsPath));
        $this->assertFalse($flow->wroteSomething());
    }

    #[Test]
    public function childSelectionWithoutParentEnablementIsRejected(): void
    {
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $state['parentEnabled'] = false;
        $state['forksAlwaysOn'] = true;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;

        $this->expectException(ExtensionsSetupValidationException::class);
        $this->expectExceptionMessage('Child selections require the extension to be enabled for the parent');
        $flow->save($state);
    }

    #[Test]
    public function disableClearsChildMembershipWithoutInstallForce(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($settingsPath, <<<'YAML'
extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
    settings:
        safe_guard:
            allow_write_outside_cwd:
                - /keep/me
forks:
    extensions:
        always_on:
            - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
        enabled: []
agents:
    extensions:
        always_on:
            - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
YAML);

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $state['parentEnabled'] = false;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $flow->save($state);

        $parsed = Yaml::parseFile($settingsPath);
        $this->assertNotContains(ExtensionsSetupTestSupport::SAFE_GUARD, $parsed['extensions']['enabled']);
        $this->assertSame([], $parsed['forks']['extensions']['always_on']);
        $this->assertSame([], $parsed['agents']['extensions']['always_on']);
        $this->assertSame(['/keep/me'], $parsed['extensions']['settings']['safe_guard']['allow_write_outside_cwd']);
    }

    #[Test]
    public function disabledSafeGuardGuidedEditPersistsWithoutEnabling(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($settingsPath, <<<'YAML'
extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
    settings:
        safe_guard:
            allow_write_outside_cwd:
                - /keep/me
YAML);

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $state['parentEnabled'] = false;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['allow_write_outside_cwd'] = ['/keep/me', '/tmp/extra'];
        $flow->save($state);

        $parsed = Yaml::parseFile($settingsPath);
        $this->assertNotContains(ExtensionsSetupTestSupport::SAFE_GUARD, $parsed['extensions']['enabled']);
        $this->assertSame(['/keep/me', '/tmp/extra'], $parsed['extensions']['settings']['safe_guard']['allow_write_outside_cwd']);
    }

    #[Test]
    public function missingPackageDisableRunsNoComposerAndLeavesSettingsUntouchedWhenUnedited(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($settingsPath, <<<'YAML'
extensions:
    enabled:
        - Ineersa\HatfieldExt\FileRewind\FileRewindExtension
    settings:
        file_rewind:
            enabled: true
            max_retained_turns: 42
YAML);

        $before = (string) file_get_contents($settingsPath);
        $flow = $this->flow(project: true);
        $this->assertTrue($flow->needsInstallConfirmation('file-rewind'));
        $state = $flow->formStateFor('file-rewind');
        $state['parentEnabled'] = false;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $flow->save($state);

        $this->assertDirectoryDoesNotExist($this->projectDir.'/.hatfield/extensions');
        $this->assertFileDoesNotExist($this->projectDir.'/.hatfield/extensions/composer.json');
        $parsed = Yaml::parseFile($settingsPath);
        $this->assertNotContains(ExtensionsSetupTestSupport::FILE_REWIND, $parsed['extensions']['enabled'] ?? []);
        $this->assertSame(42, $parsed['extensions']['settings']['file_rewind']['max_retained_turns']);
        $this->assertNotSame($before, (string) file_get_contents($settingsPath));
    }

    #[Test]
    public function invalidSettingsShapeRefusesBeforeAnyWrite(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $before = <<<'YAML'
extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
    settings:
        - broken
YAML;
        file_put_contents($settingsPath, $before);

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;

        try {
            $flow->save($state);
            $this->fail('Expected shape refusal');
        } catch (ExtensionsSetupValidationException $e) {
            $this->assertStringContainsString('extensions.settings must be a mapping', $e->getMessage());
        }

        $this->assertSame($before, (string) file_get_contents($settingsPath));
    }

    #[Test]
    public function omRatioOneIsRejectedWithoutClamping(): void
    {
        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-observational-memory',
            '0.0.42',
        );

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['model'] = 'llama_cpp/flash';
        $state['settings']['observer']['context_window_ratio'] = 1;

        try {
            $flow->save($state);
            $this->fail('Expected validation refusal');
        } catch (ExtensionsSetupValidationException $e) {
            $this->assertStringContainsString('0 < ratio < 1', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->projectDir.'/.hatfield/settings.yaml');
    }

    #[Test]
    public function safeGuardNeedsNoComposerInstall(): void
    {
        $flow = $this->flow(project: true);
        $this->assertFalse($flow->needsInstallConfirmation('safe-guard'));
        $rows = $flow->extensionRows();
        $safe = null;
        foreach ($rows as $row) {
            if ('safe-guard' === $row['id']) {
                $safe = $row;
                break;
            }
        }
        $this->assertNotNull($safe);
        $this->assertTrue($safe['builtin']);
        $this->assertFalse($safe['needsInstall']);
        $this->assertSame('safe_guard', $safe['settingsKey']);
        $this->assertStringContainsString('built-in', $safe['status']);
        $this->assertSame(ExtensionsSetupTestSupport::SAFE_GUARD, $safe['extensionClass']);
        $this->assertSame(ExtensionsSetupTestSupport::TASK_WORKFLOW, $flow->formStateFor('task-workflow')['extensionClass']);
        $this->assertNotEmpty($flow->formStateFor('file-rewind')['guidedFields']);
    }

    private function flow(bool $project): ExtensionsSetupFlow
    {
        return ExtensionsSetupTestSupport::createFlow(
            $this->tmpDir,
            $this->projectDir,
            $this->homeDir,
            $this->catalogPath,
            $project,
        );
    }
}
