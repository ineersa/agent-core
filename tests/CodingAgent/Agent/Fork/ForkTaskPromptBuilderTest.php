<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ForkTaskPromptBuilder.
 *
 * Test thesis:
 *   - The built task user message contains "Task:", the task body, and
 *     all 11 section headers.
 *   - The FORK_CHILD system append contains the fork mode declaration.
 */
#[CoversClass(ForkTaskPromptBuilder::class)]
final class ForkTaskPromptBuilderTest extends TestCase
{
    private ForkTaskPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ForkTaskPromptBuilder();
    }

    public function testBuildTaskUserMessageContainsTaskIndicator(): void
    {
        $task = 'Implement feature X';
        $message = $this->builder->buildTaskUserMessage($task);

        $this->assertStringContainsString('Task:', $message);
        $this->assertStringContainsString($task, $message);
    }

    public function testBuildTaskUserMessageContainsAll11SectionHeaders(): void
    {
        $message = $this->builder->buildTaskUserMessage('Test task');

        $expectedSections = [
            '## 1. Result / status',
            '## 2. Scope and authority',
            '## 3. Navigation / tool trail',
            '## 4. Evidence and context discovered',
            '## 5. Changes made',
            '## 6. Data/control flow',
            '## 7. Validation performed',
            '## 8. Risks, gaps, and gotchas',
            '## 9. Reusable learnings',
            '## 10. Continuation context',
            '## 11. Final handoff',
        ];

        foreach ($expectedSections as $section) {
            $this->assertStringContainsString($section, $message, "Missing section: {$section}");
        }
    }

    public function testBuildTaskUserMessageMentionsHandoffReport(): void
    {
        $message = $this->builder->buildTaskUserMessage('Test');

        $this->assertStringContainsString('handoff report', $message);
        $this->assertStringContainsString('dense', $message);
    }

    public function testForkChildSystemPromptAppend(): void
    {
        $append = $this->builder->forkChildSystemPromptAppend();

        $this->assertStringContainsString('FORK MODE IS ENABLED', $append);
        $this->assertStringContainsString('forked child agent', $append);
        $this->assertStringContainsString('delegated task', $append);
        $this->assertStringContainsString('last user message', $append);
        $this->assertStringContainsString('Do not suggest launching a fork', $append);
        $this->assertStringContainsString('obey the delegated task', $append);
    }

    public function testBuildTaskUserMessageWithEmptyTask(): void
    {
        $message = $this->builder->buildTaskUserMessage('');

        $this->assertStringContainsString('Task:', $message);
        $this->assertStringContainsString('Return a dense handoff report', $message);
    }

    public function testBuildTaskUserMessageWithComplexTask(): void
    {
        $task = "Implement feature X:\n- Step 1\n- Step 2\n- Step 3";
        $message = $this->builder->buildTaskUserMessage($task);

        $this->assertStringContainsString('Step 1', $message);
        $this->assertStringContainsString('Step 2', $message);
        $this->assertStringContainsString('Step 3', $message);
    }
}
