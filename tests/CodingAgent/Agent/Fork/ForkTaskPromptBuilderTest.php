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

        $this->assertStringContainsString('Task:', $message);
        $this->assertStringContainsString($task, $message);
        $this->assertStringContainsString('You are a fork delegated by a parent agent.', $message);
        $this->assertStringContainsString('compacted snapshot', $message);
    }

    public function testBuildTaskUserMessageDefinesCompactHandoffContract(): void
    {
        $message = $this->builder->buildTaskUserMessage('Test task');

        foreach ([
            '## Status',
            '## Result',
            '## Validation',
            '## Repository state',
            '## Risks / open decisions',
            '## Continuation',
            '## Reusable learning',
            '## Parent action',
            '## Length discipline',
        ] as $section) {
            $this->assertStringContainsString($section, $message, "Missing section: {$section}");
        }

        $this->assertStringContainsString('Required for implementation tasks', $message);
        $this->assertStringContainsString('`Commit: <full SHA>`', $message);
        $this->assertStringContainsString('`Worktree: clean` or `Worktree: dirty`', $message);
        $this->assertStringContainsString('Uncommitted paths:', $message);
    }

    public function testBuildTaskUserMessageRequiresDeltaInsteadOfTranscript(): void
    {
        $message = $this->builder->buildTaskUserMessage('Test task');

        $this->assertStringContainsString('Return the semantic delta produced by this fork, not a transcript.', $message);
        $this->assertStringContainsString('every file read, search made, or command run', $message);
        $this->assertStringContainsString('routine implementation: 250–700 words;', $message);
        $this->assertStringContainsString('exhaustive reports: only when explicitly requested.', $message);
    }

    public function testForkChildSystemPromptAppendPreservesForkModeAndFinality(): void
    {
        $append = $this->builder->forkChildSystemPromptAppend();

        $this->assertStringContainsString('FORK MODE IS ENABLED.', $append);
        $this->assertStringContainsString('forked child agent', $append);
        $this->assertStringContainsString('last user message', $append);
        $this->assertStringContainsString('Execute and verify all tool work first.', $append);
        $this->assertStringContainsString('Never emit the handoff in a message that also requests tools.', $append);
        $this->assertStringContainsString('final assistant message must be the complete handoff.', $append);
    }
}
