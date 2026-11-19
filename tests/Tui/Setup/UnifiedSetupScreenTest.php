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
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TabsWidget;
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
        $provider = ProvidersSetupFlow::for($container->get(AiCatalog::class), $container->get(SettingsOverrideWriter::class), $container->get(AppConfig::class), true);
        $extension = $container->get(ExtensionsSetupFlow::class);
        $extension->layer = SettingsLayerEnum::Project;
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $screen = new UnifiedSetupScreen($container->get(CoreSettingsScreen::class), new SetupScreen($provider), new ExtensionsSetupScreen($extension, $theme, new NullLogger()), $theme);
        $terminal = new VirtualTerminal(columns: 75, rows: 26);
        $tui = new Tui(terminal: $terminal);
        $screen->mount($tui, SettingsLayerEnum::Project, $path, $provider, 1);
        $tui->start();
        try {
            $tabs = $tui->getFocus();
            $this->assertInstanceOf(TabsWidget::class, $tabs);
            $this->assertSame('providers', $tabs->getActiveTabId());
            $tui->requestRender(force: true);
            $tui->processRender();
            $this->assertStringContainsString('AI Provider Setup', $terminal->getOutput());
            $terminal->simulateInput("\x1b[C");
            $this->assertSame('extensions', $tabs->getActiveTabId());
            $terminal->simulateInput("\r");
            $this->assertNotSame($tabs, $tui->getFocus());
            $terminal->simulateInput("\x1b");
            $this->assertSame($tabs, $tui->getFocus());
            $terminal->simulateInput("\x1b[C");
            $this->assertSame('settings', $tabs->getActiveTabId());
            $terminal->simulateInput("\r");
            $this->select($tui, 'forks');
            $this->select($tui, 'forks.enabled');
            $this->select($tui, 'false');
            $this->select($tui, 'Discard');
            $this->assertSame($original, file_get_contents($path));
            $this->select($tui, 'false');
            $this->select($tui, 'Save');
            $this->assertFalse(Yaml::parseFile($path)['forks']['enabled']);
            $this->select($tui, 'Back');
            $this->select($tui, ':reset');
            $this->select($tui, 'Save');
            $this->assertArrayNotHasKey('forks', Yaml::parseFile($path));
            $this->select($tui, 'Back');
            $terminal->simulateInput("\x1b"); // Rebuild the main Settings menu.
            $terminal->simulateInput("\x1b"); // Return focus to the tab header.
            $this->assertSame($tabs, $tui->getFocus());
            $terminal->simulateInput("\r"); // Re-enter without changing tabs.
            $this->select($tui, 'forks');
        } finally {
            $tui->stop();
        }
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
