<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer;
use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotDTO;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ForkChildMessageComposer.
 *
 * Test thesis:
 *   - Parent prologue (system, user-context messages) is stripped from
 *     the snapshot history.
 *   - Fresh system prompt is used and fork append is added.
 *   - User/assistant/tool historical messages are retained.
 *   - ForkTaskUserMessage is the final user message in the composed list.
 *   - System messages in the historical list are filtered out.
 */
#[CoversClass(ForkChildMessageComposer::class)]
final class ForkChildMessageComposerTest extends TestCase
{
    private const string FRESH_SYSTEM_PROMPT = 'Fresh Hatfield system prompt with tools, date, and CWD context.';
    private const string FORK_SYSTEM_APPEND = 'FORK MODE IS ENABLED.';
    private const string FORK_TASK_MESSAGE = 'Task: implement feature X';

    private ForkChildMessageComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new ForkChildMessageComposer();
    }

    /**
     * Build a snapshot with typical parent-side messages including prologue.
     */
    private function buildSnapshotWithPrologue(): ForkSessionSnapshotDTO
    {
        return new ForkSessionSnapshotDTO(
            messages: [
                // Parent prologue
                new AgentMessage(
                    role: 'system',
                    content: [['type' => 'text', 'text' => 'Parent system prompt']],
                ),
                new AgentMessage(
                    role: 'user-context',
                    content: [['type' => 'text', 'text' => 'Parent context from AGENTS.md']],
                ),
                // Historical conversation
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'User said something']],
                ),
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'Assistant replied']],
                ),
                new AgentMessage(
                    role: 'tool',
                    content: [['type' => 'text', 'text' => 'Tool result']],
                    toolCallId: 'call_1',
                    toolName: 'read',
                ),
            ],
            forkSystemPromptAppend: self::FORK_SYSTEM_APPEND,
            forkTaskUserMessage: self::FORK_TASK_MESSAGE,
            level: ForkLevelEnum::Middle,
        );
    }

    /**
     * Build a snapshot with no prologue (edge case).
     */
    private function buildSnapshotWithoutPrologue(): ForkSessionSnapshotDTO
    {
        return new ForkSessionSnapshotDTO(
            messages: [
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'Direct user message']],
                ),
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'Direct assistant reply']],
                ),
            ],
            forkSystemPromptAppend: self::FORK_SYSTEM_APPEND,
            forkTaskUserMessage: self::FORK_TASK_MESSAGE,
            level: ForkLevelEnum::Middle,
        );
    }

    /**
     * Build a snapshot with system messages in the history.
     */
    private function buildSnapshotWithMidHistorySystem(): ForkSessionSnapshotDTO
    {
        return new ForkSessionSnapshotDTO(
            messages: [
                // Parent prologue
                new AgentMessage(
                    role: 'system',
                    content: [['type' => 'text', 'text' => 'Parent system prompt']],
                ),
                new AgentMessage(
                    role: 'user-context',
                    content: [['type' => 'text', 'text' => 'Parent context']],
                ),
                // Historical conversation
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'User message 1']],
                ),
                new AgentMessage(
                    role: 'assistant',
                    content: [['type' => 'text', 'text' => 'Assistant reply 1']],
                ),
                // Mid-history compact summary
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'The conversation was compacted...']],
                    metadata: ['compact_summary' => true],
                ),
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'User message 2']],
                ),
            ],
            forkSystemPromptAppend: self::FORK_SYSTEM_APPEND,
            forkTaskUserMessage: self::FORK_TASK_MESSAGE,
            level: ForkLevelEnum::Middle,
        );
    }

    public function testComposesWithFreshSystemPromptAndAppend(): void
    {
        $snapshot = $this->buildSnapshotWithPrologue();
        $input = $this->composer->compose($snapshot, 'child_run_001', self::FRESH_SYSTEM_PROMPT);

        // First message should be system with combined prompt.
        self::assertCount(5, $input->messages);

        // The first message is the system message.
        $systemMsg = $input->messages[0];
        self::assertSame('system', $systemMsg->role);

        // The system prompt should be the fresh prompt + fork append.
        $expectedSystem = self::FRESH_SYSTEM_PROMPT."\n\n".self::FORK_SYSTEM_APPEND;
        self::assertSame($expectedSystem, $systemMsg->content[0]['text']);
    }

    public function testStripsParentPrologue(): void
    {
        $snapshot = $this->buildSnapshotWithPrologue();
        $input = $this->composer->compose($snapshot, 'child_run_001', self::FRESH_SYSTEM_PROMPT);

        // After the system message, we should have:
        // - user (historical)
        // - assistant
        // - tool
        // - user (fork task)
        $nonSystemMessages = \array_slice($input->messages, 1);

        // The prologue system/user-context messages should be gone.
        // Only historical user/assistant/tool + fork task should remain.
        $expectedRoles = ['user', 'assistant', 'tool', 'user'];
        $actualRoles = array_map(
            static fn (AgentMessage $m): string => $m->role,
            $nonSystemMessages,
        );

        self::assertSame($expectedRoles, $actualRoles);

        // Verify historical content is preserved.
        self::assertSame('User said something', $nonSystemMessages[0]->content[0]['text']);
        self::assertSame('Assistant replied', $nonSystemMessages[1]->content[0]['text']);
        self::assertSame('Tool result', $nonSystemMessages[2]->content[0]['text']);

        // Verify fork task is the final user message.
        self::assertSame(self::FORK_TASK_MESSAGE, $nonSystemMessages[3]->content[0]['text']);
    }

    public function testComposesWithoutPrologue(): void
    {
        $snapshot = $this->buildSnapshotWithoutPrologue();
        $input = $this->composer->compose($snapshot, 'child_run_002', self::FRESH_SYSTEM_PROMPT);

        $nonSystemMessages = \array_slice($input->messages, 1);

        $expectedRoles = ['user', 'assistant', 'user'];
        $actualRoles = array_map(
            static fn (AgentMessage $m): string => $m->role,
            $nonSystemMessages,
        );

        self::assertSame($expectedRoles, $actualRoles);
        self::assertSame(self::FORK_TASK_MESSAGE, $nonSystemMessages[2]->content[0]['text']);
    }

    public function testFiltersMidHistorySystemMessages(): void
    {
        $snapshot = $this->buildSnapshotWithMidHistorySystem();
        $input = $this->composer->compose($snapshot, 'child_run_003', self::FRESH_SYSTEM_PROMPT);

        // The mid-history compact_summary user message must be retained
        // (it's user role, not system).
        $nonSystemMessages = \array_slice($input->messages, 1);

        $expectedRoles = ['user', 'assistant', 'user', 'user', 'user'];
        $actualRoles = array_map(
            static fn (AgentMessage $m): string => $m->role,
            $nonSystemMessages,
        );

        self::assertSame($expectedRoles, $actualRoles);

        // The compact_summary message should be there.
        self::assertTrue($nonSystemMessages[2]->metadata['compact_summary'] ?? false);

        // Fork task is last.
        self::assertSame(self::FORK_TASK_MESSAGE, $nonSystemMessages[4]->content[0]['text']);
    }

    public function testComposesWithEmptyMessages(): void
    {
        $snapshot = new ForkSessionSnapshotDTO(
            messages: [],
            forkSystemPromptAppend: self::FORK_SYSTEM_APPEND,
            forkTaskUserMessage: self::FORK_TASK_MESSAGE,
            level: ForkLevelEnum::Junior,
            resolvedModel: null,
        );

        $input = $this->composer->compose($snapshot, 'child_run_004', self::FRESH_SYSTEM_PROMPT);

        // System message + fork task user message only.
        self::assertCount(2, $input->messages);
        self::assertSame('system', $input->messages[0]->role);
        self::assertSame('user', $input->messages[1]->role);
        self::assertSame(self::FORK_TASK_MESSAGE, $input->messages[1]->content[0]['text']);
    }

    public function testComposeSetsRunIdAndSystemPrompt(): void
    {
        $snapshot = $this->buildSnapshotWithPrologue();
        $input = $this->composer->compose($snapshot, 'child_run_005', self::FRESH_SYSTEM_PROMPT);

        self::assertSame('child_run_005', $input->runId);
        self::assertNotNull($input->systemPrompt);
        self::assertStringContainsString(self::FRESH_SYSTEM_PROMPT, $input->systemPrompt);
        self::assertStringContainsString(self::FORK_SYSTEM_APPEND, $input->systemPrompt);
    }
}
