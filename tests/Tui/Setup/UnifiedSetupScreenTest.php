<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlow;
use Ineersa\CodingAgent\CLI\Setup\DefinitionSetupService;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SetupSettingsService;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\Tui\Setup\CoreSettingsScreen;
use Ineersa\Tui\Setup\DefinitionSetupScreen;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Ineersa\Tui\Setup\SetupScreen;
use Ineersa\Tui\Setup\UnifiedSetupScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Yaml\Yaml;

final class UnifiedSetupScreenTest extends PerMethodIsolatedKernelTestCase
{
    protected const bool ISOLATE_HOME = true;

    public function testProviderDraftUsesSelectedSettingsScope(): void
    {
        $container = self::getContainer();
        $cwd = $this->isolatedCwd();
        $writer = $container->get(SettingsOverrideWriter::class);
        $settings = $container->get(SetupSettingsService::class);
        $catalog = $container->get(AiCatalog::class);
        foreach ([SettingsLayerEnum::User, SettingsLayerEnum::Project] as $scope) {
            $project = SettingsLayerEnum::Project === $scope;
            $root = $project ? $cwd : $this->isolatedHome();
            $path = $writer->layerPath($scope, $cwd);
            $this->assertSame($root.'/.hatfield', realpath(\dirname($path)));
            $this->assertFalse(is_link($path));
            $writer->setMany($scope, $cwd, [
                'ai.providers.fixture.base_url' => 'https://example.test',
                'ai.providers.fixture.models' => [$project ? 'project-model' : 'user-model' => ['name' => 'Original']],
            ]);
        }

        $user = ProvidersSetupFlow::for($catalog, $writer, $settings->loadConfig(SettingsLayerEnum::User));
        $project = ProvidersSetupFlow::for($catalog, $writer, $settings->loadConfig(SettingsLayerEnum::Project), true);
        $this->assertSame(['user-model'], array_keys($user->customDefinition('fixture')['models']));
        $this->assertSame(['project-model'], array_keys($project->customDefinition('fixture')['models']));
        $user->updateProviderFields('fixture', ['models' => ['user-model' => ['name' => 'Edited']]]);
        $this->assertSame(['user-model' => ['name' => 'Edited']], $settings->load()->userRaw['ai']['providers']['fixture']['models']);
        $this->assertSame(['project-model' => ['name' => 'Original']], $settings->load()->projectRaw['ai']['providers']['fixture']['models']);
    }

