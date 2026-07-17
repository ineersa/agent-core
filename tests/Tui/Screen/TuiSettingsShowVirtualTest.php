<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
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
use Symfony\Component\Yaml\Yaml;

/**
 * Virtual proof for /settings-show Markdown routing and provenance rendering.
 *
 * Test thesis: production registrar + CommandParser/SubmissionRouter route
 * /settings-show, style markdown reaches MarkdownWidget, and filtered output
 * reports fresh project override provenance plus adjacent setting description.
 */
final class TuiSettingsShowVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $tmpDir = '';
    private string $homeDir = '';
    private string $cwd = '';

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
    }

    #[Test]
    public function testSettingsShowRoutesAndRendersMarkdownWithProjectProvenance(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('settings-show-virtual');
        $this->homeDir = $this->tmpDir.'/home';
        $this->cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::createHatfieldTree($this->cwd);

        $projectSettings = [
            'tui' => [
                'transcript' => [
                    'thinking' => [
                        'visible' => false,
                    ],
                ],
            ],
        ];
        file_put_contents(
            $this->cwd.'/.hatfield/settings.yaml',
            Yaml::dump($projectSettings, 6, 4),
        );

        $pathResolver = new SettingsPathResolver(ProjectDir::get(), $this->homeDir);
        $loader = new AppConfigLoader($pathResolver);
        $resources = new AppResourceLocator(ProjectDir::get());
        $valueResolver = new SettingsValueResolver(
            PropertyAccess::createPropertyAccessorBuilder()
                ->enableExceptionOnInvalidIndex()
                ->getPropertyAccessor(),
        );

        // Boot-time config omits the project override so restart note is discriminating.
        $bootRaw = $loader->load($resources->getDefaultsPath(), $this->cwd)->effective;
        $bootRaw['tui']['transcript']['thinking']['visible'] = true;
        $activeConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
            raw: $bootRaw,
            cwd: $this->cwd,
        );

        $handler = new SettingsShowCommandHandler($loader, $resources, $activeConfig, $valueResolver);
        $registry = new SlashCommandRegistry();
        $harness = new VirtualTuiHarness(sessionId: 'settings-show-virtual');
        $state = new TuiSessionState('settings-show-virtual');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new SettingsShowCommandRegistrar($registry, $handler))->register($context);
        $router = new SubmissionRouter(new CommandParser(), $registry);

        $groups = $router->route('/settings-show');
        $this->assertInstanceOf(TranscriptMessage::class, $groups);
        $this->assertSame('markdown', $groups->style);
        $this->assertStringContainsString('| Group | Description |', $groups->text);
        $this->assertStringContainsString('| tui |', $groups->text);
        $this->assertStringContainsString('Restart required: disk settings differ from the active session.', $groups->text);

        $filtered = $router->route('/settings-show tui.transcript.thinking.visible');
        $this->assertInstanceOf(TranscriptMessage::class, $filtered);
        $this->assertSame('markdown', $filtered->style);
        $this->assertStringContainsString('tui.transcript.thinking.visible', $filtered->text);
        $this->assertStringContainsString('| false |', $filtered->text);
        $this->assertStringContainsString('| project |', $filtered->text);
        $this->assertStringContainsString('Whether assistant thinking content is visible in the transcript.', $filtered->text);
        $this->assertStringContainsString('Restart required: disk settings differ from the active session.', $filtered->text);

        $factory = new TranscriptBlockFactory();
        $block = $factory->system(
            runId: 'settings-show-virtual',
            text: $filtered->text,
            seq: 1,
            style: $filtered->style,
        );
        $widgetFactory = new TranscriptBlockWidgetFactory();
        $widget = $widgetFactory->buildWidget($block, $harness->screen()->theme());
        $this->assertInstanceOf(\Symfony\Component\Tui\Widget\MarkdownWidget::class, $widget);

        $harness->screen()->setTranscriptBlocks([$block]);
        $screen = $harness->plainScreenText();
        $this->assertTrue(
            str_contains($screen, '│') || str_contains($screen, '|') || str_contains($screen, '┌'),
            'Virtual screen should surface Markdown table structure',
        );
        $this->assertStringContainsString('project', $screen);
        $this->assertStringContainsString('false', $screen);
    }
}
