<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Tests the compact fork handoff prompt contract. */
#[CoversClass(ForkTaskPromptBuilder::class)]
final class ForkTaskPromptBuilderTest extends TestCase
{
    private ForkTaskPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ForkTaskPromptBuilder();
    }

    public function testBuildTaskUserMessageInterpolatesTask(): void
    {
        $task = "Implement feature X:\n- Preserve contract";
        $message = $this->builder->buildTaskUserMessage($task);

        $this->assertStringContainsString("Delegated task:\n{$task}", $message);
        $this->assertStringContainsString('Return a compact handoff using these fields:', $message);
        $this->assertStringNotContainsString('task-board', $message);
        $this->assertStringNotContainsString('explicit checkout ownership handoff', $message);
        $this->assertStringNotContainsString('You are a fork delegated by a parent agent.', $message);
        $this->assertStringNotContainsString('compacted snapshot', $message);
    }

    public function testBuildTaskUserMessageDefinesCompactHandoffContract(): void
    {
        $message = $this->builder->buildTaskUserMessage('Test task');

        foreach ([
            'Outcome: complete | partial | blocked | failed',
            'Result:',
            'Validation:',
            'Repository:',
            'Open:',
        ] as $field) {
            $this->assertStringContainsString($field, $message, "Missing field: {$field}");
        }

        $this->assertStringContainsString('Outcome and Result are required.', $message);
        $this->assertStringContainsString('Include Repository for implementation, even if incomplete', $message);
        $this->assertStringContainsString('Include Open only when needed.', $message);
        $this->assertStringNotContainsString('## Status', $message);
        $this->assertStringNotContainsString('## Repository state', $message);
        $this->assertStringNotContainsString('## Length discipline', $message);
        $this->assertStringNotContainsString('250–700 words', $message);
    }

    public function testBuildTaskUserMessageRequiresDeltaInsteadOfTranscript(): void
    {
        $message = $this->builder->buildTaskUserMessage('Test task');

        $this->assertStringContainsString('Report only new information the parent needs to evaluate or continue the work.', $message);
        $this->assertStringContainsString('Omit repeated background, task text, activity logs, and empty sections.', $message);
        $this->assertStringContainsString('Include every actionable review finding, ordered by severity with location and impact.', $message);
        $this->assertStringNotContainsString('Return the semantic delta produced by this fork, not a transcript.', $message);
        $this->assertStringNotContainsString('routine implementation: 250–700 words;', $message);
        $this->assertStringNotContainsString('exhaustive reports: only when explicitly requested.', $message);
    }

    public function testForkChildSystemPromptAppendPreservesOperatingContractAndFinality(): void
    {
        $append = $this->builder->forkChildSystemPromptAppend();

        $this->assertStringContainsString('You are the fork child.', $append);
        $this->assertStringContainsString('latest user message for the parent', $append);
        $this->assertStringContainsString('do not launch or monitor other agents', $append);
        $this->assertStringContainsString('Finish all tool work before returning one final handoff to the parent.', $append);
        $this->assertStringContainsString('Do not emit progress narration, request tools in the handoff message, or replace it with a later recap.', $append);
        $this->assertStringContainsString('Exclude secrets from the report.', $append);
        $this->assertStringNotContainsString('FORK MODE IS ENABLED.', $append);
    }
}
