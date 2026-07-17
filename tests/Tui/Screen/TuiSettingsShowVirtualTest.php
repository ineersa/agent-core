<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SettingsValueResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\SettingsShowCommandHandler;
use Ineersa\Tui\Listener\SettingsShowCommandRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Yaml\Yaml;

/** Thesis: real router/registrar; markdown widget path; filtered project value/source/description/restart. */
final class TuiSettingsShowVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testSettingsShowRoutesAndRendersMarkdownWithProjectProvenance(): void
    {
        $tmp = TestDirectoryIsolation::createProjectTempDir('settings-show-virtual');
        try {
            $home = $tmp.'/home';
            $cwd = $tmp.'/project';
            TestDirectoryIsolation::ensureDirectory($home.'/.hatfield');
            TestDirectoryIsolation::createHatfieldTree($cwd);
            file_put_contents($cwd.'/.hatfield/settings.yaml', Yaml::dump([
                'tui' => ['transcript' => ['thinking' => ['visible' => false]]],
            ], 6, 4));

            $resources = new AppResourceLocator(ProjectDir::get());
            $loader = new AppConfigLoader(new SettingsPathResolver(ProjectDir::get(), $home));
            $bootRaw = $loader->load($resources->getDefaultsPath(), $cwd)->effective;
            $bootRaw['tui']['transcript']['thinking']['visible'] = true;
            $handler = new SettingsShowCommandHandler(
                $loader,
                $resources,
                new AppConfig(tui: new TuiConfig(theme: 'cyberpunk'), logging: new LoggingConfig(), raw: $bootRaw, cwd: $cwd),
                new SettingsValueResolver(PropertyAccess::createPropertyAccessorBuilder()->enableExceptionOnInvalidIndex()->getPropertyAccessor()),
            );
            $registry = new SlashCommandRegistry();
            $harness = new VirtualTuiHarness(sessionId: 'settings-show-virtual');
            (new SettingsShowCommandRegistrar($registry, $handler))->register(
                $this->buildTuiContext()->withTui($harness->tui())->withState(new TuiSessionState('settings-show-virtual'))->withScreen($harness->screen())->build(),
            );
            $router = new SubmissionRouter(new CommandParser(), $registry);

            $groups = $router->route('/settings-show');
            $this->assertInstanceOf(TranscriptMessage::class, $groups);
            $this->assertSame('markdown', $groups->style);
            $this->assertStringContainsString('| Group | Description |', $groups->text);

            $groupPath = $router->route('/settings-show compaction');
            $this->assertStringContainsString(
                "Compaction replaces older conversation history with a concise handoff summary while keeping the most recent messages raw. Manual /compact is always available. Auto-compaction triggers when the estimated context exceeds compact_after_tokens. Per-provider and per-model overrides win over global settings. Provider overrides use provider IDs (e.g. openai, llama_cpp). Model overrides use provider/model keys (e.g. openai/gpt-4.1).\n\n| Setting |",
                $groupPath->text,
            );

            $filtered = $router->route('/settings-show tui.transcript.thinking.visible');
            $this->assertStringContainsString('| false |', $filtered->text);
            $this->assertStringContainsString('| project |', $filtered->text);
            $this->assertStringContainsString('Whether assistant thinking content is visible in the transcript.', $filtered->text);
            $this->assertStringContainsString('Restart required: disk settings differ from the active session.', $filtered->text);

            $block = (new TranscriptBlockFactory())->system('settings-show-virtual', $filtered->text, 1, $filtered->style);
            $this->assertInstanceOf(MarkdownWidget::class, (new TranscriptBlockWidgetFactory())->buildWidget($block, $harness->screen()->theme()));
            $harness->screen()->setTranscriptBlocks([$block]);
            $screen = $harness->plainScreenText();
            $this->assertTrue(str_contains($screen, '│') || str_contains($screen, '|') || str_contains($screen, '┌'));
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }
}
