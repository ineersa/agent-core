<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlow;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Setup\CoreSettingsScreen;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Ineersa\Tui\Setup\SetupScreen;
use Ineersa\Tui\Setup\UnifiedSetupScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
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
                $this->select($tui, 'Delegation');
                $preferences = $this->render($tui, $terminal);
                $this->assertStringContainsString('Allow forks · On', $preferences);
                $this->assertStringContainsString('Let the assistant branch', $preferences);
                $this->assertStringNotContainsString('forks.enabled', $preferences);
                $this->select($tui, 'forks.enabled');
                $this->select($tui, 'false');
                $terminal->simulateInput("\x1bOQ");
                $this->assertStringContainsString('Save or discard open forms', $this->render($tui, $terminal));
                $terminal->simulateInput("\x1b[Z");
                $this->assertStringContainsString('Manage extensions', $this->render($tui, $terminal));
                $terminal->simulateInput("\t");
                $this->select($tui, 'Discard');
                $this->assertSame($original, file_get_contents($path));
                $terminal->simulateInput("\x1bOQ");
                $this->assertStringContainsString('Project scope', $this->render($tui, $terminal));
                $this->select($tui, 'Delegation');
                $this->select($tui, 'forks.enabled');
                $this->select($tui, 'false');
                $this->select($tui, 'Save');
                $this->assertFalse(Yaml::parseFile($path)['forks']['enabled']);
                $this->assertSame($originalUser, is_file($userPath) ? file_get_contents($userPath) : null);
                $this->select($tui, 'Continue');
                $this->select($tui, ':reset');
                $this->select($tui, 'Save');
                $this->assertArrayNotHasKey('forks', Yaml::parseFile($path));
                $this->select($tui, 'Continue');
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $this->select($tui, 'Compaction');
                $this->select($tui, 'compaction.model');
                $this->select($tui, ':session');
                $this->select($tui, 'Save');
                $saved = Yaml::parseFile($path);
                $this->assertArrayHasKey('model', $saved['compaction']);
                $this->assertNull($saved['compaction']['model']);
                $this->select($tui, 'Continue');
                $this->select($tui, ':reset');
                $this->select($tui, 'Save');
                $this->select($tui, 'Continue');
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $this->assertFalse($tui->isRunning());
            } finally {
                $tui->stop();
            }
            $original = file_get_contents($path);
        }
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
