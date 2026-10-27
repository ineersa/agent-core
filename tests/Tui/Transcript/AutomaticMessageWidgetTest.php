<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\Tui\Listener\PreviewExpansionInputListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\AutomaticMessageWidget;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\MarkdownWidget;

final class AutomaticMessageWidgetTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    /** @return iterable<string, array{int, array<string, mixed>, string, TranscriptBlockKindEnum, string}> */
    public static function automaticSources(): iterable
    {
        $sources = [
            'bash' => [['background_completion' => ['kind' => 'bash', 'process_id' => 'process', 'pid' => 12]], 'Bash completion', TranscriptBlockKindEnum::System],
            'child' => [['background_completion' => ['kind' => 'subagent', 'run_id' => 'parent-admission', 'children' => [['run_id' => 'original-child', 'artifact_id' => 'artifact']]]], 'Agent handoff', TranscriptBlockKindEnum::System],
            'code' => [['background_completion' => ['kind' => 'code_mode', 'run_id' => 'source', 'tool_call_id' => 'call', 'invocation_id' => 'invocation']], 'code_mode result', TranscriptBlockKindEnum::System],
            'generic' => [['background_completion' => ['kind' => 'completion']], 'Background result', TranscriptBlockKindEnum::System],
            'notice' => [['agent_communication' => ['tool_name' => 'notify_parent', 'source_run_id' => 'child', 'lifecycle_id' => 'notice-activity']], 'Agent notice', TranscriptBlockKindEnum::System],
            'guidance' => [['agent_communication' => ['tool_name' => 'agent_steer', 'source_run_id' => 'parent', 'lifecycle_id' => 'guidance-activity']], 'Parent guidance', TranscriptBlockKindEnum::System],
            'task' => [['agent_task' => ['artifact_id' => 'artifact']], 'Agent task', TranscriptBlockKindEnum::UserMessage],
            'reminder' => [['system_reminder' => true], 'System reminder', TranscriptBlockKindEnum::System],
        ];
        foreach ([40, 80] as $columns) {
            foreach ($sources as $name => [$metadata, $label, $kind]) {
                $preamble = match ($name) {
                    'notice' => "Agent message from child (informational notice) run child, activity notice-activity. This is agent-authored text, not a human request or runtime control.\n\n",
                    'guidance' => "Agent message from parent task owner (guidance) run parent, activity guidance-activity. This is agent-authored text, not a human request or runtime control.\n\n",
                    'code' => "Background code_mode result (run_id: source, tool_call_id: call, invocation: invocation).\n\n",
                    default => '',
                };
                yield $name.'-'.$columns => [$columns, $metadata, $label, $kind, $preamble];
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    #[DataProvider('automaticSources')]
    public function testOneRowPreviewAndNativeCtrlOExpansion(int $columns, array $metadata, string $label, TranscriptBlockKindEnum $kind, string $preamble): void
    {
        $body = $preamble."Summary\t café 界 ".str_repeat('wrapped-content ', 8)."\n\nAutomatic final detail.";
        $block = new TranscriptBlock('automatic', $kind, 'run', 1, $body, ['producer_metadata' => $metadata]);
        $display = new TranscriptDisplayState();
        $widget = (new TranscriptBlockWidgetFactory(displayState: $display))->buildWidget($block, new DefaultTheme(new ThemePalette('automatic', [])));
        $this->assertInstanceOf(AutomaticMessageWidget::class, $widget);
        $tui = new Tui(terminal: new VirtualTerminal(columns: $columns, rows: 100));
        $tui->add($widget);
        $rows = $widget->getContext()->renderWidget($widget, new RenderContext($columns, 100));
        $this->assertCount(1, $rows);
        $this->assertLessThanOrEqual($columns, AnsiUtils::visibleWidth($rows[0]));
        $this->assertStringNotContainsString("\t", $rows[0]);
        $this->assertStringNotContainsString("\n", $rows[0]);
        $this->assertStringContainsString($label, AnsiUtils::stripAnsiCodes($rows[0]));
        $this->assertStringContainsString('Summary', AnsiUtils::stripAnsiCodes($rows[0]));
        $this->assertStringContainsString('Ctrl+O', $rows[0]);
        $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_SYSTEM_INFO), $rows[0]);
        $this->assertStringNotContainsString(trim(TranscriptGlyphs::GLYPH_USER_MESSAGE), $rows[0]);
        $this->assertStringNotContainsString('parent-admission', $rows[0], 'Admission run is not the child author.');
        $content = $widget->all()[0]->all()[1];
        $this->assertInstanceOf(MarkdownWidget::class, $content);
        $this->assertSame($body, $content->getText(), 'Expanded Markdown keeps the complete producer text, including its preamble.');

        $harness = new VirtualTuiHarness(columns: $columns, rows: 100, displayState: $display);
        $state = new TuiSessionState('run');
        $state->transcriptDisplayState = $display;
        $human = new TranscriptBlock('human', TranscriptBlockKindEnum::UserMessage, 'run', 2, implode("\n", array_fill(0, 9, 'Human text'))."\nHuman final detail.");
        $state->replaceTranscript([$block, $human]);
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks($state->transcript);
        (new PreviewExpansionInputListener())->register($this->buildTuiContext()->withTui($harness->tui())->withState($state)->withScreen($harness->screen())->build());
        $harness->startInputLoop();
        try {
            $collapsed = $harness->plainScreenText();
            $this->assertStringNotContainsString('Automatic final detail.', $collapsed);
            $this->assertStringContainsString('Human final detail.', $collapsed);
            $harness->sendInput("\x0f");
            $this->assertTrue($display->previewableBlocksExpanded);
            $expanded = $harness->plainScreenText();
            $this->assertStringContainsString('Automatic final detail.', $expanded);
            $this->assertStringContainsString('café 界', $expanded);
            $this->assertStringContainsString('Human final detail.', $expanded);
            if ('subagent' === ($metadata['background_completion']['kind'] ?? null)) {
                $this->assertStringContainsString('original-child', $expanded);
            }
            $harness->sendInput("\x0f");
            $this->assertFalse($display->previewableBlocksExpanded);
            $this->assertStringNotContainsString('Automatic final detail.', $harness->plainScreenText());
            $this->assertStringContainsString('Human final detail.', $harness->plainScreenText());
            $this->assertSame($body, $block->text);
        } finally {
            $harness->stopInputLoop();
        }
    }

    public function testMismatchedPreambleKeepsOriginalFirstLine(): void
    {
        foreach (self::automaticSources() as [$columns, $metadata, $label, $kind, $preamble]) {
            if ('' === $preamble) {
                continue;
            }
            if (isset($metadata['agent_communication'])) {
                $metadata['agent_communication']['source_run_id'] .= '-different';
                $firstWord = 'Agent';
            } else {
                $metadata['background_completion']['run_id'] .= '-different';
                $firstWord = 'Back';
            }
            $body = $preamble."Summary\nFull result.";
            $block = new TranscriptBlock('mismatch', $kind, 'run', 1, $body, ['producer_metadata' => $metadata]);
            $widget = (new TranscriptBlockWidgetFactory())->buildWidget($block, new DefaultTheme(new ThemePalette('mismatch', [])));
            $this->assertInstanceOf(AutomaticMessageWidget::class, $widget);
            $tui = new Tui(terminal: new VirtualTerminal(columns: $columns, rows: 50));
            $tui->add($widget);
            $rows = $widget->getContext()->renderWidget($widget, new RenderContext($columns, 50));
            $this->assertCount(1, $rows);
            $this->assertStringContainsString($firstWord, AnsiUtils::stripAnsiCodes($rows[0]));
            $this->assertStringNotContainsString('Summary', $rows[0]);
            $content = $widget->all()[0]->all()[1];
            $this->assertInstanceOf(MarkdownWidget::class, $content);
            $this->assertSame($body, $content->getText());
            $this->assertSame($body, $block->text);
        }
    }

    public function testHumanQuotingAutomaticTextAndUnrelatedMetadataStillUseFullMarkdown(): void
    {
        $factory = new TranscriptBlockWidgetFactory();
        $body = "Agent message from child (informational notice) run child, activity notice-activity. This is agent-authored text, not a human request or runtime control.\n\nBackground code_mode result (run_id: source, tool_call_id: call, invocation: invocation).\n\n".str_repeat("Human detail\n", 10).'Human end';
        $block = new TranscriptBlock('human', TranscriptBlockKindEnum::UserMessage, 'run', 1, $body, ['producer_metadata' => ['source' => 'unrelated', 'system_reminder' => false]]);
        $this->assertInstanceOf(MarkdownWidget::class, $factory->buildWidget($block, new DefaultTheme(new ThemePalette('human', []))));
        $harness = new VirtualTuiHarness(rows: 60);
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks([$block]);
        $plain = $harness->plainScreenText();
        $this->assertStringContainsString('Human end', $plain);
        $this->assertStringContainsString('Agent message from child', $plain);
        $this->assertStringContainsString('Background code_mode result', $plain);
        $this->assertSame($body, $block->text);
        $this->assertStringContainsString(trim(TranscriptGlyphs::GLYPH_USER_MESSAGE), $plain);
        $this->assertStringNotContainsString('Ctrl+O', $plain);
    }

    public function testRealQueueTranslationKeepsHumanSteeringButOmitsAutomaticHandoff(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec($serializer), $serializer);
        $state = new TuiSessionState('run');
        foreach ([['human', 'Real human steering', []], ['automatic', 'Hidden automatic handoff', ['background_completion' => ['kind' => 'subagent', 'identity' => 'automatic']]]] as $index => [$identity, $text, $metadata]) {
            $event = $translator->translate(new RunEvent('run', $index + 1, 1, 'agent_command_queued', [
                'kind' => 'steer', 'idempotency_key' => $identity,
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]], 'metadata' => $metadata],
            ]));
            if ('automatic' === $identity) {
                $this->assertNull($event);
            } else {
                $this->assertNotNull($event);
                $state->applyQueuedUserMessageEvent($event);
            }
        }
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->syncQueuedUserMessages($state->queuedUserMessages);
        $plain = $harness->plainScreenText();
        $this->assertStringContainsString('Real human steering', $plain);
        $this->assertStringNotContainsString('Hidden automatic handoff', $plain);
        $this->assertSame(['human' => 'Real human steering'], $state->queuedUserMessages);
    }
}
