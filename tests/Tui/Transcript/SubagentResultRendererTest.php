<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\SubagentResultRenderer;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class SubagentResultRendererTest extends TestCase
{
    public function testFactoryBuildsStructuredSubagentBlock(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_01HX', 'task_summary' => 'inspect runtime events', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium',
            'turn_no' => 3, 'llm_step_count' => 3, 'elapsed_ms' => 18000,
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_tc1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            text: 'ignored when progress present',
            meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)],
            streaming: true,
        );

        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('╭─', $joined);
        $this->assertStringContainsString('╰─', $joined);
        $this->assertStringContainsString('scout', $joined);
        $this->assertStringContainsString('[running]', $joined);
        $this->assertStringContainsString('Task inspect runtime events', $joined);
        $this->assertStringContainsString('agent_01HX', $joined);
        $this->assertStringContainsString('3 LLM steps', $joined);
        $this->assertStringNotContainsString(' turns', $joined);
    }

    public function testCardTruncationKeepsValidUtf8ForMultibyteTaskSummary(): void
    {
        $box = "\u{2500}";
        $this->assertTrue(mb_check_encoding($box, 'UTF-8'));
        $task = str_repeat($box, 160);
        $progress = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_utf8', 'task_summary' => $task, 'agent_run_id' => 'child-run-utf8',
            'model' => 'test/model', 'reasoning' => 'medium',
            'turn_no' => 1, 'llm_step_count' => 1, 'elapsed_ms' => 1000,
            'assistant_excerpt' => str_repeat($box, 260),
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_utf8',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            text: '',
            meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)],
            streaming: true,
        );

        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertTrue(mb_check_encoding($joined, 'UTF-8'));
        $this->assertStringContainsString('Task '.$box.$box, $joined);
        $this->assertStringContainsString('…', $joined);
    }

    public function testFooterRendersSingularLlmStepWithoutTurnLabel(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_one', 'task_summary' => 'one step', 'agent_run_id' => 'child-run-1',
            'turn_no' => 99, 'llm_step_count' => 1,
            'input_tokens' => 100, 'output_tokens' => 20,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_one_step',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            text: '',
            meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)],
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('1 LLM step', $joined);
        $this->assertStringNotContainsString('LLM steps', $joined);
        $this->assertStringNotContainsString('99 turns', $joined);
        $this->assertStringNotContainsString(' turns', $joined);
    }

    public function testRendersRichSingleProgressCardWithoutLastPrefixOnRecentTools(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_01HX', 'agent_run_id' => 'run-child-abc',
            'task_summary' => 'inspect runtime events', 'turn_no' => 17,
            'elapsed_ms' => 139000, 'tool_count' => 38, 'total_tokens' => 49000,
            'input_tokens' => 35000, 'output_tokens' => 14000, 'reasoning_tokens' => 584000,
            'cost' => 0.0104,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'high',
            'artifact_path' => 'artifacts/agents/agent_01HX',
            'recent_tools' => ['read: path="RuntimeEventTranslator.php"'],
            'assistant_excerpt' => 'Found the projection hook.',
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_tc1', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)], streaming: true,
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('● scout [running]', $joined);
        $this->assertStringContainsString('38 tools', $joined);
        $this->assertStringContainsString('49k tok', $joined);
        $this->assertStringContainsString('2m19s', $joined);
        $this->assertStringContainsString('Artifact artifacts/agents/agent_01HX', $joined);
        $this->assertStringContainsString('Run run-child-abc', $joined);
        $this->assertStringContainsString('› read: path="RuntimeEventTranslator.php"', $joined);
        $this->assertStringNotContainsString('Last read:', $joined);
        $this->assertStringContainsString('in:35k', $joined);
        $this->assertStringContainsString('deepseek/deepseek-v4-flash (reasoning: high)', $joined);
        $this->assertStringContainsString('/agents-live', $joined);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatuses')]
    public function testTerminalSingleCardKeepsCompactToolsTokensElapsedSummary(string $status): void
    {
        $progress = [
            'mode' => 'single', 'status' => $status, 'agent_name' => 'scout',
            'artifact_id' => 'agent_01HX', 'agent_run_id' => 'run-child-abc',
            'task_summary' => 'inspect runtime events', 'turn_no' => 17,
            'elapsed_ms' => 139000, 'tool_count' => 38, 'total_tokens' => 49000,
            'input_tokens' => 35000, 'output_tokens' => 14000, 'reasoning_tokens' => 584000,
            'cost' => 0.0104,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'high',
            'artifact_path' => 'artifacts/agents/agent_01HX',
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_completed_summary', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)],
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('scout ['.$status.']', $joined);
        $this->assertStringContainsString('38 tools', $joined);
        $this->assertStringContainsString('49k tok', $joined);
        $this->assertStringContainsString('2m19s', $joined);
        $this->assertStringContainsString('deepseek/deepseek-v4-flash (reasoning: high)', $joined);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('subsecondDurations')]
    public function testSubsecondSummaryMatchesPlainText(int $milliseconds, string $expected): void
    {
        $snapshot = $this->snapshot([
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_ms', 'agent_run_id' => 'run-child-ms',
            'task_summary' => 'Read file', 'model' => 'test/model', 'reasoning' => 'medium',
            'elapsed_ms' => $milliseconds,
        ]);
        $block = new TranscriptBlock(
            id: 'tool_result_ms', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $snapshot],
        );
        $this->assertStringContainsString(' · '.$expected, implode("\n", $this->renderBlockLines($block)));
        $this->assertStringContainsString(' | '.$expected, new \Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter()->format($snapshot));
    }

    public static function subsecondDurations(): iterable
    {
        yield [0, '0ms'];
        yield [123, '123ms'];
        yield [999, '999ms'];
        yield [1000, '1s'];
    }

    public function testRendersWaitingHumanNeedsInputCard(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'waiting_human', 'agent_name' => 'scout',
            'artifact_id' => 'agent_wait', 'task_summary' => 'approve edit', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 2,
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_wait', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)],
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('⚠ scout [needs input]', $joined);
        $this->assertStringContainsString('Ctrl+\\', $joined);
    }

    public static function terminalStatuses(): iterable
    {
        yield ['completed'];
        yield ['failed'];
        yield ['cancelled'];
    }

    public function testMultilineTaskSummaryDoesNotEscapeCardRail(): void
    {
        $task = "You are a scout. Complete the following steps:\n\n1. Use `read` to list docs\n2. Summarize";
        $progress = [
            'mode' => 'single', 'status' => 'waiting_human', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a70', 'task_summary' => $task, 'turn_no' => 1, 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium',
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_multiline_task', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)],
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $joined) ?? $joined;
        $this->assertStringNotContainsString("\n\n1. Use", $plain);
        $this->assertStringContainsString('1. Use', $plain);
        $this->assertStringContainsString('│ Task ', $plain);
        $this->assertStringContainsString('╰─', $plain);
        $this->assertStringNotContainsString('Handoff', $plain);
        $this->assertStringNotContainsString('Ctrl+O to expand handoff', $plain);
    }

    public function testTerminalExpandHandoffHintIsRailAlignedBeforeBottomBorder(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_done', 'task_summary' => 'task', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 3,
        ];
        $handoff = "# Handoff title\n\nUnique handoff body.\n\n- bullet one\n- bullet two\n- bullet three\n- bullet four\n- bullet five\n- bullet six\n- bullet seven\n- bullet eight\n- bullet nine";
        $block = new TranscriptBlock(
            id: 'tool_result_tc_hint', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: 'fallback', meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => $this->snapshot($progress),
                'result' => $handoff,
            ],
        );
        $joined = implode("\n", $this->renderBlockLines($block, previewLines: 3));
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $joined) ?? $joined;
        $this->assertStringContainsString('│ Ctrl+O to expand handoff', $plain);
        $posHint = strpos($plain, '│ Ctrl+O to expand handoff');
        $posBottom = strrpos($plain, '╰─');
        $this->assertNotFalse($posHint);
        $this->assertNotFalse($posBottom);
        $this->assertLessThan($posBottom, $posHint);
    }

    public function testActiveProgressWithResultTextDoesNotRenderHandoffSection(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_run', 'task_summary' => 'task', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 1,
        ];
        $handoff = "# Premature handoff\n\nShould not show while running.";
        $block = new TranscriptBlock(
            id: 'tool_result_running_handoff', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => $this->snapshot($progress),
                'result' => $handoff,
            ],
            streaming: true,
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('scout [running]', $joined);
        $this->assertStringNotContainsString('Handoff', $joined);
        $this->assertStringNotContainsString('Ctrl+O to expand handoff', $joined);
        $this->assertStringNotContainsString('Premature handoff', $joined);
    }

    public function testWaitingHumanWithResultTextDoesNotRenderHandoffSection(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'waiting_human', 'agent_name' => 'scout',
            'artifact_id' => 'agent_wait', 'task_summary' => 'approve', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 2,
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_wait_handoff', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => $this->snapshot($progress),
                'result' => "# Draft handoff\n\nNot terminal yet.",
            ],
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('needs input', $joined);
        $this->assertStringNotContainsString('Ctrl+O to expand handoff', $joined);
        $this->assertStringNotContainsString('Draft handoff', $joined);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatuses')]
    public function testRendersParallelProgressAsStackedCards(string $status): void
    {
        $progress = [
            'mode' => 'parallel', 'status' => 'running', 'completed_count' => 0, 'total_count' => 2, 'elapsed_ms' => 60000,
            'children' => [
                [
                    'index' => 1, 'agent_name' => 'scout', 'status' => 'running', 'artifact_id' => 'agent_1',
                    'agent_run_id' => 'run-1', 'task_summary' => 'Read docs', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 4, 'tool_count' => 3, 'total_tokens' => 9000,
                    'artifact_path' => 'artifacts/agents/agent_1',
                ],
                [
                    'index' => 2, 'agent_name' => 'reviewer', 'status' => $status, 'artifact_id' => 'agent_2', 'agent_run_id' => 'run-1', 'model' => 'test/model', 'reasoning' => 'medium',
                    'task_summary' => 'Review patch', 'turn_no' => 1, 'tool_count' => 5, 'total_tokens' => 12000, 'elapsed_ms' => 45000, 'active_tool' => 'read: path="AGENTS.md"',
                ],
            ],
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_tc_par', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)], streaming: true,
        );
        $joined = implode("\n", $this->renderBlockLines($block));
        $this->assertStringContainsString('parallel subagents (0/2 completed)', $joined);
        $this->assertStringContainsString('├─', $joined);
        $this->assertStringContainsString('#1', $joined);
        $this->assertStringContainsString('scout', $joined);
        $this->assertStringContainsString('#2', $joined);
        $this->assertStringContainsString('reviewer', $joined);
        $this->assertStringContainsString('Task Read docs', $joined);
        $this->assertStringContainsString('Task Review patch', $joined);
        $this->assertStringContainsString('reviewer ['.$status.']', $joined);
        $this->assertStringContainsString('5 tools', $joined);
        $this->assertStringContainsString('12k tok', $joined);
        $this->assertStringContainsString('45s', $joined);
        $this->assertStringNotContainsString('running Step', $joined);
    }

    public function testRendersTerminalWidgetWithMarkdownHandoffPreview(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_done', 'task_summary' => 'task', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 3,
            'artifact_path' => 'artifacts/agents/agent_done',
        ];
        $handoff = "# Handoff title\n\nUnique handoff body.\n\n- bullet one\n- bullet two\n- bullet three\n- bullet four\n- bullet five\n- bullet six\n- bullet seven\n- bullet eight\n- bullet nine";
        $block = new TranscriptBlock(
            id: 'tool_result_tc', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: 'fallback', meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => $this->snapshot($progress),
                'subagent_final' => true,
                'result' => $handoff,
            ],
            streaming: false,
        );
        $joined = implode("\n", $this->renderBlockLines($block, previewLines: 3));
        $this->assertStringContainsString('✓ scout [completed]', $joined);
        $this->assertStringContainsString('Handoff', $joined);
        $this->assertStringNotContainsString('│ Handoff', $joined);
        $this->assertStringContainsString('Handoff title', $joined);
        $this->assertStringContainsString('Unique handoff body', $joined);
        $this->assertStringContainsString('more line', $joined);
        $this->assertStringContainsString('Ctrl+O to expand handoff', $joined);
        $this->assertStringContainsString('agent_retrieve', $joined);
        $this->assertStringNotContainsString('bullet nine', $joined);
    }

    public function testCollapsedHandoffEllipsisKeepsItalicAnsiAsSiblingWidget(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_done', 'task_summary' => 'task', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 3,
            'artifact_path' => 'artifacts/agents/agent_done',
        ];
        $handoff = "# Handoff title\n\nUnique handoff body.\n\n- bullet one\n- bullet two\n- bullet three\n- bullet four\n- bullet five\n- bullet six\n- bullet seven\n- bullet eight\n- bullet nine";
        $block = new TranscriptBlock(
            id: 'tool_result_tc_italic', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: 'fallback', meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => $this->snapshot($progress),
                'subagent_final' => true,
                'result' => $handoff,
            ],
            streaming: false,
        );

        $joined = implode("\n", $this->renderBlockLines($block, previewLines: 3));
        $this->assertMatchesRegularExpression(
            '/\x1b\[3m(?:\x1b\[[0-9;]*m)*… \d+ more lines?/',
            $joined,
            'Collapsed handoff ellipsis must keep italic ANSI outside MarkdownWidget',
        );
        $this->assertStringContainsString('… ', preg_replace('/\x1b\[[0-9;]*m/', '', $joined) ?? $joined);
    }

    public function testExpandedHandoffShowsFullMarkdownBody(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_done', 'task_summary' => 'task', 'agent_run_id' => 'child-run-1', 'model' => 'test/model', 'reasoning' => 'medium', 'turn_no' => 3,
        ];
        $handoff = "line0\nline1\nline2\nline3\nline4\nline5\nline6\nline7\nline8\nline9";
        $block = new TranscriptBlock(
            id: 'tool_result_expand', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => $this->snapshot($progress),
                'result' => $handoff,
            ],
        );
        $joined = implode("\n", $this->renderBlockLines($block, previewLines: 2, expanded: true));
        $this->assertStringContainsString('line9', $joined);
        $this->assertStringNotContainsString('more line', $joined);
        $this->assertStringNotContainsString('Ctrl+O to expand handoff', $joined);
    }

    public function testSubagentResultRendererSupportsMetaOnly(): void
    {
        $renderer = new SubagentResultRenderer();
        $block = new TranscriptBlock(
            id: 'tr',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 0,
            text: '',
            meta: ['tool_name' => 'subagent'],
        );
        $this->assertTrue($renderer->supports($block));
    }

    public function testChildTranscriptCardContextUsageContract(): void
    {
        $base = [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_ctx', 'agent_run_id' => 'child-run-ctx',
            'task_summary' => 'Context stats', 'reasoning' => 'medium', 'turn_no' => 3,
            'input_tokens' => 35000, 'output_tokens' => 14000,
        ];

        $canonical = array_merge($base, \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::progressPayloadOverrides());
        $plainCanonical = $this->plainRenderedSubagentCard($canonical);
        $this->assertStringContainsString(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::TRANSCRIPT_CTX_LINE, $plainCanonical);

        $missingLatest = array_merge($base, ['model' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::MODEL]);
        $plainMissingLatest = $this->plainRenderedSubagentCard($missingLatest);
        $this->assertStringNotContainsString('CTX ', $plainMissingLatest, 'Missing latest_input_tokens must not fabricate a CTX line');
        $this->assertDoesNotMatchRegularExpression('/\b\d+%\s+\d/', $plainMissingLatest, 'Missing latest tokens must not show a context percentage');

        // Canonical progress always carries model; CTX still requires latest tokens + window.
        $missingWindow = array_merge($base, \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::progressPayloadOverridesMissingContextWindow());
        $plainMissingWindow = $this->plainRenderedSubagentCard($missingWindow);
        $this->assertStringNotContainsString('CTX ', $plainMissingWindow, 'Missing context_window must not fabricate a CTX line');
        $this->assertDoesNotMatchRegularExpression('/\b\d+%\s+\d/', $plainMissingWindow);

        $unresolvableModelWithWindow = array_merge($base, \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::progressPayloadOverridesUnresolvableModelWithWindow());
        $plainUnresolvableWithWindow = $this->plainRenderedSubagentCard($unresolvableModelWithWindow);
        $this->assertStringContainsString(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::TRANSCRIPT_CTX_LINE, $plainUnresolvableWithWindow, 'Explicit context_window must format CTX even when model is not catalog-resolvable');

        $unresolvableNoWindow = array_merge($base, \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::progressPayloadOverridesWithLatestInput(
            \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::LATEST_INPUT_TOKENS,
            'unknown-provider/no-context-window',
            0,
        ));
        unset($unresolvableNoWindow['context_window']);
        $plainUnresolvableNoWindow = $this->plainRenderedSubagentCard($unresolvableNoWindow);
        $this->assertStringNotContainsString('CTX ', $plainUnresolvableNoWindow, 'Without context_window, unresolvable model must not fabricate CTX');
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function plainRenderedSubagentCard(array $progress): string
    {
        $block = new TranscriptBlock(
            id: 'tool_result_ctx', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $this->snapshot($progress)],
        );
        $joined = implode("\n", $this->renderBlockLines($block));

        return preg_replace('/\x1b\[[0-9;]*m/', '', $joined) ?? $joined;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function snapshot(array $progress): SubagentProgressSnapshotInterface
    {
        $snapshot = SubagentProgressSerializerTestSupport::denormalizer()->denormalize(
            $progress,
            SubagentProgressSnapshotInterface::class,
        );
        $this->assertInstanceOf(SubagentProgressSnapshotInterface::class, $snapshot);
        $violations = SubagentProgressSerializerTestSupport::validator()->validate($snapshot);
        $this->assertSame(0, $violations->count());

        return $snapshot;
    }

    /**
     * @return list<string>
     */
    private function renderBlockLines(
        TranscriptBlock $block,
        int $previewLines = 8,
        bool $expanded = false,
        int $width = 120,
    ): array {
        $displayConfig = new TranscriptDisplayConfig(toolResultPreviewLines: $previewLines);
        $displayState = new TranscriptDisplayState(previewableBlocksExpanded: $expanded);
        $factory = new TranscriptBlockWidgetFactory(
            subagentRenderer: new SubagentResultRenderer(
                displayConfig: $displayConfig,
                displayState: $displayState,
            ),
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
        $theme = $this->theme();
        $root = new ContainerWidget();
        $root->add($factory->buildWidget($block, $theme));

        return (new Renderer())->renderFrame($root, max($width, 1), 24)->toArray();
    }

    private function theme(): DefaultTheme
    {
        return new DefaultTheme(new ThemePalette('test', [
            'accent' => 'cyan',
            'success' => 'green',
            'warning' => 'yellow',
            'error' => 'red',
            'muted' => '#888',
            'border_accent' => 'bright_cyan',
            'border_muted' => '#666',
            'tool_output' => 'white',
            'tool_title' => 'bright_white',
        ]));
    }
}
