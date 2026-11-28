<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Setup\DefinitionSetupService;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SetupSettingsService;
use Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\Tui\Setup\DefinitionSetupScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testBundledAgentCanEditBodyAndDelete(): void
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
        (new DefinitionSetupScreen($container->get(DefinitionSetupService::class), SettingsLayerEnum::User, new NullLogger(), $theme, skill: false))->mount($tui, $pane, $tui->stop(...));
        $tui->start();
        try {
            $terminal->simulateInput("\r"); // Open the only agent.
            $terminal->clearOutput();
            $tui->requestRender(force: true);
            $tui->processRender();
            $this->assertStringContainsString('Delete definition', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
            $terminal->simulateInput("\r"); // Edit.
            [$heading, $metadata, $warning, $status] = $pane->all();
            $this->assertInstanceOf(TextWidget::class, $heading);
            $this->assertInstanceOf(TextWidget::class, $metadata);
            $this->assertInstanceOf(TextWidget::class, $warning);
            $this->assertInstanceOf(TextWidget::class, $status);
            $this->assertSame($theme->color(ThemeColorEnum::Accent, 'scout'), $heading->getText());
            $this->assertSame($theme->muted('Source: '.$path.' · Owner: user · Built-in'), $metadata->getText());
            $expected = $theme->warning('Changes apply to the installed user copy, not the bundled resource.');
            $this->assertSame($expected, $warning->getText());
            $terminal->simulateInput("\x1b[B"); // Description.
            $terminal->simulateInput("\r");
            $terminal->simulateInput(' updated');
            $terminal->simulateInput("\r");
            $this->assertStringContainsString('Fixture updated', (string) file_get_contents($path));
            $this->assertSame($theme->success('Saved frontmatter to '.$path), $status->getText());
            $this->assertSame($expected, $warning->getText());
            $terminal->simulateInput("\x1b"); // Back to details.
            $terminal->simulateInput("\x1b[B"); // Edit body.
            $terminal->simulateInput("\r");
            $terminal->simulateInput("\x01Updated ");
            $terminal->simulateInput("\r");
            $this->assertStringContainsString("---\nUpdated Body\n", (string) file_get_contents($path));
            $terminal->simulateInput("\x1b[B");
            $terminal->simulateInput("\x1b[B"); // Delete.
            $terminal->simulateInput("\r\x1b[B\r"); // Confirm.
            $this->assertFileDoesNotExist($path);
        } finally {
            $tui->stop();
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function skillSelections(): iterable
    {
        yield 'list' => ['[missing]', ['missing']];
        yield 'comma-separated' => ['missing, absent', ['missing', 'absent']];
    }

    /** @param list<string> $selectedSkills */
    #[DataProvider('skillSelections')]
    public function testToolAndSkillPickersPreserveUnavailableEntries(string $skills, array $selectedSkills): void
    {
        $home = $this->isolatedHome();
        $this->assertSame($home.'/.hatfield', realpath($home.'/.hatfield'));
        $this->assertFalse(is_link($home.'/.hatfield/agents'));
        $this->assertFalse(is_link($home.'/.hatfield/skills'));
        $this->assertFalse(is_link($home.'/.hatfield/skills/example'));
        $agent = $home.'/.hatfield/agents/picker.md';
        $fs = new Filesystem();
        $fs->dumpFile($agent, "---\nname: picker\ndescription: Fixture\ntools: 'mcp:missing_*'\nskills: ".$skills."\n---\nBody\n");
        $fs->dumpFile($home.'/.hatfield/skills/example/SKILL.md', "---\nname: example\ndescription: Fixture\n---\nBody\n");
        $service = self::getContainer()->get(DefinitionSetupService::class);
        $terminal = new VirtualTerminal(columns: 130, rows: 35);
        $tui = new Tui(terminal: $terminal);
        $pane = new ContainerWidget();
        $tui->add($pane);
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        (new DefinitionSetupScreen($service, SettingsLayerEnum::User, new NullLogger(), $theme, skill: false))->mount($tui, $pane, $tui->stop(...));
        $tui->start();
        try {
            $terminal->simulateInput("\r\r");
            $tui->requestRender(force: true);
            $tui->processRender();
            $this->assertStringContainsString('Inherit parent / default', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
            $this->assertArrayNotHasKey('model', $service->fields($fs->readFile($agent)));
            $this->assertSame([], array_intersect(['subagent', 'fork', 'agent_resume', 'agent_steer'], $service->availableTools()));
            $terminal->simulateInput("\x1b[B\x1b[B\r \r");
            $this->assertSame('mcp:missing_*', $service->fields($fs->readFile($agent))['tools']);
            $this->assertInstanceOf(SelectListWidget::class, $tui->getFocus()->all()[0]);
            $terminal->simulateInput("\x1b");
            $terminal->simulateInput("\x1b[A\x1b[A");
            foreach ([2 => ['tools', ['mcp:missing_*'], 'mcp:*'], 5 => ['skills', $selectedSkills, 'example']] as $index => [$field, $selected, $known]) {
                $form = $tui->getFocus();
                $this->assertInstanceOf(SettingsListWidget::class, $form);
                $terminal->simulateInput(str_repeat("\x1b[B", $index)."\r");
                $picker = $form->all()[0];
                $this->assertInstanceOf(SelectListWidget::class, $picker);
                $this->assertSame($selected[0].' (current; unavailable)', $picker->getSelectedItem()['label'] ?? null);
                $this->assertSame($selected, array_column($picker->getSelectedItems(), 'value'));
                $before = $fs->readFile($agent);
                $terminal->simulateInput("\r"); // Unchanged confirmation preserves scalar spelling.
                $this->assertSame($before, $fs->readFile($agent));
                $terminal->simulateInput("\r".str_repeat("\x1b[B", \count($selected))." \x1b");
                $this->assertSame($before, $fs->readFile($agent)); // Cancel preserves selections.
                $terminal->simulateInput("\r".str_repeat("\x1b[B", \count($selected))." \r");
                $this->assertSame([...$selected, $known], $service->fields($fs->readFile($agent))[$field]);
            }
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
        $terminal = new VirtualTerminal(columns: 130, rows: 35);
        $tui = new Tui(terminal: $terminal);
        $pane = new ContainerWidget();
        $tui->add($pane);
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        (new DefinitionSetupScreen($service, SettingsLayerEnum::User, new NullLogger(), $theme, skill: false))->mount($tui, $pane, $tui->stop(...));
        $tui->start();
        try {
            $cwd = $this->isolatedCwd();
            $this->assertSame($cwd.'/.hatfield', realpath($cwd.'/.hatfield'));
            $this->assertFalse(is_link($cwd.'/.hatfield/settings.yaml'));
            $writer = $container->get(SettingsOverrideWriter::class);
            $writer->setMany(SettingsLayerEnum::Project, $cwd, [
                'ai.providers.fixture' => ['enabled' => true, 'models' => ['reasoner' => ['thinking_level_map' => ['high' => 'high']]]],
                'extensions.enabled' => [SchedulingExtension::class],
            ]);
            $models = $container->get(SetupSettingsService::class)->loadConfig()->catalog?->allModels() ?? [];
            $modelIndex = array_search('fixture/reasoner', array_map(static fn ($ref): string => $ref->toString(), $models), true);
            $this->assertIsInt($modelIndex);
            $terminal->simulateInput("\r");
            $terminal->simulateInput("\r");
            foreach ([3 => ['model', 'Model override for this child', 'fixture/reasoner'], 4 => ['thinking', 'Reasoning effort for the child model', 'high'], 6 => ['extensions', 'Optional child extensions', 'SchedulingExtension'], 8 => ['systemPromptMode', 'Replace uses the agent prompt', 'append']] as $index => [$field, $help, $label]) {
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
                $picker = $form->all()[0];
                $this->assertInstanceOf(SelectListWidget::class, $picker);
                if ('model' === $field) {
                    $picker->setSelectedIndex($modelIndex + 1);
                }
                $terminal->clearOutput();
                $tui->requestRender(force: true);
                $tui->processRender();
                $this->assertStringContainsString($label, AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                if ('extensions' === $field) {
                    $terminal->simulateInput(' ');
                    $this->assertArrayNotHasKey($field, $service->fields($fs->readFile($path)), 'Toggling does not save before Enter.');
                } elseif ('model' !== $field) {
                    $terminal->simulateInput("\x1b[B");
                    $terminal->simulateInput("\x1b[B");
                }
                if ('extensions' === $field) {
                    $writer->set(SettingsLayerEnum::Project, $cwd, 'extensions.enabled', []);
                    $terminal->simulateInput("\r");
                    $this->assertArrayNotHasKey($field, $service->fields($fs->readFile($path)), 'A selection removed while the picker was open must not be saved.');
                    $terminal->simulateInput("\r");
                    $empty = $form->all()[0];
                    $this->assertInstanceOf(SelectListWidget::class, $empty);
                    $this->assertSame('No optional extensions available', $empty->getSelectedItem()['label'] ?? null);
                    $terminal->simulateInput("\x1b");
                    $writer->set(SettingsLayerEnum::Project, $cwd, 'extensions.enabled', [SchedulingExtension::class]);
                    $terminal->simulateInput("\r");
                    $terminal->simulateInput(' ');
                }
                $terminal->simulateInput("\r");
                $this->assertSame('extensions' === $field ? [SchedulingExtension::class] : $label, $service->fields($fs->readFile($path))[$field]);
            }
        } finally {
            $tui->stop();
        }
    }
}
