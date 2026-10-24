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
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSettings;
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

    #[Test]
    public function omModelAllowsNestedProviderModelIdsAndRejectsAliases(): void
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
        $state['settings']['model'] = 'local/Qwen/Qwen3';

        $flow->save($state);
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame('local/Qwen/Qwen3', $parsed['extensions']['settings']['observational_memory']['model']);

        // Unrelated edit of an already-enabled OM config must keep the nested model.
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $this->assertSame('local/Qwen/Qwen3', $state['settings']['model']);
        $state['settings']['observer']['context_window_ratio'] = 0.55;
        $flow->save($state);
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame('local/Qwen/Qwen3', $parsed['extensions']['settings']['observational_memory']['model']);
        $this->assertSame(0.55, $parsed['extensions']['settings']['observational_memory']['observer']['context_window_ratio']);

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $state['settings']['model'] = '@favorite';
        try {
            $flow->save($state);
            $this->fail('Expected alias rejection');
        } catch (ExtensionsSetupValidationException $e) {
            $this->assertStringContainsString('exact provider/model', $e->getMessage());
        }
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame('local/Qwen/Qwen3', $parsed['extensions']['settings']['observational_memory']['model']);
    }

    #[Test]
    public function safeGuardListFieldsAcceptYamlSequencesAndRejectDelimiterText(): void
    {
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $state['parentEnabled'] = false;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['allow_command_patterns'] = ['echo hello, world', '  keep spaces  '];
        $flow->save($state);

        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame(
            ['echo hello, world', '  keep spaces  '],
            $parsed['extensions']['settings']['safe_guard']['allow_command_patterns'],
        );

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $state['settings']['allow_command_patterns'] = 'echo hello, world';
        try {
            $flow->save($state);
            $this->fail('Expected YAML sequence refusal');
        } catch (ExtensionsSetupValidationException $e) {
            $this->assertStringContainsString('YAML sequence', $e->getMessage());
        }

        $this->assertSame(
            ['echo hello, world', '  keep spaces  '],
            Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml')['extensions']['settings']['safe_guard']['allow_command_patterns'],
        );

        $this->assertSame(
            ['echo hello, world', '  keep spaces  '],
            $flow->parseListField(
                $flow->formatListField(['echo hello, world', '  keep spaces  ']),
                ['echo hello, world', '  keep spaces  '],
            ),
        );
    }

    #[Test]
    public function untouchedSafeGuardSaveWritesNothingForFreshProjectAndUser(): void
    {
        $projectSettings = $this->projectDir.'/.hatfield/settings.yaml';
        $userSettings = $this->homeDir.'/.hatfield/settings.yaml';
        $this->assertFileDoesNotExist($projectSettings);
        $this->assertFileDoesNotExist($userSettings);

        $projectFlow = $this->flow(project: true);
        $projectFlow->save($projectFlow->formStateFor('safe-guard'));
        $this->assertFalse($projectFlow->wroteSomething());
        $this->assertSame([], $projectFlow->summaryLines());
        $this->assertFileDoesNotExist($projectSettings);

        $userFlow = $this->flow(project: false);
        $userFlow->save($userFlow->formStateFor('safe-guard'));
        $this->assertFalse($userFlow->wroteSomething());
        $this->assertSame([], $userFlow->summaryLines());
        $this->assertFileDoesNotExist($userSettings);
    }

    #[Test]
    public function unrelatedDisableDoesNotPinInheritedUserForkListsOrUnchangedConfig(): void
    {
        $userSettings = $this->homeDir.'/.hatfield/settings.yaml';
        file_put_contents($userSettings, <<<'YAML'
forks:
    extensions:
        always_on:
            - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
            - Acme\UserOnly\KeepMeExtension
        enabled:
            - Acme\UserOnly\OptionalExtension
agents:
    extensions:
        always_on:
            - Acme\UserOnly\AgentExtension
YAML);
        $beforeUser = (string) file_get_contents($userSettings);
        $projectSettings = $this->projectDir.'/.hatfield/settings.yaml';
        $this->assertFileDoesNotExist($projectSettings);

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('file-rewind');
        $this->assertTrue($flow->needsInstallConfirmation('file-rewind'));
        $state['parentEnabled'] = false;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $flow->save($state);

        $this->assertFalse($flow->wroteSomething());
        $this->assertSame([], $flow->summaryLines());
        $this->assertFileDoesNotExist($projectSettings);
        $this->assertSame($beforeUser, (string) file_get_contents($userSettings));
        $this->assertDirectoryDoesNotExist($this->projectDir.'/.hatfield/extensions');
    }

    #[Test]
    public function editedGuidedLeafWritesOnlyChangedOverrideAndKeepsInheritedFieldsLive(): void
    {
        $userSettings = $this->homeDir.'/.hatfield/settings.yaml';
        file_put_contents($userSettings, <<<'YAML'
extensions:
    settings:
        safe_guard:
            allow_write_outside_cwd:
                - /user/allowed
            auto_deny_in_noninteractive: false
YAML);

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $this->assertSame(['/user/allowed'], $state['settings']['allow_write_outside_cwd']);
        $this->assertFalse($state['settings']['auto_deny_in_noninteractive']);
        $state['settings']['allow_write_outside_cwd'] = ['/project/override'];
        $flow->save($state);

        $this->assertTrue($flow->wroteSomething());
        $projectParsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame(['/project/override'], $projectParsed['extensions']['settings']['safe_guard']['allow_write_outside_cwd']);
        $this->assertArrayNotHasKey('auto_deny_in_noninteractive', $projectParsed['extensions']['settings']['safe_guard']);
        $this->assertArrayNotHasKey('extensions', $projectParsed['forks'] ?? []);
        $this->assertArrayNotHasKey('extensions', $projectParsed['agents'] ?? []);

        file_put_contents($userSettings, <<<'YAML'
extensions:
    settings:
        safe_guard:
            allow_write_outside_cwd:
                - /user/allowed
            auto_deny_in_noninteractive: true
YAML);
        $reloaded = $this->flow(project: true)->formStateFor('safe-guard');
        $this->assertSame(['/project/override'], $reloaded['settings']['allow_write_outside_cwd']);
        $this->assertTrue($reloaded['settings']['auto_deny_in_noninteractive']);
    }

    #[Test]
    public function omSemanticSearchPersistsConfiguredBlocksAndStaysOffByDefault(): void
    {
        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-observational-memory',
            '0.0.42',
        );

        $flow = $this->flow(project: true);
        $this->assertSame(
            ['local/Qwen/Qwen3', 'local/flash'],
            $flow->catalogModelIds(),
        );

        $state = $flow->formStateFor('observational-memory');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['model'] = 'local/flash';
        $flow->save($state);

        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame('local/flash', $parsed['extensions']['settings']['observational_memory']['model']);
        $this->assertArrayNotHasKey('semantic', $parsed['extensions']['settings']['observational_memory']);

        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $state['settings']['semantic']['embedding_api']['base_url'] = 'http://127.0.0.1:8080/v1';
        $state['settings']['semantic']['embedding_api']['model_id'] = 'text-embedding-3-small';
        $state['settings']['semantic']['embedding_api']['query_prefix'] = 'query:';
        $state['settings']['semantic']['reranker_api']['base_url'] = 'http://127.0.0.1:8081';
        $state['settings']['semantic']['reranker_api']['model_id'] = 'bge-reranker';
        $state['settings']['semantic']['reranker_api']['min_score'] = -0.25;
        $flow->save($state);

        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $semantic = $parsed['extensions']['settings']['observational_memory']['semantic'];
        $this->assertSame('http://127.0.0.1:8080/v1', $semantic['embedding_api']['base_url']);
        $this->assertSame('text-embedding-3-small', $semantic['embedding_api']['model_id']);
        $this->assertSame('query:', $semantic['embedding_api']['query_prefix']);
        $this->assertArrayNotHasKey('chunk_bytes', $semantic['embedding_api']);
        $this->assertArrayNotHasKey('overlap_bytes', $semantic['embedding_api']);
        $this->assertSame('http://127.0.0.1:8081', $semantic['reranker_api']['base_url']);
        $this->assertSame('bge-reranker', $semantic['reranker_api']['model_id']);
        $this->assertSame(-0.25, $semantic['reranker_api']['min_score']);
        $this->assertArrayNotHasKey('batch_size', $semantic['reranker_api']);

        $reloaded = $this->flow(project: true)->formStateFor('observational-memory');
        $this->assertSame(1200, $reloaded['settings']['semantic']['embedding_api']['chunk_bytes']);
        $this->assertSame(192, $reloaded['settings']['semantic']['embedding_api']['overlap_bytes']);
        $this->assertSame(8, $reloaded['settings']['semantic']['reranker_api']['batch_size']);
    }

    #[Test]
    public function omSemanticInvalidValuesRefuseBeforeWrites(): void
    {
        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-observational-memory',
            '0.0.42',
        );

        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['model'] = 'local/flash';
        $flow->save($state);
        $before = (string) file_get_contents($settingsPath);

        $cases = [
            [
                'mutate' => static function (array &$settings): void {
                    $settings['semantic']['embedding_api']['base_url'] = 'http://127.0.0.1:8080';
                },
                'needle' => 'both base_url and model_id',
            ],
            [
                'mutate' => static function (array &$settings): void {
                    $settings['semantic']['reranker_api']['base_url'] = 'http://127.0.0.1:8081';
                    $settings['semantic']['reranker_api']['model_id'] = 'bge-reranker';
                },
                'needle' => 'requires semantic.embedding_api',
            ],
            [
                'mutate' => static function (array &$settings): void {
                    $settings['semantic']['embedding_api']['base_url'] = 'http://user@127.0.0.1:8080';
                    $settings['semantic']['embedding_api']['model_id'] = 'emb';
                },
                'needle' => 'without credentials, query, or fragment',
            ],
            [
                'mutate' => static function (array &$settings): void {
                    $settings['semantic']['embedding_api']['base_url'] = 'http://127.0.0.1:8080';
                    $settings['semantic']['embedding_api']['model_id'] = 'emb';
                    $settings['semantic']['embedding_api']['chunk_bytes'] = 8;
                    $settings['semantic']['embedding_api']['overlap_bytes'] = 8;
                },
                'needle' => 'smaller than chunk_bytes',
            ],
        ];

        foreach ($cases as $case) {
            $flow = $this->flow(project: true);
            $state = $flow->formStateFor('observational-memory');
            $case['mutate']($state['settings']);
            try {
                $flow->save($state);
                $this->fail('Expected semantic validation refusal for '.$case['needle']);
            } catch (ExtensionsSetupValidationException $e) {
                $this->assertStringContainsString($case['needle'], $e->getMessage());
            }
            $this->assertSame($before, (string) file_get_contents($settingsPath));
        }
    }

    #[Test]
    public function omSemanticResetClearsBlocksWithoutNullLeafMaps(): void
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
        $state['settings']['model'] = 'local/flash';
        $state['settings']['semantic']['embedding_api']['base_url'] = 'http://127.0.0.1:8080/v1';
        $state['settings']['semantic']['embedding_api']['model_id'] = 'text-embedding-3-small';
        $state['settings']['semantic']['reranker_api']['base_url'] = 'http://127.0.0.1:8081';
        $state['settings']['semantic']['reranker_api']['model_id'] = 'bge-reranker';
        $state['settings']['semantic']['reranker_api']['min_score'] = -0.25;
        $flow->save($state);

        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertNotNull(SemanticSettings::fromArray($parsed['extensions']['settings']['observational_memory']['semantic']));

        // Clear min_score only: key must disappear, embeddings/reranker remain valid.
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $state['settings']['semantic']['reranker_api']['min_score'] = '';
        $flow->save($state);
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $semantic = $parsed['extensions']['settings']['observational_memory']['semantic'];
        $this->assertArrayNotHasKey('min_score', $semantic['reranker_api']);
        $this->assertNotNull(SemanticSettings::fromArray($semantic));

        // Disable reranker only.
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $state['settings']['semantic']['reranker_api']['base_url'] = '';
        $state['settings']['semantic']['reranker_api']['model_id'] = '';
        $flow->save($state);
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $semantic = $parsed['extensions']['settings']['observational_memory']['semantic'];
        $this->assertArrayNotHasKey('reranker_api', $semantic);
        $this->assertNotNull(SemanticSettings::fromArray($semantic));

        // Disable semantic search entirely.
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('observational-memory');
        $state['settings']['semantic']['embedding_api']['base_url'] = '';
        $state['settings']['semantic']['embedding_api']['model_id'] = '';
        $flow->save($state);
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertArrayNotHasKey('semantic', $parsed['extensions']['settings']['observational_memory']);
        $this->assertNull(SemanticSettings::fromArray(null));
    }

    #[Test]
    public function omSemanticResetMasksInheritedBlocksAndReportsInheritedMinScore(): void
    {
        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::User,
            $this->homeDir,
            'ineersa/hatfield-ext-observational-memory',
            '0.0.42',
        );
        $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-observational-memory',
            '0.0.42',
        );

        $userFlow = $this->flow(project: false);
        $state = $userFlow->formStateFor('observational-memory');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['model'] = 'local/flash';
        $state['settings']['semantic']['embedding_api']['base_url'] = 'http://127.0.0.1:8080/v1';
        $state['settings']['semantic']['embedding_api']['model_id'] = 'text-embedding-3-small';
        $state['settings']['semantic']['reranker_api']['base_url'] = 'http://127.0.0.1:8081';
        $state['settings']['semantic']['reranker_api']['model_id'] = 'bge-reranker';
        $state['settings']['semantic']['reranker_api']['min_score'] = -0.25;
        $userFlow->save($state);

        $projectFlow = $this->flow(project: true);
        $state = $projectFlow->formStateFor('observational-memory');
        $this->assertSame(-0.25, $state['settings']['semantic']['reranker_api']['min_score']);
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['model'] = 'local/flash';
        $state['settings']['semantic']['reranker_api']['min_score'] = '';
        $projectFlow->save($state);
        $projectSettings = $this->projectDir.'/.hatfield/settings.yaml';
        // No local min_score override existed, so clearing it writes nothing and
        // must not invent a null floor. The inherited user value remains effective.
        $this->assertFileDoesNotExist($projectSettings);
        $this->assertTrue(
            [] !== array_filter(
                $projectFlow->summaryLines(),
                static fn (string $line): bool => str_contains($line, 'inherited floor'),
            ),
        );
        $effective = $this->flow(project: true)->formStateFor('observational-memory');
        $this->assertSame(-0.25, $effective['settings']['semantic']['reranker_api']['min_score']);
        $this->assertNotNull(SemanticSettings::fromArray(
            Yaml::parseFile($this->homeDir.'/.hatfield/settings.yaml')['extensions']['settings']['observational_memory']['semantic'],
        ));

        $projectFlow = $this->flow(project: true);
        $state = $projectFlow->formStateFor('observational-memory');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings']['model'] = 'local/flash';
        $state['settings']['semantic']['embedding_api']['base_url'] = '';
        $state['settings']['semantic']['embedding_api']['model_id'] = '';
        $projectFlow->save($state);
        $projectParsed = Yaml::parseFile($projectSettings);
        $this->assertNull($projectParsed['extensions']['settings']['observational_memory']['semantic']);
        $this->assertNull(SemanticSettings::fromArray(null));
        // User layer remains configured and valid.
        $this->assertNotNull(SemanticSettings::fromArray(
            Yaml::parseFile($this->homeDir.'/.hatfield/settings.yaml')['extensions']['settings']['observational_memory']['semantic'],
        ));
        // Project null mask disables effective semantic search for this scope.
        $masked = $this->flow(project: true)->formStateFor('observational-memory');
        $this->assertSame('', $masked['settings']['semantic']['embedding_api']['base_url']);
        $this->assertSame('', $masked['settings']['semantic']['embedding_api']['model_id']);
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
