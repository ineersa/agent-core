<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\CodingAgent\Agent\Fork\DefaultForkSnapshotSummaryProvider;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotCompactor;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ForkSnapshotCompactor.
 *
 * Test thesis:
 *   - No compaction when messages fit within the token budget.
 *   - Compacts retained tail when over budget and carries forward a
 *     prior compact_summary summary.
 *   - Never mutates the input array.
 *   - Respects safe boundary (no split tool-call/tool-result groups).
 */
#[CoversClass(ForkSnapshotCompactor::class)]
#[CoversClass(DefaultForkSnapshotSummaryProvider::class)]
final class ForkSnapshotCompactorTest extends TestCase
{
    private ForkSnapshotCompactor $compactor;

    protected function setUp(): void
    {
        $tokenEstimator = new CompactionTokenEstimator();
        $sequenceValidator = new AgentMessageToolCallSequenceValidator();
        $boundarySelector = new CompactionBoundarySelector($tokenEstimator, $sequenceValidator);
        $summaryProvider = new DefaultForkSnapshotSummaryProvider();

        $this->compactor = new ForkSnapshotCompactor($boundarySelector, $summaryProvider);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function userMessage(string $content): AgentMessage
    {
        return new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $content]],
        );
    }

    private function assistantMessage(string $content, array $toolCalls = []): AgentMessage
    {
        $metadata = [];
        if ([] !== $toolCalls) {
            $metadata['tool_calls'] = $toolCalls;
        }

        return new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => $content]],
            metadata: $metadata,
        );
    }

    private function toolMessage(string $toolCallId, string $content): AgentMessage
    {
        return new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $content]],
            toolCallId: $toolCallId,
        );
    }

    // ── Tests ────────────────────────────────────────────────────────────

    public function testNoCompactionWhenUnderBudget(): void
    {
        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi!'),
        ];

        $result = $this->compactor->compact($messages, 50000);

        self::assertFalse($result->compacted);
        self::assertCount(2, $result->messages);
        self::assertNull($result->summaryText);
        self::assertSame(0, $result->summarizedCount);
    }

    public function testCompactionWhenOverBudget(): void
    {
        // Include a prior compact_summary so the default summary provider
        // can carry it forward (NOOP provider only reuses existing summaries).
        $priorSummary = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Prior session summary for context.']],
            metadata: ['compact_summary' => true],
        );

        // Create enough messages to exceed the budget.
        $messages = [$priorSummary];
        for ($i = 0; $i < 20; ++$i) {
            $messages[] = $this->userMessage("This is a long user message number {$i} with plenty of text to consume token budget quickly. " . \str_repeat('x', 40));
            $messages[] = $this->assistantMessage("This is assistant response number {$i} that also contains substantial text to take up token space. " . \str_repeat('y', 40));
        }

        $budget = 500; // Very small budget to force compaction.

        $result = $this->compactor->compact($messages, $budget);

        self::assertTrue($result->compacted);
        self::assertGreaterThan(0, \count($result->messages));
        self::assertIsString($result->summaryText);
        self::assertGreaterThan(0, $result->summarizedCount);
    }

    public function testDoesNotMutateInput(): void
    {
        $messages = [
            $this->userMessage('Message one'),
            $this->assistantMessage('Response one'),
            $this->userMessage('Message two'),
            $this->assistantMessage('Response two'),
        ];

        $originalCount = \count($messages);

        $this->compactor->compact($messages, 50);

        self::assertCount($originalCount, $messages);
    }

    public function testCompactionWithPriorCompactSummary(): void
    {
        // Build messages with a prior compact_summary message and enough
        // content to exceed a very tight budget.
        $summaryMessage = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Previous conversation summary that should be carried forward.']],
            metadata: ['compact_summary' => true],
        );

        $allMessages = [
            $summaryMessage,
            $this->userMessage('Old message with lots of padding ' . \str_repeat('x', 200)),
            $this->assistantMessage('Old response with lots of padding ' . \str_repeat('y', 200)),
            $this->userMessage('More old content ' . \str_repeat('z', 200)),
            $this->assistantMessage('More old response ' . \str_repeat('w', 200)),
        ];

        $result = $this->compactor->compact($allMessages, 100);

        // Should compact because budget is tight and messages are long.
        self::assertTrue($result->compacted, 'Expected compaction to occur with tight budget and long messages');

        // First message should be the newly created compact_summary carrying forward
        // the prior summary text.
        self::assertTrue($result->messages[0]->metadata['compact_summary'] ?? false);
        self::assertStringContainsString('Previous conversation summary', $result->messages[0]->content[0]['text']);
    }

    public function testEmptyInput(): void
    {
        $result = $this->compactor->compact([], 50000);

        self::assertFalse($result->compacted);
        self::assertCount(0, $result->messages);
    }

    public function testSingleMessageFits(): void
    {
        $messages = [$this->userMessage('Just one message')];

        $result = $this->compactor->compact($messages, 50000);

        self::assertFalse($result->compacted);
        self::assertCount(1, $result->messages);
    }

    public function testSafeBoundaryRespected(): void
    {
        // Build messages where a naive cut would split a tool-call group.
        // We want the tool-call batch to be in the "retained" portion but
        // complete (not split).  Create many old messages to force
        // compaction, then a complete tool-call group near the end.
        $priorSummary = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Prior session summary.']],
            metadata: ['compact_summary' => true],
        );

        $messages = [$priorSummary];
        for ($i = 0; $i < 15; ++$i) {
            $messages[] = $this->userMessage("Old user turn {$i} with lots of text for token consumption " . \str_repeat('a', 60)); // very long
            $messages[] = $this->assistantMessage("Old assistant turn {$i} " . \str_repeat('b', 60));
        }

        // Add a complete tool-call group (assistant with tool_calls → tool results).
        $messages[] = $this->assistantMessage('Using tool', [
            ['id' => 'call_tool_1', 'name' => 'test_tool', 'arguments' => ['arg' => 'value']],
        ]);
        $messages[] = $this->toolMessage('call_tool_1', 'Tool result data');
        $messages[] = $this->assistantMessage('Tool completed');
        $messages[] = $this->userMessage('Continue with conversation');

        // With a very tight budget, compaction should find a safe boundary
        // that does NOT split the tool-call group.
        $result = $this->compactor->compact($messages, 100);

        self::assertTrue($result->compacted, 'Expected compaction to occur for the safe-boundary fixture');

        // Verify no tool-call group is split.
        if ($result->compacted) {
            // The retained tail should not contain orphan tool messages.
            $toolCallIds = [];
            $foundOrphan = false;

            foreach ($result->messages as $msg) {
                if ('assistant' === $msg->role) {
                    $extracted = AgentMessageToolCallSequenceValidator::extractToolCallIds($msg);
                    foreach ($extracted as $id) {
                        $toolCallIds[$id] = true;
                    }
                }

                if ('tool' === $msg->role) {
                    $tid = $msg->toolCallId;
                    if (null !== $tid && '' !== $tid && !isset($toolCallIds[$tid])) {
                        $foundOrphan = true;
                    }
                }

                // User/system message resets the batch.
                if ('user' === $msg->role && [] !== $toolCallIds) {
                    // Check if this user appears within an open batch
                    // (this would be a split). For this test, we just
                    // assert no orphan tool messages.
                }
            }

            self::assertFalse($foundOrphan, 'Found orphan tool message — safe boundary was violated');
        }
    }

    public function testCompactSummaryMessageStructure(): void
    {
        // Include a prior compact_summary so the default summary provider
        // can carry it forward.
        $priorSummary = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Prior session summary for context.']],
            metadata: ['compact_summary' => true],
        );

        // Force compaction with many messages and a very small budget.
        $messages = [$priorSummary];
        for ($i = 0; $i < 20; ++$i) {
            $messages[] = $this->userMessage("Long old message {$i} that takes up token budget aggressively. " . \str_repeat('x', 50));
            $messages[] = $this->assistantMessage("Long old response {$i} that also chews through the token budget. " . \str_repeat('y', 50));
        }

        $result = $this->compactor->compact($messages, 200);

        if ($result->compacted) {
            // The first message must be a user message with compact_summary metadata.
            self::assertSame('user', $result->messages[0]->role);
            self::assertTrue($result->messages[0]->metadata['compact_summary'] ?? false);
            self::assertStringContainsString('<summary>', $result->messages[0]->content[0]['text']);
            self::assertStringContainsString('</summary>', $result->messages[0]->content[0]['text']);
        }
    }
}
