<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Yaml\Yaml;

final class ExtensionsSetupScreenTest extends IsolatedKernelTestCase
{
    public function testDisablingParentClearsChildControlsAndSavesWithoutManualYamlEdits(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);
        $terminal = new VirtualTerminal(columns: 120, rows: 40);
        $tui = new Tui(terminal: $terminal);
        $screen = new ExtensionsSetupScreen($flow, new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette()), new NullLogger());
        $panel = new ContainerWidget();
        $notice = new TextWidget('');
        // Mount the existing private screen wiring without introducing a test-only production API.
        foreach (['tui' => $tui, 'panel' => $panel, 'notice' => $notice] as $property => $value) {
            (new \ReflectionProperty($screen, $property))->setValue($screen, $value);
        }
        $tui->add($notice)->add($panel);
        (new \ReflectionMethod($screen, 'menu'))->invoke($screen);
        $tui->start();
        try {
            $terminal->simulateInput("\r");
            $terminal->simulateInput("\r"); // Disable parent.
            $this->assertSame(['parent' => 'false', 'forks' => 'off', 'agents' => 'false'], $flow->members);
            $settings = $tui->getFocus();
            $this->assertInstanceOf(SettingsListWidget::class, $settings);
            $this->assertSame('off', $settings->getValue('forks'));
            $this->assertSame('false', $settings->getValue('agents'));

            $terminal->simulateInput("\r"); // Re-enabling must not silently restore child choices.
            $this->assertSame(['parent' => 'true', 'forks' => 'off', 'agents' => 'false'], $flow->members);
            $terminal->simulateInput("\r");
            for ($row = 0; $row < 4; ++$row) {
                $terminal->simulateInput("\x1b[B");
            }
            $terminal->simulateInput("\r"); // Open the masked allow_command_patterns field.
            $terminal->simulateInput("\r"); // Empty confirmation must not edit it.
            $this->assertSame('(configured)', $settings->getValue('allow_command_patterns'));
            $saved = Yaml::parseFile($this->isolatedCwd().'/.hatfield/settings.yaml');
            $this->assertSame([SchedulingExtension::class], $saved['extensions']['enabled']);
            $this->assertSame([SchedulingExtension::class], $saved['forks']['extensions']['always_on']);
            $this->assertSame([SchedulingExtension::class], $saved['agents']['extensions']['always_on']);
            $this->assertFalse($saved['extensions']['settings']['safe_guard']['auto_deny_in_noninteractive']);
            $this->assertSame(['fixture-command'], $saved['extensions']['settings']['safe_guard']['allow_command_patterns']);
            $this->assertSame('.hatfield/unchanged-log', $saved['logging']['path']);
            $this->assertSame('Saved to project scope. Changes load next session.', $notice->getText());

            $layers = self::getContainer()->get(AppConfigLoader::class)->load(
                self::getContainer()->get(AppResourceLocator::class)->getDefaultsPath(),
                $this->isolatedCwd(),
            );
            $this->assertNotContains(SafeGuardExtension::class, $layers->effective['extensions']['enabled']);
            $this->assertNotContains(SafeGuardExtension::class, $layers->effective['forks']['extensions']['always_on']);
            $this->assertNotContains(SafeGuardExtension::class, $layers->effective['agents']['extensions']['always_on']);
        } finally {
            $tui->stop();
        }
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        self::assertSame($classCwd, realpath($classCwd));
        self::assertSame($classCwd.'/.hatfield', realpath($classCwd.'/.hatfield'));
        file_put_contents($classCwd.'/.hatfield/settings.yaml', Yaml::dump([
            'extensions' => [
                'enabled' => [SafeGuardExtension::class, SchedulingExtension::class],
                'settings' => ['safe_guard' => ['auto_deny_in_noninteractive' => false, 'allow_command_patterns' => ['fixture-command']]],
            ],
            'forks' => ['extensions' => ['always_on' => [SafeGuardExtension::class, SchedulingExtension::class]]],
            'agents' => ['extensions' => ['always_on' => [SafeGuardExtension::class, SchedulingExtension::class]]],
            'logging' => ['path' => '.hatfield/unchanged-log'],
        ], 6));
    }
}
