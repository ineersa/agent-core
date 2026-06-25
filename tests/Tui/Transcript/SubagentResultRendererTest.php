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
        self::assertStringContainsString('subagent scout', $joined);
        self::assertStringContainsString('Task: inspect runtime events', $joined);
        self::assertStringContainsString('agent_01HX', $joined);
        self::assertStringContainsString('3 turns', $joined);
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
        $joined = implode("
", $lines);
        self::assertStringContainsString('subagent scout', $joined);
        self::assertStringContainsString('38 tools', $joined);
        self::assertStringContainsString('49k tok', $joined);
        self::assertStringContainsString('RuntimeEventTranslator', $joined);
        self::assertStringContainsString('in:35k', $joined);
        self::assertStringContainsString('deepseek/deepseek-v4-flash', $joined);
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
        self::assertTrue($renderer->supports($block));
    }
}
