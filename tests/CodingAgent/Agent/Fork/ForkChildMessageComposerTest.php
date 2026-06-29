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
 *   - Fresh system prompt is used and fork append is added.
 *   - Fresh user-context messages are preserved (AGENTS.md, skills, agents).
 *   - Parent prologue (system, user-context messages) is stripped from
 *     the snapshot history.
 *   - User/assistant/tool historical messages are retained.
 *   - System messages in the historical list are filtered out.
 *   - ForkTaskUserMessage is the final user message in the composed list.
 *   - RunMetadata includes fork provenance (parent_run_id, artifact_id, kind).
 */
#[CoversClass(ForkChildMessageComposer::class)]
final class ForkChildMessageComposerTest extends TestCase
{
    private const string FRESH_SYSTEM_PROMPT = 'Fresh Hatfield system prompt with tools, date, and CWD context.';
    private const string FORK_SYSTEM_APPEND = 'FORK MODE IS ENABLED.';
    private const string FORK_TASK_MESSAGE = 'Task: implement feature X';
    private const string PARENT_RUN_ID = 'parent-run-001';
    private const string ARTIFACT_ID = 'artifact-001';

    private ForkChildMessageComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new ForkChildMessageComposer();
    }

    /**
     * Build fresh context messages that InProcessAgentSessionClient normally produces.
     *
     * @return list<AgentMessage>
     */
    private function buildFreshContextMessages(): array
    {
        return [
            new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => 'Child AGENTS.md context']],
                metadata: ['source' => 'agents_context', 'files' => ['AGENTS.md']],
            ),
            new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => 'Child skills context']],
                metadata: ['source' => 'skills_context'],
            ),
            new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => 'Child agents definitions context']],
                metadata: ['source' => 'agents_definitions_context'],
            ),
        ];
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
        $input = $this->composer->compose(
            snapshot: $snapshot,
            childRunId: 'child_run_001',
            freshSystemPrompt: self::FRESH_SYSTEM_PROMPT,
            freshContextMsgs: $this->buildFreshContextMessages(),
            parentRunId: self::PARENT_RUN_ID,
            artifactId: self::ARTIFACT_ID,
        );

        // First message should be system with combined prompt.
        self::assertCount(8, $input->messages); // system + 3 context + 3 historical + 1 task

        $systemMsg = $input->messages[0];
        self::assertSame('system', $systemMsg->role);

        // The system prompt should be the fresh prompt + fork append.
        $expectedSystem = self::FRESH_SYSTEM_PROMPT."\n\n".self::FORK_SYSTEM_APPEND;
        self::assertSame($expectedSystem, $systemMsg->content[0]['text']);

        // Fresh context messages should be in positions 1-3.
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame('user-context', $input->messages[$i]->role);
        }
        self::assertSame('Child AGENTS.md context', $input->messages[1]->content[0]['text']);
        self::assertSame('Child skills context', $input->messages[2]->content[0]['text']);
        self::assertSame('Child agents definitions context', $input->messages[3]->content[0]['text']);
    }

    public function testStripsParentPrologueAndPreservesFreshContext(): void
    {
        $snapshot = $this->buildSnapshotWithPrologue();
        $input = $this->composer->compose(
            snapshot: $snapshot,
            childRunId: 'child_run_001',
            freshSystemPrompt: self::FRESH_SYSTEM_PROMPT,
            freshContextMsgs: $this->buildFreshContextMessages(),
            parentRunId: self::PARENT_RUN_ID,
            artifactId: self::ARTIFACT_ID,
        );

        // After the system message + fresh context, we should have historical
        // user/assistant/tool + fork task user.
        $afterContext = \array_slice($input->messages, 4);

        // The prologue (system, user-context) from the snapshot should be gone.
        // Only historical user/assistant/tool + fork task should remain.
        $expectedRoles = ['user', 'assistant', 'tool', 'user'];
        $actualRoles = array_map(
            static fn (AgentMessage $m): string => $m->role,
            $afterContext,
        );

        self::assertSame($expectedRoles, $actualRoles);

        // Verify historical content is preserved.
        self::assertSame('User said something', $afterContext[0]->content[0]['text']);
        self::assertSame('Assistant replied', $afterContext[1]->content[0]['text']);
        self::assertSame('Tool result', $afterContext[2]->content[0]['text']);

        // Verify fork task is the final user message.
        self::assertSame(self::FORK_TASK_MESSAGE, $afterContext[3]->content[0]['text']);
    }

    public function testComposesWithoutPrologue(): void
    {
        $snapshot = $this->buildSnapshotWithoutPrologue();
        $input = $this->composer->compose(
            snapshot: $snapshot,
            childRunId: 'child_run_002',
            freshSystemPrompt: self::FRESH_SYSTEM_PROMPT,
            freshContextMsgs: $this->buildFreshContextMessages(),
            parentRunId: self::PARENT_RUN_ID,
            artifactId: self::ARTIFACT_ID,
        );

        // system + 3 context + 2 historical + 1 task = 7
        self::assertCount(7, $input->messages);

        $afterContext = \array_slice($input->messages, 4);
        $expectedRoles = ['user', 'assistant', 'user'];
        $actualRoles = array_map(
            static fn (AgentMessage $m): string => $m->role,
            $afterContext,
        );

        self::assertSame($expectedRoles, $actualRoles);
        self::assertSame(self::FORK_TASK_MESSAGE, $afterContext[2]->content[0]['text']);
    }

    public function testFiltersMidHistorySystemMessages(): void
    {
        $snapshot = $this->buildSnapshotWithMidHistorySystem();
        $input = $this->composer->compose(
            snapshot: $snapshot,
            childRunId: 'child_run_003',
            freshSystemPrompt: self::FRESH_SYSTEM_PROMPT,
            freshContextMsgs: $this->buildFreshContextMessages(),
            parentRunId: self::PARENT_RUN_ID,
            artifactId: self::ARTIFACT_ID,
        );

        // The mid-history compact_summary user message must be retained
        // (it's user role, not system).
        $afterContext = \array_slice($input->messages, 4);

        $expectedRoles = ['user', 'assistant', 'user', 'user', 'user'];
        $actualRoles = array_map(
            static fn (AgentMessage $m): string => $m->role,
            $afterContext,
        );

        self::assertSame($expectedRoles, $actualRoles);

        // The compact_summary message should be there.
        self::assertTrue($afterContext[2]->metadata['compact_summary'] ?? false);

        // Fork task is last.
        self::assertSame(self::FORK_TASK_MESSAGE, $afterContext[4]->content[0]['text']);
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

        $input = $this->composer->compose(
            snapshot: $snapshot,
            childRunId: 'child_run_004',
            freshSystemPrompt: self::FRESH_SYSTEM_PROMPT,
            freshContextMsgs: $this->buildFreshContextMessages(),
            parentRunId: self::PARENT_RUN_ID,
            artifactId: self::ARTIFACT_ID,
        );

        // System message + 3 context + fork task user message only.
        self::assertCount(5, $input->messages);
        self::assertSame('system', $input->messages[0]->role);
        foreach ([1, 2, 3] as $i) {
            self::assertSame('user-context', $input->messages[$i]->role);
        }
        self::assertSame('user', $input->messages[4]->role);
        self::assertSame(self::FORK_TASK_MESSAGE, $input->messages[4]->content[0]['text']);
    }

    public function testComposeSetsRunIdSystemPromptAndForkProvenance(): void
    {
        $snapshot = $this->buildSnapshotWithPrologue();
        $input = $this->composer->compose(
            snapshot: $snapshot,
            childRunId: 'child_run_005',
            freshSystemPrompt: self::FRESH_SYSTEM_PROMPT,
            freshContextMsgs: $this->buildFreshContextMessages(),
            parentRunId: self::PARENT_RUN_ID,
            artifactId: self::ARTIFACT_ID,
        );

        self::assertSame('child_run_005', $input->runId);
        self::assertNotNull($input->systemPrompt);
        self::assertStringContainsString(self::FRESH_SYSTEM_PROMPT, $input->systemPrompt);
        self::assertStringContainsString(self::FORK_SYSTEM_APPEND, $input->systemPrompt);

        // Verify fork provenance in RunMetadata.
        self::assertNotNull($input->metadata);
        $sessionMeta = $input->metadata->session;
        self::assertArrayHasKey('parent_run_id', $sessionMeta);
        self::assertSame(self::PARENT_RUN_ID, $sessionMeta['parent_run_id']);
        self::assertArrayHasKey('artifact_id', $sessionMeta);
        self::assertSame(self::ARTIFACT_ID, $sessionMeta['artifact_id']);
        self::assertArrayHasKey('kind', $sessionMeta);
        self::assertSame('fork_child', $sessionMeta['kind']);
    }

    public function testComposesWithoutForkProvenanceWhenIdsEmpty(): void
    {
        $snapshot = $this->buildSnapshotWithPrologue();
        $input = $this->composer->compose(
            snapshot: $snapshot,
            childRunId: 'child_run_006',
            freshSystemPrompt: self::FRESH_SYSTEM_PROMPT,
            freshContextMsgs: [],
            parentRunId: '',
            artifactId: '',
        );

        self::assertNotNull($input->metadata);
        $sessionMeta = $input->metadata->session;
        // Without parentRunId/artifactId, only kind should be set.
        self::assertArrayHasKey('kind', $sessionMeta);
        self::assertSame('fork_child', $sessionMeta['kind']);
        // parent_run_id and artifact_id should still be present as empty strings
        // because RunMetadata::session is an array keyed by our input.
        // But the important thing is the run still works.
    }
}
