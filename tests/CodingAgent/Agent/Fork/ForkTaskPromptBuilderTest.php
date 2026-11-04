<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForkTaskPromptBuilder::class)]
final class ForkTaskPromptBuilderTest extends TestCase
{
    public function testAssignmentIsPreservedVerbatimWithoutRoleOrReportInstructions(): void
    {
        $builder = new ForkTaskPromptBuilder();
        $task = "  Implement feature X:\n- Preserve contract\n\n";

        $this->assertSame($task, $builder->buildTaskUserMessage($task));
        $this->assertSame('', $builder->buildTaskUserMessage(''));
    }

    public function testSystemAppendOwnsImplementationRoleAndBriefFinalResult(): void
    {
        $append = new ForkTaskPromptBuilder()->forkChildSystemPromptAppend();

        foreach ([
            'The latest assignment is your task; inherited context is reference',
            'Inspect the assigned checkout and relevant code',
            'preserve unrelated changes',
            'Implement the decided behavior and design using routine local judgment',
            'Report material contradictions or missing decisions rather than changing scope',
            'Run focused validation',
            'Main owns task and ownership records, phases, other agents, commits, reviews, PRs',
            'Do not perform that administration or launch or monitor other agents',
            'Finish all tool work before returning one brief final result',
            'status, changes, checks and outcomes, and any blocker',
            'For unfinished work, include locations and the next action',
            'exclude secrets',
        ] as $instruction) {
            $this->assertStringContainsString($instruction, $append);
        }
        $this->assertStringNotContainsString('Repository:', $append);
        $this->assertStringNotContainsString('actionable review finding', $append);
        $this->assertSame(1, substr_count($append, 'one brief final result'));
    }
}
