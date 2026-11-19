<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowConfiguration;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SettingItem;
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

    public function testMissingHookIsCreatedThroughIndividualFieldEditors(): void
    {
        $flow = clone self::getContainer()->get(ExtensionsSetupFlow::class);
        $cfg = new TaskWorkflowConfiguration();
        foreach (['configuration' => $cfg, 'class' => 'fixture-task-workflow', 'key' => 'task_workflow'] as $property => $value) {
            (new \ReflectionProperty($flow, $property))->setValue($flow, $value);
        }
        $flow->members = ['parent' => 'false', 'forks' => 'off', 'agents' => 'false'];
        (new \ReflectionMethod($flow, 'collectNodes'))->invoke($flow, $cfg->getConfigTreeBuilder()->buildTree());
        $path = $this->isolatedCwd().'/.hatfield/settings.yaml';
        $this->assertSame($this->isolatedCwd().'/.hatfield', realpath(\dirname($path)));
        $this->assertFalse(is_link($path));
        $terminal = new VirtualTerminal(columns: 120, rows: 40);
        $tui = new Tui(terminal: $terminal);
        $screen = new ExtensionsSetupScreen($flow, new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette()), new NullLogger());
        $pane = new ContainerWidget();
        $tui->add($pane);
        $screen->mount($tui, $pane, $tui->stop(...));
        $show = new \ReflectionMethod($screen, 'showForm');
        $show->invoke($screen, 'fixture-task-workflow');
        $tui->start();
        try {
            $before = file_get_contents($path);
            $this->editField($tui, $terminal, 'hooks.cleanup.script', 'abandoned.sh');
            $terminal->simulateInput("\x1b");
            $this->assertSame($before, file_get_contents($path));
            $show->invoke($screen, 'fixture-task-workflow');
            foreach (['setup' => ['script', 'timeout_seconds'], 'validate' => ['timeout_seconds', 'script']] as $hook => $order) {
                $before = file_get_contents($path);
                $values = ['script' => $hook.'.sh', 'timeout_seconds' => '12'];
                foreach ($order as $index => $field) {
                    $this->editField($tui, $terminal, 'hooks.'.$hook.'.'.$field, $values[$field]);
                    if (0 === $index) {
                        $this->assertSame($before, file_get_contents($path));
                    }
                }
                $hooks = Yaml::parseFile($path)['extensions']['settings']['task_workflow']['hooks'];
                $this->assertSame($hook.'.sh', $hooks[$hook]['script']);
                $this->assertSame(12, $hooks[$hook]['timeout_seconds']);
                $this->assertArrayNotHasKey('cleanup', $hooks);
                $this->editField($tui, $terminal, 'hooks.'.$hook.'.timeout_seconds', '15');
                $saved = Yaml::parseFile($path)['extensions']['settings']['task_workflow']['hooks'][$hook];
                $this->assertSame(15, $saved['timeout_seconds']);
                $this->assertSame($hook.'.sh', $saved['script']);
            }
            $writer = self::getContainer()->get(SettingsOverrideWriter::class);
            $cwd = $this->isolatedCwd();
            $user = $writer->layerPath(SettingsLayerEnum::User, $cwd);
            $home = getenv('HATFIELD_QA_TEST_HOME');
            $this->assertIsString($home);
            $this->assertNotSame('', $home);
            $this->assertSame($home.'/.hatfield', realpath(\dirname($user)));
            $this->assertFalse(is_link($user));
            $key = 'extensions.settings.task_workflow.hooks.setup';
            $inherited = ['script' => 'inherited.sh', 'timeout_seconds' => 21];
            $writer->set(SettingsLayerEnum::User, $cwd, $key, $inherited);
            try {
                $userBefore = file_get_contents($user);
                $this->activateField($tui, $terminal, 'clear:hooks.setup');
                $hooks = Yaml::parseFile($path)['extensions']['settings']['task_workflow']['hooks'];
                $this->assertArrayNotHasKey('setup', $hooks);
                $this->assertSame('validate.sh', $hooks['validate']['script']);
                $this->assertSame(15, $hooks['validate']['timeout_seconds']);
                $this->assertSame($inherited, $flow->settings['hooks']['setup']);
                $this->assertSame($userBefore, file_get_contents($user));
            } finally {
                $writer->remove(SettingsLayerEnum::User, $cwd, $key);
            }
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

    private function editField(Tui $tui, VirtualTerminal $terminal, string $id, string $value): void
    {
        $this->activateField($tui, $terminal, $id);
        $terminal->simulateInput("\x7f");
        $terminal->simulateInput("\x7f");
        $terminal->simulateInput($value);
        $terminal->simulateInput("\r");
    }

    private function activateField(Tui $tui, VirtualTerminal $terminal, string $id): void
    {
        $settings = $tui->getFocus();
        $this->assertInstanceOf(SettingsListWidget::class, $settings);
        $items = (new \ReflectionProperty(SettingsListWidget::class, 'items'))->getValue($settings);
        $index = array_search($id, array_map(static fn (SettingItem $item): string => $item->getId(), $items), true);
        $this->assertIsInt($index);
        (new \ReflectionProperty(SettingsListWidget::class, 'selectedIndex'))->setValue($settings, $index);
        $terminal->simulateInput("\r");
    }
}
