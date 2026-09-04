<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Virtual product proof for collapsed tool-card presentation.
 *
 * Test thesis: ChatScreen → TranscriptMountedWidget → TranscriptToolRenderer
 * keeps collapsed cards compact (identifying args, short/hidden results, spaced
 * result bodies, dim successful edit summaries) while Ctrl+O expansion restores
 * full detail. Exercises the live widget → ScreenBuffer path without tmux.
 */
final class TuiCollapsedToolCardVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-collapsed-tool-cards';

    #[Test]
    public function collapsedReadShowsIdentifyingArgsAndHidesSuccessfulResult(): void
    {
        $body = implode("\n", [
            '1|secret-line-a',
            '2|secret-line-b',
            '3|secret-line-c',
        ]);
        $displayState = new TranscriptDisplayState(previewableBlocksExpanded: false);
        $blocks = $this->readExchangeBlocks($body);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 8),
            displayState: $displayState,
        );
        $harness->screen()->setTranscriptBlocks($blocks);
        $harness->screen()->setWorkingVisible(false);

        $collapsed = $harness->plainScreenText();
        $this->assertStringContainsString('path: src/Example.php', $collapsed);
        $this->assertStringContainsString('offset: 10', $collapsed);
        $this->assertStringContainsString('limit: 20', $collapsed);
        $this->assertStringNotContainsString('nested:', $collapsed);
        $this->assertStringNotContainsString('secret-line-a', $collapsed);
        $this->assertStringNotContainsString('secret-line-c', $collapsed);

        $displayState->previewableBlocksExpanded = true;
        $harness->screen()->setTranscriptBlocks($blocks);
        $expanded = $harness->plainScreenText();
        $this->assertStringContainsString('nested:', $expanded);
        $this->assertStringContainsString('secret-line-a', $expanded);
        $this->assertStringContainsString('secret-line-c', $expanded);
    }

    #[Test]
    public function collapsedBashShowsStyledCommandAndBoundedTail(): void
    {
        $body = implode("\n", [
            'bash_line_0',
            'bash_line_1',
            'bash_line_2',
            'bash_line_3',
            'bash_line_4',
            'bash_line_5',
        ]);
        $palette = new ThemePalette('virtual-collapsed-bash', [
            ThemeColorEnum::MarkdownCode->value => '#00ffff',
            ThemeColorEnum::ToolOutput->value => '#39ff14',
            ThemeColorEnum::Muted->value => '#718096',
            ThemeColorEnum::Text->value => '',
        ]);
        $displayState = new TranscriptDisplayState(previewableBlocksExpanded: false);
        $blocks = [
            new TranscriptBlock(
                id: 'tc-bash',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-bash',
                    'tool_name' => 'bash',
                    'arguments' => [
                        'command' => "echo one\necho two",
                        'cwd' => '/tmp',
                    ],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-bash',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-bash',
                    'tool_name' => 'bash',
                    'result' => $body,
                    'is_error' => false,
                ],
            ),
        ];
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            palette: $palette,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 8),
            displayState: $displayState,
        );
        $harness->screen()->setTranscriptBlocks($blocks);
        $harness->screen()->setWorkingVisible(false);

        $collapsed = $harness->plainScreenText();
        $collapsedAnsi = $harness->ansiOutput();
        $this->assertStringContainsString('$ echo one ⏎ echo two', $collapsed);
        $this->assertStringNotContainsString('cwd:', $collapsed);
        $this->assertStringContainsString('bash_line_5', $collapsed);
        $this->assertStringContainsString('bash_line_3', $collapsed);
        $this->assertStringNotContainsString('bash_line_0', $collapsed);
        $this->assertStringContainsString('earlier line', $collapsed);
        $this->assertMatchesRegularExpression(
            '/echo one ⏎ echo two[\s\S]*?\n\s*\n[\s\S]*?bash_line_/',
            $collapsed,
            'Collapsed bash must separate command args from result body',
        );
        $this->assertMatchesRegularExpression(
            '/\x1b\[38;2;0;255;255m\$ echo one ⏎ echo two/',
            $collapsedAnsi,
            'Collapsed bash command must use MarkdownCode styling',
        );

        $displayState->previewableBlocksExpanded = true;
        $harness->screen()->setTranscriptBlocks($blocks);
        $expanded = $harness->plainScreenText();
        $this->assertStringContainsString('command:', $expanded);
        $this->assertStringContainsString('cwd:', $expanded);
        $this->assertStringContainsString('bash_line_0', $expanded);
        $this->assertStringContainsString('bash_line_5', $expanded);
        $this->assertStringNotContainsString('earlier line', $expanded);
    }

    #[Test]
    public function collapsedGenericToolKeepsScalarArgsAndShortResultHead(): void
    {
        $resultBody = implode("\n", [
            'status: moved',
            'from: TODO',
            'to: IN-PROGRESS',
            'notes: keep this line collapsed away',
            'extra: still collapsed away',
        ]);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 8),
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: false),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-move',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'move_task',
                meta: [
                    'tool_call_id' => 'call-move',
                    'tool_name' => 'move_task',
                    'arguments' => [
                        'task' => '2026-09-04-reduce-collapsed-tui-tool-call-clutter',
                        'from' => 'TODO',
                        'to' => 'IN-PROGRESS',
                        'payload' => [
                            'nested' => ['keep' => 'hidden'],
                            'blob' => str_repeat('x', 40),
                        ],
                        'notes' => "line1\nline2\nline3\nline4",
                    ],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-move',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'move_task',
                meta: [
                    'tool_call_id' => 'call-move',
                    'tool_name' => 'move_task',
                    'result' => $resultBody,
                    'is_error' => false,
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $collapsed = $harness->plainScreenText();
        $this->assertStringContainsString('task:', $collapsed);
        $this->assertStringContainsString('from: TODO', $collapsed);
        $this->assertStringContainsString('to: IN-PROGRESS', $collapsed);
        $this->assertStringNotContainsString('payload:', $collapsed);
        $this->assertStringNotContainsString('notes:', $collapsed);
        $this->assertStringContainsString('status: moved', $collapsed);
        $this->assertStringContainsString('from: TODO', $collapsed);
        $this->assertStringContainsString('to: IN-PROGRESS', $collapsed);
        $this->assertStringNotContainsString('notes: keep this line collapsed away', $collapsed);
        $this->assertStringNotContainsString('extra: still collapsed away', $collapsed);
        $this->assertMatchesRegularExpression(
            '/to: IN-PROGRESS[\s\S]*?\n\s*\n[\s\S]*?status: moved/',
            $collapsed,
            'Collapsed generic tool must separate args from result body',
        );
        $this->assertStringContainsString('more line', $collapsed);
    }

    #[Test]
    public function successfulEditSummaryUsesDimWhileErrorKeepsErrorColor(): void
    {
        $palette = new ThemePalette('virtual-edit-summary', [
            ThemeColorEnum::Dim->value => '#112233',
            ThemeColorEnum::ToolOutput->value => '#39ff14',
            ThemeColorEnum::Error->value => '#ff3366',
            ThemeColorEnum::DiffAdded->value => '#00ff00',
            ThemeColorEnum::DiffRemoved->value => '#ff0000',
            ThemeColorEnum::Text->value => '',
        ]);
        $patch = "--- a/x\n+++ b/x\n@@\n-old\n+new";
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            palette: $palette,
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: false),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-edit-ok',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'edit',
                meta: [
                    'tool_call_id' => 'call-edit-ok',
                    'tool_name' => 'edit',
                    'arguments' => ['path' => 'src/Foo.php', 'patch' => $patch],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-edit-ok',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'edit',
                meta: [
                    'tool_call_id' => 'call-edit-ok',
                    'tool_name' => 'edit',
                    'result' => "Applied patch to src/Foo.php (13 additions, 3 deletions)\n\nUpdated file context:\n@@ -1 +1 @@\n-old\n+new",
                    'is_error' => false,
                ],
            ),
            new TranscriptBlock(
                id: 'tc-edit-err',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 3,
                text: 'edit',
                meta: [
                    'tool_call_id' => 'call-edit-err',
                    'tool_name' => 'edit',
                    'arguments' => ['path' => 'src/Bar.php', 'patch' => $patch],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-edit-err',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 4,
                text: 'edit',
                meta: [
                    'tool_call_id' => 'call-edit-err',
                    'tool_name' => 'edit',
                    'result' => 'edit failed: conflict while applying patch',
                    'is_error' => true,
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $plain = $harness->plainScreenText();
        $ansi = $harness->ansiOutput();
        $this->assertStringContainsString('Applied patch to src/Foo.php (13 additions, 3 deletions)', $plain);
        $this->assertStringNotContainsString('Updated file context:', $plain);
        $this->assertStringContainsString('edit failed: conflict while applying patch', $plain);
        $this->assertMatchesRegularExpression(
            '/\x1b\[38;2;17;34;51m\s*Applied patch to src\/Foo\.php \(13 additions, 3 deletions\)/',
            $ansi,
            'Successful edit summary must use Dim styling',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\x1b\[38;2;57;255;20m\s*Applied patch to src\/Foo\.php \(13 additions, 3 deletions\)/',
            $ansi,
            'Successful edit summary must not use ToolOutput styling',
        );
        $this->assertMatchesRegularExpression(
            '/\x1b\[38;2;255;51;102m\s*edit failed: conflict while applying patch/',
            $ansi,
            'Failed edit diagnostics must keep Error styling',
        );
    }

    /**
     * @return list<TranscriptBlock>
     */
    private function readExchangeBlocks(string $body): array
    {
        return [
            new TranscriptBlock(
                id: 'tc-read',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'read',
                meta: [
                    'tool_call_id' => 'call-read',
                    'tool_name' => 'read',
                    'arguments' => [
                        'path' => 'src/Example.php',
                        'offset' => 10,
                        'limit' => 20,
                        'nested' => ['keep' => 'hidden-until-expand'],
                    ],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-read',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'read',
                meta: [
                    'tool_call_id' => 'call-read',
                    'tool_name' => 'read',
                    'result' => $body,
                    'is_error' => false,
                ],
            ),
        ];
    }
}
