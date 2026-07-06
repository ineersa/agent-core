<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Transcript\SubagentResultRenderer;
use Ineersa\Tui\Transcript\TranscriptBlockRenderer;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\TestCase;

final class SubagentResultRendererTest extends TestCase
{
    public function testTranscriptBlockRendererDelegatesStructuredSubagentBlock(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_01HX', 'task_summary' => 'inspect runtime events', 'turn_no' => 3, 'elapsed_ms' => 18000,
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_tc1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            text: 'ignored when progress present',
            meta: ['tool_name' => 'subagent', 'subagent_progress' => $progress],
            streaming: true,
        );

        $renderer = new TranscriptBlockRenderer();
        $lines = $renderer->renderBlock($block, new TuiRenderContext(terminalWidth: 100));
        $joined = implode(' ', $lines);
        $this->assertStringContainsString('subagent scout', $joined);
        $this->assertStringContainsString('Task: inspect runtime events', $joined);
        $this->assertStringContainsString('agent_01HX', $joined);
        $this->assertStringContainsString('3 turns', $joined);
    }

    public function testRendersRichSingleProgress(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_01HX', 'task_summary' => 'inspect runtime events', 'turn_no' => 17,
            'elapsed_ms' => 139000, 'tool_count' => 38, 'total_tokens' => 49000,
            'input_tokens' => 35000, 'output_tokens' => 14000, 'reasoning_tokens' => 584000,
            'cost' => 0.0104, 'model' => 'deepseek/deepseek-v4-flash',
            'artifact_path' => 'artifacts/agents/agent_01HX',
            'recent_tools' => ['read: path="RuntimeEventTranslator.php"'],
            'assistant_excerpt' => 'Found the projection hook.',
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_tc1', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $progress], streaming: true,
        );
        $lines = (new TranscriptBlockRenderer())->renderBlock($block, new TuiRenderContext(terminalWidth: 120));
        $joined = implode('
', $lines);
        $this->assertStringContainsString('subagent scout', $joined);
        $this->assertStringContainsString('38 tools', $joined);
        $this->assertStringContainsString('49k tok', $joined);
        $this->assertStringContainsString('RuntimeEventTranslator', $joined);
        $this->assertStringContainsString('in:35k', $joined);
        $this->assertStringContainsString('deepseek/deepseek-v4-flash', $joined);
    }

    public function testRendersParallelProgressAsStackedSingleWidgets(): void
    {
        $progress = [
            'mode' => 'parallel', 'status' => 'running', 'completed_count' => 0, 'total_count' => 2, 'elapsed_ms' => 60000,
            'children' => [
                [
                    'index' => 1, 'agent_name' => 'scout', 'status' => 'running', 'artifact_id' => 'agent_1',
                    'task_summary' => 'Read docs', 'turn_no' => 4, 'tool_count' => 3, 'total_tokens' => 9000,
                    'artifact_path' => 'artifacts/agents/agent_1',
                ],
                [
                    'index' => 2, 'agent_name' => 'reviewer', 'status' => 'running', 'artifact_id' => 'agent_2',
                    'task_summary' => 'Review patch', 'turn_no' => 1, 'active_tool' => 'read: path="AGENTS.md"',
                ],
            ],
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_tc_par', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: '', meta: ['tool_name' => 'subagent', 'subagent_progress' => $progress], streaming: true,
        );
        $joined = implode('
', (new TranscriptBlockRenderer())->renderBlock($block, new TuiRenderContext(terminalWidth: 120)));
        $this->assertStringContainsString('parallel subagents running (0/2 completed)', $joined);
        $this->assertStringContainsString('#1 subagent scout', $joined);
        $this->assertStringContainsString('#2 subagent reviewer', $joined);
        $this->assertStringContainsString('Task: Read docs', $joined);
        $this->assertStringContainsString('Task: Review patch', $joined);
        $this->assertStringContainsString('AGENTS.md', $joined);
        $this->assertStringNotContainsString('running Step', $joined);
    }

    public function testRendersTerminalWidgetWithHandoff(): void
    {
        $progress = [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_done', 'task_summary' => 'task', 'turn_no' => 3,
            'artifact_path' => 'artifacts/agents/agent_done',
        ];
        $block = new TranscriptBlock(
            id: 'tool_result_tc', kind: TranscriptBlockKindEnum::ToolResult, runId: 'run1', seq: 1,
            text: 'fallback', meta: [
                'tool_name' => 'subagent',
                'subagent_progress' => $progress,
                'subagent_final' => true,
                'result' => "Subagent scout completed.\nArtifact: agent_done\n\nUnique handoff body.",
            ],
            streaming: false,
        );
        $lines = (new TranscriptBlockRenderer())->renderBlock($block, new TuiRenderContext(terminalWidth: 120));
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('completed scout', $joined);
        $this->assertStringContainsString('Unique handoff body', $joined);
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
}
