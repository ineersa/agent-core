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
        self::assertStringContainsString('subagent scout running', $joined);
        self::assertStringContainsString('Task: inspect runtime events', $joined);
        self::assertStringContainsString('Artifact: agent_01HX', $joined);
        self::assertStringContainsString('turn 3', $joined);
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