    public function testNavigationAndProjectOverrideSaveAndReset(): void
    {
        $container = self::getContainer();
        $cwd = $this->isolatedCwd();
        $path = $cwd.'/.hatfield/settings.yaml';
        $this->assertSame($cwd.'/.hatfield', realpath(\dirname($path)));
        $this->assertFalse(is_link($path));
        $original = file_get_contents($path);
        $writer = $container->get(SettingsOverrideWriter::class);
        $userPath = $writer->layerPath(SettingsLayerEnum::User, $cwd);
        $this->assertSame($this->isolatedHome().'/.hatfield', realpath(\dirname($userPath)));
        $this->assertFalse(is_link($userPath));
        $originalUser = is_file($userPath) ? file_get_contents($userPath) : null;
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $provider = null;
        $agentDir = $cwd.'/.hatfield/agents';
        $agent = $agentDir.'/fixture.md';
        $rawAgent = "---\nname: fixture\ndescription: Original\n---\nBody\n";
        foreach ([90, 48] as $width) {
            $this->assertFalse(is_link($agentDir));
            $this->assertFalse(is_link($agent));
            $this->assertTrue(!file_exists($agentDir) || realpath($agentDir) === $agentDir);
            (new Filesystem())->dumpFile($agent, $rawAgent);
            $terminal = new VirtualTerminal(columns: $width, rows: 30);
            $tui = new Tui(terminal: $terminal);
            (new UnifiedSetupScreen($theme))->mount($tui, SettingsLayerEnum::User, static function (SettingsLayerEnum $scope) use ($container, $writer, $cwd, $theme, &$provider): array {
                $provider = ProvidersSetupFlow::for($container->get(AiCatalog::class), $writer, $container->get(AppConfig::class), SettingsLayerEnum::Project === $scope);
                $extension = clone $container->get(ExtensionsSetupFlow::class);
                $extension->layer = $scope;

                $service = $container->get(DefinitionSetupService::class);

                return [$writer->layerPath($scope, $cwd), $container->get(CoreSettingsScreen::class), $provider, new SetupScreen($provider), new ExtensionsSetupScreen($extension, $theme, new NullLogger()), new DefinitionSetupScreen($service, $scope, new NullLogger(), $theme, skill: true), new DefinitionSetupScreen($service, $scope, new NullLogger(), $theme, skill: false)];
            });
            $tui->start();
            $this->assertStringContainsString('Preferences', $this->render($tui, $terminal));
            $terminal->simulateInput("\t");
            try {
                $this->assertStringContainsString('AI Provider Setup', $this->render($tui, $terminal));
                $terminal->simulateInput("\x1b[C");
                $this->assertStringContainsString('AI Provider Setup', $this->render($tui, $terminal));
                $terminal->simulateInput("\t");
                $extensions = $this->render($tui, $terminal);
                $this->assertStringContainsString('Manage extensions', $extensions);
                $this->assertStringContainsString('Observational Memory', $extensions);
                if ($width >= 90) {
                    $this->assertMatchesRegularExpression('/Observational Memory[^\r\n]*(Available|Installed)/', $extensions);
                }
                $this->assertStringNotContainsString('→ Back', $extensions);
                $terminal->simulateInput("\t"); // Skills.
                $this->assertStringContainsString('No definitions found', $this->render($tui, $terminal));
                $terminal->simulateInput("\t"); // Agents.
                $this->assertStringContainsString('fixture · project · read-only', $this->render($tui, $terminal));
                if ($width >= 90) {
                    $terminal->simulateInput("\x1bOQ"); // Project.
                    $this->select($tui, $agent);
                    $this->select($tui, 'edit');
                    $this->field($tui, 'name');
                    $terminal->simulateInput('!');
                    $terminal->simulateInput("\t");
                    $this->assertStringContainsString('Confirm or cancel the open editor first', $this->render($tui, $terminal));
                    $terminal->simulateInput("\x1bOQ");
                    $this->assertStringContainsString('Project scope', $this->render($tui, $terminal));
                    $terminal->simulateInput("\r");
                    $this->assertStringContainsString('Not saved', $this->render($tui, $terminal));
                    $this->assertSame($rawAgent, file_get_contents($agent));
                    $this->field($tui, 'description');
                    $terminal->simulateInput(' updated');
                    $terminal->simulateInput("\r");
                    $this->assertStringContainsString('Original updated', (string) file_get_contents($agent));
                    $terminal->simulateInput("\x1b");
                    $this->select($tui, 'body');
                    $terminal->simulateInput('draft');
                    $editor = $tui->getFocus();
                    $this->assertInstanceOf(EditorWidget::class, $editor);
                    $draft = $editor->getText();
                    foreach (["\t", "\x1b[Z", "\x1bOQ"] as $key) {
                        $terminal->simulateInput($key);
                        $this->assertSame($editor, $tui->getFocus());
                        $this->assertSame($draft, $editor->getText());
                        $this->assertStringContainsString('Esc cancels', $this->render($tui, $terminal));
                    }
                    $terminal->simulateInput("\x1b");
                    $this->select($tui, 'delete');
                    $this->assertStringContainsString('Confirm deletion', $this->render($tui, $terminal));
                    $this->select($tui, 'cancel');
                    $this->assertFileExists($agent);
                    $this->select($tui, 'delete');
                    $this->select($tui, 'confirm');
                    $this->assertFileDoesNotExist($agent);
                    $terminal->simulateInput("\x1bOQ"); // User.
                }
                $terminal->simulateInput("\t"); // Settings.
                $terminal->simulateInput("\x1bOQ");
                $this->assertStringContainsString('Project scope', $this->render($tui, $terminal));
                $this->select($tui, 'sessions');
                $text = $this->render($tui, $terminal);
                $this->assertMatchesRegularExpression('/→ Path[^\r\n]*○/', $text);
                $this->assertStringContainsString('● Set here  ○ Not set here', $text);
                $this->assertStringNotContainsString('d · Remove', $text);
                $this->field($tui, 'sessions.path');
                $text = $this->render($tui, $terminal);
                $this->assertMatchesRegularExpression('/Directory\s+that stores/', $text);
                $this->assertStringContainsString('Enter applies', $text);
                $terminal->simulateInput("\r");
                $this->assertSame($original, file_get_contents($path), 'Unchanged inherited path does not become an override.');
                $this->field($tui, 'sessions.path');
                $terminal->simulateInput('d');
                $terminal->simulateInput("\x1b");
                $this->assertSame($original, file_get_contents($path));
                $this->field($tui, 'sessions.path');
                $terminal->simulateInput('/');
                $terminal->simulateInput('d');
                $terminal->simulateInput("\r");
                $this->assertStringEndsWith('/d', Yaml::parseFile($path)['sessions']['path']);
                $text = $this->render($tui, $terminal);
                $this->assertMatchesRegularExpression('/→ Path[^\r\n]*●/', $text);
                $this->assertMatchesRegularExpression('/d · Remove\s+from\s+this\s+scope/', $text);
                $terminal->simulateInput('d');
                $this->assertStringContainsString('Enter confirms', $this->render($tui, $terminal));
                $terminal->simulateInput("\r");
                $this->assertArrayNotHasKey('sessions', Yaml::parseFile($path));
                $text = $this->render($tui, $terminal);
                $this->assertMatchesRegularExpression('/→ Path[^\r\n]*○/', $text);
                $this->assertStringNotContainsString('d · Remove', $text);
                $terminal->simulateInput("\x1b");
                $this->select($tui, 'compaction');
                $this->field($tui, 'compaction.model');
                $terminal->simulateInput("\r");
                $local = Yaml::parseFile($path)['compaction'];
                $this->assertArrayHasKey('model', $local);
                $this->assertNull($local['model']);
                $this->assertMatchesRegularExpression('/→ Model[^\r\n]*●/', $this->render($tui, $terminal));
                $terminal->simulateInput("\x1bOQ");
                $this->select($tui, 'compaction');
                $this->assertMatchesRegularExpression('/Model[^\r\n]*○/', $this->render($tui, $terminal));
                $terminal->simulateInput("\x1bOQ");
                $this->select($tui, 'compaction');
                $terminal->simulateInput('d');
                $terminal->simulateInput("\r");
                $terminal->simulateInput("\x1b");
                $this->select($tui, 'prompts');
                $before = file_get_contents($path);
                $this->field($tui, 'prompts');
                $terminal->simulateInput('[false]');
                $terminal->simulateInput("\r");
                $this->assertSame($before, file_get_contents($path));
                $this->assertStringContainsString('Not saved', $this->render($tui, $terminal));
                $this->field($tui, 'prompts');
                $terminal->simulateInput('[team/prompts]');
                $terminal->simulateInput("\r");
                $this->assertSame(['team/prompts'], Yaml::parseFile($path)['prompts']);
                $terminal->simulateInput('d');
                $terminal->simulateInput("\r");
                $terminal->simulateInput("\x1b");
                $this->select($tui, 'ai');
                $widget = $tui->getFocus();
                $this->assertInstanceOf(SettingsListWidget::class, $widget);
                $this->assertNull($widget->getValue('ai.http.timeout'));
                $this->assertNull($widget->getValue('ai.http.max_retries'));
                $this->assertInstanceOf(ProvidersSetupFlow::class, $provider);
                $provider->saveCustom('live-fixture', 'http://localhost:8080', '/v1/chat/completions', null, ['first' => ['name' => 'First']], '');
                $provider->updateProviderField('live-fixture', 'models', ['new-model' => ['name' => 'New']]);
                $this->field($tui, 'ai.default_model');
                $text = $this->render($tui, $terminal);
                $this->assertStringContainsString('live-fixture/new-model', $text);
                $this->assertStringNotContainsString('live-fixture/first', $text);
                $terminal->simulateInput("\x1b");
                $provider->removeCustom('live-fixture');
                $before = file_get_contents($path);
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b[Z\x1b[Z\x1b[Z"); // Agents → Skills → Extensions.
                $this->select($tui, 'safe_guard');
                $terminal->simulateInput("\x1b"); // Browsing and cancelling do not write.
                $this->assertSame($before, file_get_contents($path));
                $this->select($tui, 'safe_guard');
                $terminal->simulateInput("\r"); // Confirm parent enablement without a Save row.
                $after = file_get_contents($path);
                $this->assertNotSame($before, $after);
                $terminal->simulateInput("\x1bOQ");
                $this->assertStringContainsString('User scope', $this->render($tui, $terminal));
                $terminal->simulateInput("\x1b");
                $this->assertSame($after, file_get_contents($path));
                $this->assertSame($originalUser, is_file($userPath) ? file_get_contents($userPath) : null);
                $this->assertFalse($tui->isRunning());
            } finally {
                $tui->stop();
            }
            $original = file_get_contents($path);
        }
    }

    public function testMaskedUserBooleanTogglesInBothDirections(): void
    {
        $container = self::getContainer();
        $cwd = $this->isolatedCwd();
        $writer = $container->get(SettingsOverrideWriter::class);
        $project = $writer->layerPath(SettingsLayerEnum::Project, $cwd);
        $user = $writer->layerPath(SettingsLayerEnum::User, $cwd);
        $this->assertSame($this->isolatedHome().'/.hatfield', realpath(\dirname($user)));
        $this->assertFalse(is_link($user));
        $this->assertSame($cwd.'/.hatfield', realpath(\dirname($project)));
        $this->assertFalse(is_link($project));
        $writer->set(SettingsLayerEnum::User, $cwd, 'forks.enabled', true);
        $writer->set(SettingsLayerEnum::Project, $cwd, 'forks.enabled', false);
        $terminal = new VirtualTerminal(columns: 120, rows: 30);
        $tui = new Tui(terminal: $terminal);
        $pane = new ContainerWidget();
        $tui->add($pane);
        $flow = ProvidersSetupFlow::for($container->get(AiCatalog::class), $writer, $container->get(AppConfig::class));
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $container->get(CoreSettingsScreen::class)->mount($tui, $pane, SettingsLayerEnum::User, $flow, $tui->stop(...), static fn (string $text) => new TextWidget($text), $theme, static fn () => null);
        $tui->start();
        try {
            $this->select($tui, 'forks');
            $this->assertStringContainsString('Project override masks this value', $this->render($tui, $terminal));
            $this->field($tui, 'forks.enabled');
            $this->assertFalse(Yaml::parseFile($user)['forks']['enabled']);
            $this->field($tui, 'forks.enabled');
            $this->assertTrue(Yaml::parseFile($user)['forks']['enabled']);
            $this->assertFalse(Yaml::parseFile($project)['forks']['enabled']);
        } finally {
            $tui->stop();
            $writer->remove(SettingsLayerEnum::User, $cwd, 'forks.enabled');
            $writer->remove(SettingsLayerEnum::Project, $cwd, 'forks.enabled');
        }
    }

    public function testMaskedAgentTimeoutEditIsStillValidated(): void
    {
        $container = self::getContainer();
        $cwd = $this->isolatedCwd();
        $writer = $container->get(SettingsOverrideWriter::class);
        $project = $writer->layerPath(SettingsLayerEnum::Project, $cwd);
        $this->assertSame($cwd.'/.hatfield', realpath(\dirname($project)));
        $this->assertFalse(is_link($project));
        $user = $writer->layerPath(SettingsLayerEnum::User, $cwd);
        $this->assertSame($this->isolatedHome().'/.hatfield', realpath(\dirname($user)));
        $this->assertFalse(is_link($user));
        $before = is_file($user) ? file_get_contents($user) : null;
        $writer->set(SettingsLayerEnum::Project, $cwd, 'agents.subagent_tool_timeout_seconds', 120);
        $settings = $container->get(SetupSettingsService::class);
        try {
            $settings->save(SettingsLayerEnum::User, ['agents.subagent_tool_timeout_seconds' => 0], []);
            $this->fail('A project override must not hide an invalid user value.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('agents.subagent_tool_timeout_seconds', $error->getMessage());
        }
        $this->assertSame($before, is_file($user) ? file_get_contents($user) : null);
    }

    public function testInventoryCoversSettingsNotPersonallyConfigured(): void
    {
        $inventory = self::getContainer()->get(SetupSettingsService::class)->inventory();
        foreach (['sessions.path', 'agents.subagent_tool_timeout_seconds', 'compaction.model_overrides', 'forks.extensions.enabled', 'prompts'] as $key) {
            $this->assertArrayHasKey($key, $inventory[explode('.', $key)[0]]);
        }
        $this->assertArrayNotHasKey('extensions', $inventory);
        $this->assertArrayNotHasKey('ai.providers', $inventory['ai']);
        $this->assertArrayNotHasKey('ai.http.timeout', $inventory['ai']);
        $this->assertArrayNotHasKey('runtime', $inventory);
        $this->assertArrayNotHasKey('tools.execution.max_parallelism', $inventory['tools']);
        $this->assertSame(250000, $inventory['compaction']['compaction.compact_after_tokens']['sample']);
        $this->assertSame(200000, $inventory['context_budget_reminders']['context_budget_reminders.early_input_tokens']['sample']);
        $missing = [];
        foreach ($inventory as $fields) {
            foreach ($fields as $key => $field) {
                if ('' === $field['help']) {
                    $missing[] = $key;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function testInventoryDefaultsMatchRuntimeFallbacks(): void
    {
        $container = self::getContainer();
        $defaults = $container->get(SetupSettingsService::class)->load()->defaultsRaw;
        $legacy = $defaults;
        unset($legacy['agents']['subagent_tool_timeout_seconds'], $legacy['ai']['http'], $legacy['ai']['default_model'], $legacy['ai']['default_reasoning'], $legacy['ai']['favorite_models'], $legacy['compaction']['provider_overrides'], $legacy['compaction']['model_overrides']);
        $hydrate = fn (array $data): AppConfig => AppConfig::fromSettingsData($data, $container->get(DenormalizerInterface::class), $this->isolatedCwd());
        $current = $hydrate($defaults);
        $previous = $hydrate($legacy);
        $this->assertEquals($previous->agents, $current->agents);
        $this->assertEquals($previous->compaction, $current->compaction);
        $this->assertEquals($previous->ai, $current->ai);
    }

    /** @return iterable<string, array{SettingsLayerEnum, string, string}> */
    public static function extensionScopes(): iterable
    {
        foreach ([SettingsLayerEnum::User, SettingsLayerEnum::Project] as $scope) {
            yield $scope->value.' forks' => [$scope, 'forks', 'enabled'];
            yield $scope->value.' agents' => [$scope, 'agents', 'always_on'];
        }
    }

    #[DataProvider('extensionScopes')]
    public function testExtensionMultiselectShowsDefaultsAndPersistsOnlyOnConfirm(SettingsLayerEnum $scope, string $group, string $selection): void
    {
        $container = self::getContainer();
        $cwd = $this->isolatedCwd();
        $writer = $container->get(SettingsOverrideWriter::class);
        $path = $writer->layerPath($scope, $cwd);
        $this->assertSame((SettingsLayerEnum::User === $scope ? $this->isolatedHome() : $cwd).'/.hatfield', realpath(\dirname($path)));
        $this->assertFalse(is_link($path));
        $this->assertSame($cwd.'/.hatfield', realpath($cwd.'/.hatfield'));
        $this->assertFalse(is_link($writer->layerPath(SettingsLayerEnum::Project, $cwd)));
        $this->assertSame($this->isolatedHome().'/.hatfield', realpath($this->isolatedHome().'/.hatfield'));
        $this->assertFalse(is_link($writer->layerPath(SettingsLayerEnum::User, $cwd)));
        $writer->set(SettingsLayerEnum::User, $cwd, 'extensions.enabled', ['Example\\First', 'Example\\Second']);
        $writer->set(SettingsLayerEnum::Project, $cwd, 'extensions.project_extensions', ['Example\\ProjectOnly']);
        $key = $group.'.extensions.'.$selection;
        $writer->set($scope, $cwd, $key, ['Example\\First']);
        $before = file_get_contents($path);
        $terminal = new VirtualTerminal(columns: 160, rows: 35);
        $tui = new Tui(terminal: $terminal);
        $pane = new ContainerWidget();
        $tui->add($pane);
        $flow = ProvidersSetupFlow::for($container->get(AiCatalog::class), $writer, $container->get(AppConfig::class));
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $container->get(CoreSettingsScreen::class)->mount($tui, $pane, $scope, $flow, $tui->stop(...), static fn (string $text) => new TextWidget($text), $theme, static fn () => null);
        $tui->start();
        try {
            $this->select($tui, $group);
            $this->assertStringContainsString('Default:', $this->render($tui, $terminal));
            $this->field($tui, $key);
            if (SettingsLayerEnum::User === $scope) {
                $this->assertStringNotContainsString('Example\\ProjectOnly', $this->render($tui, $terminal));
            } else {
                $this->assertStringContainsString('Example\\ProjectOnly', $this->render($tui, $terminal));
            }
            $this->assertStringContainsString('Space toggles', $this->render($tui, $terminal));
            $this->assertStringContainsString('[x]', $this->render($tui, $terminal));
            $terminal->simulateInput(' ');
            $terminal->simulateInput("\x1b");
            $this->assertSame($before, file_get_contents($path));
            $this->field($tui, $key);
            $terminal->simulateInput("\x1b[B");
            $terminal->simulateInput(' ');
            $terminal->simulateInput("\r");
            $this->assertSame(['Example\\First', 'Example\\Second'], Yaml::parseFile($path)[$group]['extensions'][$selection]);
            $this->field($tui, $key);
            $terminal->simulateInput(' ');
            $terminal->simulateInput("\x1b[B");
            $terminal->simulateInput(' ');
            $terminal->simulateInput("\r");
            $this->assertSame([], Yaml::parseFile($path)[$group]['extensions'][$selection]);
        } finally {
            $tui->stop();
        }
    }

    public function testHiddenYamlParseFailureDoesNotEchoInputSnippet(): void
    {
        $container = self::getContainer();
        $writer = $container->get(SettingsOverrideWriter::class);
        $path = $writer->layerPath(SettingsLayerEnum::Project, $this->isolatedCwd());
        $this->assertSame($this->isolatedCwd().'/.hatfield', realpath(\dirname($path)));
        $this->assertFalse(is_link($path));
        $before = file_get_contents($path);
        $terminal = new VirtualTerminal(columns: 180, rows: 35);
        $tui = new Tui(terminal: $terminal);
        $pane = new ContainerWidget();
        $tui->add($pane);
        $flow = ProvidersSetupFlow::for($container->get(AiCatalog::class), $writer, $container->get(AppConfig::class));
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $container->get(CoreSettingsScreen::class)->mount($tui, $pane, SettingsLayerEnum::Project, $flow, $tui->stop(...), static fn (string $text) => new TextWidget($text), $theme, static fn () => null);
        $tui->start();
        try {
            $this->select($tui, 'compaction');
            $this->field($tui, 'compaction.provider_overrides');
            $terminal->simulateInput('{secret: PRIVATE-SENTINEL');
            $terminal->simulateInput("\r");
            $rendered = $this->render($tui, $terminal);
            $this->assertStringContainsString('Not saved:', $rendered);
            $this->assertStringContainsString('line 1', $rendered);
            $this->assertStringNotContainsString('PRIVATE-SENTINEL', $rendered);
            $this->assertSame($before, file_get_contents($path));
        } finally {
            $tui->stop();
        }
    }

    private function field(Tui $tui, string $id): void
    {
        $list = $tui->getFocus();
        $this->assertInstanceOf(SettingsListWidget::class, $list);
        $items = (new \ReflectionProperty(SettingsListWidget::class, 'items'))->getValue($list);
        $index = array_search($id, array_map(static fn (SettingItem $item): string => $item->getId(), $items), true);
        $this->assertIsInt($index);
        (new \ReflectionProperty(SettingsListWidget::class, 'selectedIndex'))->setValue($list, $index);
        $tui->handleInput("\r");
    }

    private function render(Tui $tui, VirtualTerminal $terminal): string
    {
        $terminal->clearOutput();
        $tui->requestRender(force: true);
        $tui->processRender();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }

    private function select(Tui $tui, string $value): void
    {
        $list = $tui->getFocus();
        $this->assertInstanceOf(SelectListWidget::class, $list);
        $index = array_search($value, array_column((new \ReflectionProperty(SelectListWidget::class, 'items'))->getValue($list), 'value'), true);
        $this->assertIsInt($index);
        $list->setSelectedIndex($index);
        $tui->handleInput("\r");
    }
}
