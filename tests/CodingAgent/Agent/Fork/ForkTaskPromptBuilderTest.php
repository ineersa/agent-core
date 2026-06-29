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

        self::assertStringContainsString('Task:', $message);
        self::assertStringContainsString($task, $message);
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
            self::assertStringContainsString($section, $message, "Missing section: {$section}");
        }
    }

    public function testBuildTaskUserMessageMentionsHandoffReport(): void
    {
        $message = $this->builder->buildTaskUserMessage('Test');

        self::assertStringContainsString('handoff report', $message);
        self::assertStringContainsString('dense', $message);
    }

    public function testForkChildSystemPromptAppend(): void
    {
        $append = $this->builder->forkChildSystemPromptAppend();

        self::assertStringContainsString('FORK MODE IS ENABLED', $append);
        self::assertStringContainsString('forked child agent', $append);
        self::assertStringContainsString('delegated task', $append);
        self::assertStringContainsString('last user message', $append);
        self::assertStringContainsString('Do not suggest launching a fork', $append);
        self::assertStringContainsString('obey the delegated task', $append);
    }

    public function testBuildTaskUserMessageWithEmptyTask(): void
    {
        $message = $this->builder->buildTaskUserMessage('');

        self::assertStringContainsString('Task:', $message);
        self::assertStringContainsString('Return a dense handoff report', $message);
    }

    public function testBuildTaskUserMessageWithComplexTask(): void
    {
        $task = "Implement feature X:\n- Step 1\n- Step 2\n- Step 3";
        $message = $this->builder->buildTaskUserMessage($task);

        self::assertStringContainsString('Step 1', $message);
        self::assertStringContainsString('Step 2', $message);
        self::assertStringContainsString('Step 3', $message);
    }
}
