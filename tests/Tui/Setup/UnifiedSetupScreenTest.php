<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlow;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SetupSettingsService;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Setup\CoreSettingsScreen;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Ineersa\Tui\Setup\SetupScreen;
use Ineersa\Tui\Setup\UnifiedSetupScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

final class UnifiedSetupScreenTest extends IsolatedKernelTestCase
{
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
        $originalUser = is_file($userPath) ? file_get_contents($userPath) : null;
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        foreach ([90, 48] as $width) {
            $terminal = new VirtualTerminal(columns: $width, rows: 30);
            $tui = new Tui(terminal: $terminal);
            (new UnifiedSetupScreen($theme))->mount($tui, SettingsLayerEnum::User, static function (SettingsLayerEnum $scope) use ($container, $writer, $cwd, $theme): array {
                $provider = ProvidersSetupFlow::for($container->get(AiCatalog::class), $writer, $container->get(AppConfig::class), SettingsLayerEnum::Project === $scope);
                $extension = clone $container->get(ExtensionsSetupFlow::class);
                $extension->layer = $scope;

                return [$writer->layerPath($scope, $cwd), $container->get(CoreSettingsScreen::class), $provider, new SetupScreen($provider), new ExtensionsSetupScreen($extension, $theme, new NullLogger())];
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
                $this->assertStringNotContainsString('→ Back', $extensions);
                $terminal->simulateInput("\t");
                $this->select($tui, 'forks');
                $this->assertStringContainsString('enabled', strtolower($this->render($tui, $terminal)));
                $this->field($tui, 'forks.enabled');
                $widget = $tui->getFocus();
                $this->assertInstanceOf(SettingsListWidget::class, $widget);
                $this->assertSame('Off', $widget->getValue('forks.enabled'));
                $this->field($tui, 'forks.enabled');
                $this->assertSame('On', $widget->getValue('forks.enabled'));
                $this->field($tui, 'forks.thinking_level');
                $terminal->simulateInput("\x1b[B");
                $terminal->simulateInput("\r");
                $this->assertSame('off', $widget->getValue('forks.thinking_level'));
                $this->field($tui, 'forks.thinking_level');
                $terminal->simulateInput("\r");
                $this->assertSame('off', $widget->getValue('forks.thinking_level'));
                $terminal->simulateInput("\x1bOQ");
                $this->assertStringContainsString('Save or discard open forms', $this->render($tui, $terminal));
                $terminal->simulateInput("\x1b[Z");
                $this->assertStringContainsString('Manage extensions', $this->render($tui, $terminal));
                $terminal->simulateInput("\t");
                $this->field($tui, ':discard');
                $this->assertSame($original, file_get_contents($path));
                $terminal->simulateInput("\x1bOQ");
                $this->assertStringContainsString('Project scope', $this->render($tui, $terminal));
                $this->select($tui, 'sessions');
                $this->assertStringContainsString('Directory that stores', $this->render($tui, $terminal));
                $this->field($tui, 'sessions.path');
                $terminal->simulateInput('/test');
                $terminal->simulateInput("\r");
                $this->field($tui, 'sessions.path');
                $terminal->simulateInput('/again');
                $terminal->simulateInput("\r");
                $this->field($tui, ':save');
                $this->assertStringEndsWith('/test/again', Yaml::parseFile($path)['sessions']['path']);
                $this->assertSame($originalUser, is_file($userPath) ? file_get_contents($userPath) : null);
                $this->field($tui, ':reset');
                $terminal->simulateInput("\r");
                $this->field($tui, ':save');
                $this->assertArrayNotHasKey('sessions', Yaml::parseFile($path));
                $terminal->simulateInput("\x1b");
                $this->select($tui, 'prompts');
                $this->field($tui, 'prompts');
                $terminal->simulateInput('[false]');
                $terminal->simulateInput("\r");
                $before = file_get_contents($path);
                $this->field($tui, ':save');
                $this->assertSame($before, file_get_contents($path));
                $this->assertStringContainsString('Not saved', $this->render($tui, $terminal));
                $this->field($tui, 'prompts');
                for ($character = 0; $character < \strlen('[false]'); ++$character) {
                    $terminal->simulateInput("\x7f");
                }
                $terminal->simulateInput('[team/prompts]');
                $terminal->simulateInput("\r");
                $this->field($tui, ':save');
                $this->assertSame(['team/prompts'], Yaml::parseFile($path)['prompts']);
                $this->field($tui, ':reset');
                $terminal->simulateInput("\r");
                $this->field($tui, ':save');
                $terminal->simulateInput("\x1b");
                $this->select($tui, 'ai');
                $this->field($tui, 'ai.providers');
                $terminal->simulateInput('{dummy: {api_key: redact-this-fixture}}');
                $this->assertStringNotContainsString('redact-this-fixture', $this->render($tui, $terminal));
                $terminal->simulateInput("\r");
                $this->assertStringNotContainsString('redact-this-fixture', $this->render($tui, $terminal));
                $this->field($tui, ':discard');
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $this->assertFalse($tui->isRunning());
            } finally {
                $tui->stop();
            }
            $original = file_get_contents($path);
        }
    }

    public function testInventoryCoversSettingsNotPersonallyConfigured(): void
    {
        $inventory = self::getContainer()->get(SetupSettingsService::class)->inventory();
        foreach (['sessions.path', 'agents.subagent_tool_timeout_seconds', 'ai.http.max_retries', 'ai.providers', 'compaction.model_overrides', 'extensions.settings', 'forks.extensions.always_on', 'prompts'] as $key) {
            $this->assertArrayHasKey($key, $inventory[explode('.', $key)[0]]);
        }
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
        $hydrate = fn (array $data): AppConfig => AppConfig::fromSettingsData($data, $container->get(DenormalizerInterface::class), $this->isolatedCwd(), $container->get(ValidatorInterface::class));
        $current = $hydrate($defaults);
        $previous = $hydrate($legacy);
        $this->assertEquals($previous->agents, $current->agents);
        $this->assertEquals($previous->compaction, $current->compaction);
        $this->assertEquals($previous->ai, $current->ai);
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
        $this->assertInstanceOf(\Symfony\Component\Tui\Widget\SelectListWidget::class, $list);
        $index = array_search($value, array_column((new \ReflectionProperty(\Symfony\Component\Tui\Widget\SelectListWidget::class, 'items'))->getValue($list), 'value'), true);
        $this->assertIsInt($index);
        $list->setSelectedIndex($index);
        $tui->handleInput("\r");
    }
}
