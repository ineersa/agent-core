<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\SkillReadProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Virtual proof: exact discovered skill reads render as compact skill cards.
 *
 * Test thesis: real ToolProjectionSubscriber annotation + TranscriptProjector
 * + ChatScreen/TranscriptBlockWidgetFactory path yields a collapsed
 * `[skill] <frontmatter-name>:1-400` card (result hidden, Ctrl+O expands),
 * while an ordinary/unrelated SKILL.md read stays a normal read card.
 */
final class TuiSkillReadCardVirtualRenderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('skill-read-card');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function testDiscoveredSkillReadRendersCompactSkillCardAndOrdinaryReadStaysRead(): void
    {
        $skillDir = $this->tmpDir.'/.agents/skills/testing';
        mkdir($skillDir, 0777, true);
        $skillFile = $skillDir.'/SKILL.md';
        $skillBody = "---\nname: testing\ndescription: Testing skill\n---\n\n# Hidden skill body\n\nskill-secret-line\n";
        file_put_contents($skillFile, $skillBody);

        $unrelatedDir = $this->tmpDir.'/docs/unrelated';
        mkdir($unrelatedDir, 0777, true);
        $unrelatedFile = $unrelatedDir.'/SKILL.md';
        file_put_contents($unrelatedFile, "---\nname: unrelated\ndescription: Not registered\n---\n\nunrelated-secret-line\n");

        $discovery = $this->createDiscovery($this->tmpDir);
        $this->assertNotNull($discovery->findBySkillFilePath($skillFile));
        $this->assertNull($discovery->findBySkillFilePath($unrelatedFile));

        $projector = $this->createProjector($discovery);

        $skillCallId = 'call-skill-1';
        $ordinaryCallId = 'call-ordinary-1';
        $skillResult = "1|---\n2|name: testing\n...\nskill-secret-line";
        $ordinaryResult = "1|---\n2|name: unrelated\n...\nunrelated-secret-line";

        $this->projectCompletedRead(
            $projector,
            $skillCallId,
            $skillFile,
            $skillResult,
            offset: 1,
            limit: 400,
        );
        $this->projectCompletedRead(
            $projector,
            $ordinaryCallId,
            $unrelatedFile,
            $ordinaryResult,
        );

        $blocks = $projector->blocks();
        $skillCall = null;
        $ordinaryCall = null;
        foreach ($blocks as $block) {
            if (($block->meta['tool_call_id'] ?? null) === $skillCallId && 'tool_call_' === substr($block->id, 0, 10)) {
                $skillCall = $block;
            }
            if (($block->meta['tool_call_id'] ?? null) === $ordinaryCallId && 'tool_call_' === substr($block->id, 0, 10)) {
                $ordinaryCall = $block;
            }
        }

        $this->assertNotNull($skillCall);
        $this->assertSame('testing', $skillCall->meta['skill_name'] ?? null);
        $this->assertNotNull($ordinaryCall);
        $this->assertArrayNotHasKey('skill_name', $ordinaryCall->meta);

        // Distinct Skill / MarkdownHeading / ToolTitle palette values make the selected role observable.
        $palette = new ThemePalette('skill-read-card-theme', [
            ThemeColorEnum::Skill->value => 'bright_magenta',
            ThemeColorEnum::MarkdownHeading->value => 'bright_yellow',
            ThemeColorEnum::ToolTitle->value => 'bright_cyan',
            ThemeColorEnum::Dim->value => 'bright_black',
        ]);

        $displayState = new TranscriptDisplayState(previewableBlocksExpanded: false);
        $harness = new VirtualTuiHarness(
            sessionId: 'skill-read-card-session',
            palette: $palette,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 2),
            displayState: $displayState,
        );
        $harness->screen()->setTranscriptBlocks($blocks);
        $harness->screen()->setWorkingVisible(false);

        $collapsed = $harness->plainScreenText();
        $this->assertStringContainsString('Ctrl+O to expand', $collapsed);
        $this->assertStringNotContainsString('skill-secret-line', $collapsed);
        $this->assertStringNotContainsString('path: '.$skillFile, $collapsed);

        $skillLine = $this->skillHeaderPlainLine($collapsed);
        $this->assertStringStartsWith('  [skill] testing:1-400', $skillLine);
        $this->assertStringNotContainsString(TranscriptGlyphs::GLYPH_TOOL, $skillLine);

        $collapsedAnsi = $harness->ansiOutput();
        $this->assertMatchesRegularExpression(
            "/\x1b\[95m  \[skill\] testing:1-400\x1b\[39m/",
            $collapsedAnsi,
            'Collapsed skill header must use Skill (bright_magenta / 95)',
        );

        $this->assertStringContainsString('read', $collapsed);
        // Path may wrap across terminal columns in VirtualTerminal output
        // (long worktree paths can split tokens like SKILL.md mid-name).
        $collapsedUnwrapped = str_replace("\n", '', $collapsed);
        $this->assertStringContainsString('docs/unrelated', $collapsedUnwrapped);
        $this->assertStringContainsString('SKILL.md', $collapsedUnwrapped);
        $this->assertStringContainsString('path:', $collapsed);
        $this->assertStringNotContainsString('[skill] unrelated', $collapsed);

        $displayState->previewableBlocksExpanded = true;
        $harness->screen()->setTranscriptBlocks($blocks);
        $expanded = $harness->plainScreenText();
        $expandedSkillLine = $this->skillHeaderPlainLine($expanded);
        $this->assertStringStartsWith('  [skill] testing:1-400', $expandedSkillLine);
        $this->assertStringNotContainsString(TranscriptGlyphs::GLYPH_TOOL, $expandedSkillLine);
        $this->assertStringContainsString('skill-secret-line', $expanded);
        $this->assertStringNotContainsString('Ctrl+O to expand', $expanded);

        $expandedAnsi = $harness->ansiOutput();
        $this->assertMatchesRegularExpression(
            "/\x1b\[95m  \[skill\] testing:1-400\x1b\[39m/",
            $expandedAnsi,
            'Expanded skill header must keep Skill palette role',
        );
    }

    private function skillHeaderPlainLine(string $plainScreenText): string
    {
        foreach (explode("\n", $plainScreenText) as $line) {
            if (str_contains($line, '[skill]')) {
                return $line;
            }
        }

        $this->fail('Expected a rendered [skill] line');
    }

    private function createDiscovery(string $cwd): SkillDiscovery
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir, 0777, true);

        return new SkillDiscovery(
            config: new SkillsConfig(),
            pathResolver: new SettingsPathResolver($cwd, $homeDir),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $cwd,
            ),
            extractor: new MarkdownFrontmatterExtractor(),
            resources: new AppResourceLocator($this->tmpDir),
            filesystem: new Filesystem(),
        );
    }

    private function createProjector(SkillDiscovery $discovery): TranscriptProjector
    {
        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(new SubagentProgressDisplayFormatter(), SubagentProgressSerializerTestSupport::denormalizer()));
        $dispatcher->addSubscriber(new SkillReadProjectionSubscriber($discovery));

        return new TranscriptProjector($dispatcher, $state);
    }

    private function projectCompletedRead(
        TranscriptProjector $projector,
        string $toolCallId,
        string $path,
        string $result,
        ?int $offset = null,
        ?int $limit = null,
    ): void {
        static $seq = 0;
        // Tool-call arguments are the flat provider map (read DTO fields at
        // the top level).
        $fields = ['path' => $path];
        if (null !== $offset) {
            $fields['offset'] = $offset;
        }
        if (null !== $limit) {
            $fields['limit'] = $limit;
        }
        $arguments = $fields;

        ++$seq;
        $projector->accept(new RuntimeEvent(
            type: 'tool_call.started',
            runId: 'skill-read-run',
            seq: $seq,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'read',
            ],
        ));
        ++$seq;
        $projector->accept(new RuntimeEvent(
            type: 'tool_call.arguments_completed',
            runId: 'skill-read-run',
            seq: $seq,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'read',
                'arguments' => $arguments,
            ],
        ));
        ++$seq;
        $projector->accept(new RuntimeEvent(
            type: 'tool_execution.started',
            runId: 'skill-read-run',
            seq: $seq,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'read',
            ],
        ));
        ++$seq;
        $projector->accept(new RuntimeEvent(
            type: 'tool_execution.completed',
            runId: 'skill-read-run',
            seq: $seq,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'read',
                'result' => $result,
                'duration_ms' => 12,
            ],
        ));
    }
}
