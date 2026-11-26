<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Setup\DefinitionSetupService;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\Tui\Setup\DefinitionSetupScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class DefinitionSetupScreenTest extends PerMethodIsolatedKernelTestCase
{
    protected const bool ISOLATE_HOME = true;

    public function testBundledAgentWarningStaysStyledThroughSave(): void
    {
        $home = $this->isolatedHome();
        $this->assertSame($home.'/.hatfield', realpath($home.'/.hatfield'));
        $dir = $home.'/.hatfield/agents';
        $path = $dir.'/scout.md';
        $this->assertFileDoesNotExist($dir);
        $this->assertFalse(is_link($dir));
        (new Filesystem())->dumpFile($path, "---\nname: scout\ndescription: Fixture\n---\nBody\n");
        $container = self::getContainer();
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $terminal = new VirtualTerminal(columns: 120, rows: 30);
        $tui = new Tui(terminal: $terminal);
        $pane = new ContainerWidget();
        $tui->add($pane);
        (new DefinitionSetupScreen($container->get(DefinitionSetupService::class), SettingsLayerEnum::User, $container->get(AppConfig::class), new NullLogger(), $theme, skill: false))->mount($tui, $pane, $tui->stop(...));
        $tui->start();
        try {
            $terminal->simulateInput("\r"); // Open the only agent.
            $terminal->clearOutput();
            $tui->requestRender(force: true);
            $tui->processRender();
            $this->assertStringNotContainsString('Delete definition', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
            $terminal->simulateInput("\r"); // Edit.
            [$heading, $metadata, $warning, $status] = $pane->all();
            $this->assertInstanceOf(TextWidget::class, $heading);
            $this->assertInstanceOf(TextWidget::class, $metadata);
            $this->assertInstanceOf(TextWidget::class, $warning);
            $this->assertInstanceOf(TextWidget::class, $status);
            $this->assertSame($theme->color(ThemeColorEnum::Accent, 'scout'), $heading->getText());
            $this->assertSame($theme->muted('Source: '.$path.' · Owner: user · Built-in'), $metadata->getText());
            $expected = $theme->warning('Built-in: edits can be overwritten on refresh or rematerialization. Deletion is disabled.');
            $this->assertSame($expected, $warning->getText());
            $terminal->simulateInput("\x1b[B"); // Description.
            $terminal->simulateInput("\r");
            $terminal->simulateInput(' updated');
            $terminal->simulateInput("\r");
            $this->assertStringContainsString('Fixture updated', (string) file_get_contents($path));
            $this->assertSame($theme->success('Saved frontmatter to '.$path), $status->getText());
            $this->assertSame($expected, $warning->getText());
        } finally {
            $tui->stop();
        }
    }

    public function testAgentPickersUseNativeSingleAndMultipleSelection(): void
    {
        $home = $this->isolatedHome();
        $this->assertSame($home.'/.hatfield', realpath($home.'/.hatfield'));
        $dir = $home.'/.hatfield/agents';
        $this->assertFileDoesNotExist($dir);
        $this->assertFalse(is_link($dir));
        $path = $dir.'/picker.md';
        $fs = new Filesystem();
        $fs->dumpFile($path, "---\nname: picker\ndescription: Fixture\n---\nBody\n");
        $container = self::getContainer();
        $service = $container->get(DefinitionSetupService::class);
        $app = clone $container->get(AppConfig::class);
        $app->catalog = new HatfieldModelCatalog(AiConfig::fromArray(['providers' => ['fixture' => ['enabled' => true, 'models' => ['reasoner' => ['thinking_level_map' => ['high' => 'high']]]]]]));
        $app->extensions = new ExtensionsConfig([SafeGuardExtension::class]);
        $terminal = new VirtualTerminal(columns: 130, rows: 35);
        $tui = new Tui(terminal: $terminal);
        $pane = new ContainerWidget();
        $tui->add($pane);
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        (new DefinitionSetupScreen($service, SettingsLayerEnum::User, $app, new NullLogger(), $theme, skill: false))->mount($tui, $pane, $tui->stop(...));
        $tui->start();
        try {
            $terminal->simulateInput("\r");
            $terminal->simulateInput("\r");
            foreach ([3 => ['model', 'Model override for this child', 'fixture/reasoner'], 4 => ['thinking', 'Reasoning effort for the child model', 'high'], 6 => ['extensions', 'Optional child extensions', 'SafeGuardExtension'], 8 => ['systemPromptMode', 'Replace uses the agent prompt', 'append']] as $index => [$field, $help, $label]) {
                $form = $tui->getFocus();
                $this->assertInstanceOf(SettingsListWidget::class, $form);
                for ($i = 0; $i < $index; ++$i) {
                    $terminal->simulateInput("\x1b[B");
                }
                $terminal->clearOutput();
                $tui->requestRender(force: true);
                $tui->processRender();
                $this->assertStringContainsString($help, AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                $terminal->simulateInput("\r");
                $this->assertInstanceOf(SelectListWidget::class, $form->all()[0]);
                $terminal->clearOutput();
                $tui->requestRender(force: true);
                $tui->processRender();
                $this->assertStringContainsString($label, AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                if ('extensions' === $field) {
                    $terminal->simulateInput(' ');
                    $this->assertArrayNotHasKey($field, $service->fields($fs->readFile($path)), 'Toggling does not save before Enter.');
                } else {
                    $terminal->simulateInput("\x1b[B");
                    if ('model' !== $field) {
                        $terminal->simulateInput("\x1b[B");
                    }
                }
                $terminal->simulateInput("\r");
                $this->assertSame('extensions' === $field ? $app->extensions->enabled : $label, $service->fields($fs->readFile($path))[$field]);
            }
        } finally {
            $tui->stop();
        }
    }
}
