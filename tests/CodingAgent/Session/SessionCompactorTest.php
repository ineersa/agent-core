<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Session\CompactResultDTO;
use Ineersa\CodingAgent\Session\CompactionPreparationDTO;
use Ineersa\CodingAgent\Session\SessionCompactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionCompactor::class)]
#[CoversClass(CompactionPreparationDTO::class)]
#[CoversClass(CompactResultDTO::class)]
#[CoversClass(CompactionConfig::class)]
final class SessionCompactorTest extends TestCase
{
    private SessionCompactor $compactor;
    private CompactionConfig $settings;

    protected function setUp(): void
    {
        $this->compactor = new SessionCompactor();
        // Use a small keep_recent_tokens so we can test with short message lists.
        $this->settings = new CompactionConfig(
            enabled: true,
            reserveTokens: 16384,
            keepRecentTokens: 200, // small to trigger compaction on short lists
            maxSummaryTokens: null,
            model: null,
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeMessage(string $role, string $text, array $extra = []): AgentMessage
    {
        return new AgentMessage(
            role: $role,
            content: [['type' => 'text', 'text' => $text]],
            toolCallId: $extra['toolCallId'] ?? null,
            toolName: $extra['toolName'] ?? null,
            metadata: $extra['metadata'] ?? [],
        );
    }

    private function makeAssistantWithToolCalls(array $toolCallIds): AgentMessage
    {
        $toolCalls = [];

        foreach ($toolCallIds as $id) {
            $toolCalls[] = [
                'id' => $id,
                'type' => 'function',
                'function' => ['name' => 'some_tool', 'arguments' => '{}'],
            ];
        }

        return new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Calling tools...']],
            metadata: ['tool_calls' => $toolCalls],
        );
    }

    private function makeToolResult(string $toolCallId): AgentMessage
    {
        return new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => 'Result for ' . $toolCallId]],
            toolCallId: $toolCallId,
            toolName: 'some_tool',
        );
    }

    /**
     * Create a long synthetic conversation that exceeds keepRecentTokens.
     *
     * Pattern: alternating user/assistant pairs with verbose content.
     * Returns messages that will trigger compaction.
     */
    private function makeLongConversation(int $pairs = 20): array
    {
        $messages = [];

        for ($i = 0; $i < $pairs; ++$i) {
            $messages[] = $this->makeMessage(
                'user',
                'This is a long user message number ' . $i . '. ' . \str_repeat('padding ', 20),
            );
            $messages[] = $this->makeMessage(
                'assistant',
                'This is a long assistant message number ' . $i . '. ' . \str_repeat('response padding ', 20),
            );
        }

        return $messages;
    }

    // ── prepare(): no-op conditions ──────────────────────────────────

    /**
     * Thesis: prepare() returns null when compaction is disabled.
     */
    public function testPrepareReturnsNullWhenDisabled(): void
    {
        $disabledSettings = new CompactionConfig(enabled: false);
        $messages = $this->makeLongConversation(50);

        $result = $this->compactor->prepare($messages, $disabledSettings);

        self::assertNull($result);
    }

    /**
     * Thesis: prepare() returns null for very short sessions (0 or 1 message).
     */
    public function testPrepareReturnsNullForShortSessions(): void
    {
        $result0 = $this->compactor->prepare([], $this->settings);
        self::assertNull($result0);

        $result1 = $this->compactor->prepare(
            [$this->makeMessage('user', 'hello')],
            $this->settings,
        );
        self::assertNull($result1);
    }

    /**
     * Thesis: prepare() returns null when the session fits within keepRecentTokens.
     */
    public function testPrepareReturnsNullWhenWithinBudget(): void
    {
        // Two small messages fit in 200 token budget.
        $messages = [
            $this->makeMessage('user', 'hi'),
            $this->makeMessage('assistant', 'hello'),
        ];

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNull($result);
    }

    // ── prepare(): long session partitions ───────────────────────────

    /**
     * Thesis: For a long conversation, prepare() returns non-null partitions
     * with correct counts, indexes, and token estimate.
     */
    public function testPrepareProducesPartitionsForLongSession(): void
    {
        $messages = $this->makeLongConversation(30);
        $total = \count($messages); // 60 messages

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result, 'Should produce preparation for long session');
        self::assertGreaterThan(0, $result->messagesCompacted, 'Should compact some messages');
        self::assertGreaterThan(0, $result->messagesRetained, 'Should retain some messages');
        self::assertSame($total, $result->messagesCompacted + $result->messagesRetained, 'All messages accounted for');
        self::assertSame($result->messagesCompacted, $result->firstRetainedIndex, 'First retained index matches compacted count');
        self::assertGreaterThan(0, $result->tokenEstimateBefore, 'Token estimate before should be positive');
        self::assertSameSize($result->messagesToSummarize, range(0, $result->messagesCompacted - 1), 'messagesToSummarize count matches');
        self::assertSameSize($result->retainedTailMessages, range(0, $result->messagesRetained - 1), 'retainedTailMessages count matches');
    }

    /**
     * Thesis: The first message in retainedTailMessages should be the message
     * at firstRetainedIndex in the original list.
     */
    public function testRetainedTailMatchesOriginalContinuity(): void
    {
        $messages = $this->makeLongConversation(30);

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result);
        // The first retained message should equal the original at firstRetainedIndex.
        self::assertSame(
            $messages[$result->firstRetainedIndex],
            $result->retainedTailMessages[0],
        );
    }

    // ── Prior compact summary detection ──────────────────────────────

    /**
     * Thesis: prepare() detects a prior compact summary among messagesToSummarize.
     */
    public function testPriorCompactSummaryDetected(): void
    {
        // Build a long conversation with a compact summary in the middle.
        $summaryMsg = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Previous summary...']],
            metadata: ['compact_summary' => true],
        );

        // Put the summary early in the conversation so it ends up in messagesToSummarize.
        $messages = [
            $this->makeMessage('user', 'Start'),
            $this->makeMessage('assistant', 'Response 1'),
            $summaryMsg,
        ];

        // Add enough padding to exceed keepRecentTokens.
        $messages = \array_merge($messages, $this->makeLongConversation(20));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result);
        self::assertTrue($result->priorSummaryPresent, 'Should detect prior compact summary');
    }

    /**
     * Thesis: prepare() reports priorSummaryPresent=false for a clean conversation
     * with no prior compact summary.
     */
    public function testPriorCompactSummaryNotDetected(): void
    {
        $messages = $this->makeLongConversation(30);

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result);
        self::assertFalse($result->priorSummaryPresent);
    }

    // ── Safe cut: user boundary ─────────────────────────────────────

    /**
     * Thesis: When the only safe boundaries are before assistant messages,
     * the algorithm falls back to an assistant-text boundary (no user
     * boundary available).
     */
    public function testAssistantTextBoundaryWhenNoUserBoundary(): void
    {
        // Create conversation where the last several messages are all assistant.
        $messages = [];

        // Put user messages early.
        for ($i = 0; $i < 5; ++$i) {
            $messages[] = $this->makeMessage('user', 'Question ' . $i . ' ' . \str_repeat('pad ', 20));
            $messages[] = $this->makeMessage('assistant', 'Answer ' . $i . ' ' . \str_repeat('pad ', 20));
        }

        // Then many consecutive assistant messages (no user boundary nearby).
        for ($i = 0; $i < 10; ++$i) {
            $messages[] = $this->makeMessage('assistant', 'Follow-up ' . $i . ' ' . \str_repeat('pad ', 20));
        }

        $result = $this->compactor->prepare($messages, $this->settings);

        // Should still produce a valid preparation — cutting before an
        // assistant message when no user boundary is available.
        self::assertNotNull($result);
        self::assertGreaterThan(0, $result->messagesCompacted);
        self::assertGreaterThan(0, $result->messagesRetained);
    }

    // ── Safe cut: assistant tool-call groups ─────────────────────────

    /**
     * Thesis: An assistant tool-call message and its tool results are
     * retained together — the cut never splits them.
     */
    public function testToolCallGroupRetainedTogether(): void
    {
        $messages = $this->makeLongConversation(8);

        // Add a tool-call group near the end.
        $messages[] = $this->makeAssistantWithToolCalls(['call_1', 'call_2']);
        $messages[] = $this->makeToolResult('call_1');
        $messages[] = $this->makeToolResult('call_2');

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result, 'Tool-call group near end should still produce a compaction');

        // The assistant tool-call message must be in the retained tail.
        $retainedRoles = \array_map(static fn (AgentMessage $m): string => $m->role, $result->retainedTailMessages);

        // The tool-call group is placed at the end of the conversation
        // and should be present, complete, in the retained tail.
        self::assertContains('assistant', $retainedRoles, 'Assistant tool-call expected in retained tail');
        self::assertContains('tool', $retainedRoles, 'Tool results expected in retained tail');

        // Verify the specific tool-call group is intact.
        $retainedCallIds = [];
        foreach ($result->retainedTailMessages as $msg) {
            if ('tool' === $msg->role) {
                $retainedCallIds[] = $msg->toolCallId;
            }

            if ('assistant' === $msg->role) {
                foreach ($this->extractToolCallIdsFromMessage($msg) as $id) {
                    $retainedCallIds[] = $id;
                }
            }
        }

        self::assertContains('call_1', $retainedCallIds, 'call_1 must be in retained tail');
        self::assertContains('call_2', $retainedCallIds, 'call_2 must be in retained tail');
    }

    /**
     * Thesis: No orphan tool result is retained — every tool result
     * in the retained tail has its assistant tool call also retained.
     */
    public function testNoOrphanToolResult(): void
    {
        // Build messages where a tool-call group is entirely in the summarize zone.
        $messages = [];
        $messages[] = $this->makeAssistantWithToolCalls(['orphan_call']);
        $messages[] = $this->makeToolResult('orphan_call');

        // Add padding after to push boundary.
        $messages = \array_merge($messages, $this->makeLongConversation(15));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result, 'Orphan early in history should still produce compaction');

        // The retained tail should never contain a tool result whose
        // assistant tool call was in the summarize partition.
        foreach ($result->retainedTailMessages as $msg) {
            if ('tool' === $msg->role && 'orphan_call' === $msg->toolCallId) {
                self::fail('Orphan tool result retained — its assistant call was summarized away');
            }
        }
    }

    /**
     * Thesis: No assistant tool-call message is summarized away while
     * its tool results are retained.
     */
    public function testNoSummarizedAssistantWithRetainedToolResults(): void
    {
        // Build a tool-call group near the middle, after some history.
        $messages = $this->makeLongConversation(5);
        $messages[] = $this->makeAssistantWithToolCalls(['split_call']);
        $messages[] = $this->makeToolResult('split_call');

        // Add more padding.
        $messages = \array_merge($messages, $this->makeLongConversation(15));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result, 'Compaction should succeed with tool-call group in middle');

        // Check that split_call is not in messagesToSummarize while its
        // result is in retainedTailMessages.
        $summarizeIds = [];

        foreach ($result->messagesToSummarize as $msg) {
            $toolCalls = $msg->metadata['tool_calls'] ?? null;

            if (\is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    if (isset($tc['id'])) {
                        $summarizeIds[$tc['id']] = true;
                    }
                }
            }
        }

        foreach ($result->retainedTailMessages as $msg) {
            if ('tool' === $msg->role && null !== $msg->toolCallId) {
                self::assertArrayNotHasKey(
                    $msg->toolCallId,
                    $summarizeIds,
                    "Tool result {$msg->toolCallId} retained but its assistant call was summarized away",
                );
            }
        }
    }

    /**
     * Thesis: When a tool-call group spans the boundary, the algorithm
     * moves the boundary earlier so the entire group is retained.
     */
    public function testToolCallGroupMovesBoundaryEarlier(): void
    {
        // Place a tool-call group right at where the boundary would be.
        $messages = $this->makeLongConversation(8);

        // Assistant with tool call → tool result → then more conversation to push boundary near.
        $messages[] = $this->makeAssistantWithToolCalls(['boundary_call']);
        $messages[] = $this->makeToolResult('boundary_call');
        $messages[] = $this->makeMessage('assistant', 'After tool ' . \str_repeat('pad ', 20));
        $messages[] = $this->makeMessage('user', 'Latest ' . \str_repeat('pad ', 20));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertNotNull($result, 'Compaction should succeed: boundary moved earlier rather than splitting group');

        // Verify the 'boundary_call' tool result is not in retain while
        // its assistant is in summarize, or vice versa.
        $foundInSummarize = false;
        $foundInRetain = false;

        foreach ($result->messagesToSummarize as $msg) {
            if ('tool' === $msg->role && 'boundary_call' === $msg->toolCallId) {
                $foundInSummarize = true;
            }

            $toolCalls = $msg->metadata['tool_calls'] ?? null;

            if (\is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    if (('boundary_call') === ($tc['id'] ?? null)) {
                        $foundInSummarize = true;
                    }
                }
            }
        }

        foreach ($result->retainedTailMessages as $msg) {
            if ('tool' === $msg->role && 'boundary_call' === $msg->toolCallId) {
                $foundInRetain = true;
            }

            $toolCalls = $msg->metadata['tool_calls'] ?? null;

            if (\is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    if (('boundary_call') === ($tc['id'] ?? null)) {
                        $foundInRetain = true;
                    }
                }
            }
        }

        // If found in both partitions, it means the group was split — invalid.
        self::assertFalse(
            $foundInSummarize && $foundInRetain,
            'Tool-call group was split across partitions',
        );
    }

    // ── Prompt text ──────────────────────────────────────────────────

    /**
     * Thesis: The summarization system message matches the plan exact text.
     * Asserted through buildSummarizationMessages() output, not a test-only accessor.
     */
    public function testSummarizationSystemMessageExact(): void
    {
        $messages = $this->makeLongConversation(15);
        $prep = $this->compactor->prepare($messages, $this->settings);
        self::assertNotNull($prep);

        $result = $this->compactor->buildSummarizationMessages($prep, null);

        $expected = "You are a context summarization assistant. Read the conversation and produce only a handoff summary.\n\nDo not continue the conversation. Do not answer questions from the conversation. Do not call tools. Output only the summary text.";
        self::assertSame($expected, $result[0]->content[0]['text']);
    }

    /**
     * Thesis: The summarization user prompt matches the plan exact text.
     * Asserted through buildSummarizationMessages() output, not a test-only accessor.
     */
    public function testSummarizationUserPromptExact(): void
    {
        $messages = $this->makeLongConversation(15);
        $prep = $this->compactor->prepare($messages, $this->settings);
        self::assertNotNull($prep);

        $result = $this->compactor->buildSummarizationMessages($prep, null);
        $last = \count($result) - 1;

        $expected = "You are performing a CONTEXT CHECKPOINT COMPACTION. Create a handoff summary for another LLM that will resume the task.\n\nInclude:\n- Current progress and key decisions made\n- Important context, constraints, or user preferences\n- What remains to be done (clear next steps)\n- Any critical data, examples, file paths, commands, errors, or references needed to continue\n\nIf a prior compaction summary exists in the conversation, incorporate it and preserve still-relevant facts.\n\nBe concise, structured, and focused on helping the next LLM seamlessly continue the work.";
        self::assertSame($expected, $result[$last]->content[0]['text']);
    }

    // ── buildSummarizationMessages() ─────────────────────────────────

    /**
     * Thesis: buildSummarizationMessages returns [system, ...toSummarize, user] format
     * with the exact prompt texts embedded.
     */
    public function testBuildSummarizationMessagesStructure(): void
    {
        $messages = $this->makeLongConversation(20);

        $preparation = $this->compactor->prepare($messages, $this->settings);
        self::assertNotNull($preparation);

        $result = $this->compactor->buildSummarizationMessages($preparation, null);

        // First message is system.
        self::assertSame('system', $result[0]->role);
        self::assertStringContainsString(
            'context summarization assistant',
            $result[0]->content[0]['text'],
        );

        // Middle messages are the summarize partition.
        $middleCount = \count($result) - 2; // exclude system and user
        self::assertCount($preparation->messagesCompacted, \array_slice($result, 1, $middleCount));

        // Last message is user prompt.
        $lastIndex = \count($result) - 1;
        self::assertSame('user', $result[$lastIndex]->role);
        self::assertStringContainsString(
            'CONTEXT CHECKPOINT COMPACTION',
            $result[$lastIndex]->content[0]['text'],
        );
    }

    /**
     * Thesis: Custom instructions are appended to the user prompt.
     */
    public function testBuildSummarizationMessagesWithCustomInstructions(): void
    {
        $messages = $this->makeLongConversation(20);

        $preparation = $this->compactor->prepare($messages, $this->settings);
        self::assertNotNull($preparation);

        $result = $this->compactor->buildSummarizationMessages(
            $preparation,
            'summarize only database decisions',
        );
        $lastIndex = \count($result) - 1;
        $promptText = $result[$lastIndex]->content[0]['text'];

        self::assertStringContainsString(
            'summarize only database decisions',
            $promptText,
        );
        self::assertStringContainsString(
            'Additional user instructions for this compaction:',
            $promptText,
        );
    }

    /**
     * Thesis: buildSummarizationMessages with empty/whitespace custom instructions
     * does not append the instructions block.
     */
    public function testBuildSummarizationMessagesEmptyCustomInstructions(): void
    {
        $messages = $this->makeLongConversation(20);

        $preparation = $this->compactor->prepare($messages, $this->settings);
        self::assertNotNull($preparation);

        $result = $this->compactor->buildSummarizationMessages($preparation, '   ');
        $lastIndex = \count($result) - 1;
        $promptText = $result[$lastIndex]->content[0]['text'];

        self::assertStringNotContainsString(
            'Additional user instructions',
            $promptText,
        );
    }

    // ── buildCompactedMessages() ────────────────────────────────────

    /**
     * Thesis: buildCompactedMessages produces a summary message with
     * correct role, metadata, prefix/suffix, and message order.
     */
    public function testBuildCompactedMessagesStructure(): void
    {
        $messages = $this->makeLongConversation(20);

        $preparation = $this->compactor->prepare($messages, $this->settings);
        self::assertNotNull($preparation);

        $result = $this->compactor->buildCompactedMessages(
            'This is the summary text.',
            $preparation,
        );

        // Summary message properties.
        self::assertSame('user', $result->summaryMessage->role);
        self::assertTrue(
            ($result->summaryMessage->metadata['compact_summary'] ?? false) === true,
            'Summary message should have compact_summary metadata',
        );
        self::assertStringContainsString(
            'The conversation history before this point was compacted',
            $result->summaryMessage->content[0]['text'],
        );
        self::assertStringContainsString(
            'This is the summary text.',
            $result->summaryMessage->content[0]['text'],
        );
        self::assertStringContainsString(
            '</summary>',
            $result->summaryMessage->content[0]['text'],
        );

        // Compacted messages = [summaryMessage, ...retainedTail].
        self::assertCount(
            $preparation->messagesRetained + 1, // +1 for summary
            $result->compactedMessages,
        );
        self::assertSame($result->summaryMessage, $result->compactedMessages[0]);
        self::assertSame(
            $preparation->retainedTailMessages,
            \array_slice($result->compactedMessages, 1),
        );

        // Token estimates.
        self::assertSame($preparation->tokenEstimateBefore, $result->tokenEstimateBefore);
        self::assertGreaterThan(0, $result->tokenEstimateAfter);
        self::assertGreaterThan(
            $result->tokenEstimateAfter,
            $result->tokenEstimateBefore,
            'Token estimate after should be less than before',
        );

        // Counts carry through.
        self::assertSame($preparation->messagesCompacted, $result->messagesCompacted);
        self::assertSame($preparation->messagesRetained, $result->messagesRetained);
        self::assertSame($preparation->firstRetainedIndex, $result->firstRetainedIndex);
    }

    /**
     * Thesis: The summary prefix text tells the model it's prior context, not a new request.
     */
    public function testSummaryPrefixExplicitlyMarksAsContext(): void
    {
        $messages = $this->makeLongConversation(20);

        $preparation = $this->compactor->prepare($messages, $this->settings);
        self::assertNotNull($preparation);

        $result = $this->compactor->buildCompactedMessages('Test summary', $preparation);

        $text = $result->summaryMessage->content[0]['text'];

        self::assertStringContainsString(
            'The conversation history before this point was compacted',
            $text,
        );
        self::assertStringContainsString(
            'Use it as prior context, not as a new user request',
            $text,
        );
        self::assertStringContainsString('<summary>', $text);
        self::assertStringContainsString('</summary>', $text);
    }

    // ── Partition validity via AgentMessageToolCallSequenceValidator ─

    /**
     * Thesis: When the retained tail has an unclosed assistant tool-call
     * batch that no boundary walk can resolve, prepare() returns null.
     *
     * Construction: the last message is an assistant with tool_calls and
     * no following tool result.  Every possible boundary includes this
     * message in the retained tail (boundary=0 is rejected by
     * findSafeBoundary).  Cross-boundary checks pass because no tool
     * results exist at all, but isValidSequence rejects every retained
     * tail ending with an unclosed batch.
     */
    public function testUnclosedBatchInRetainedTailReturnsNull(): void
    {
        // Keep recent tokens small so even short messages trigger compaction.
        $tightSettings = new CompactionConfig(
            enabled: true,
            keepRecentTokens: 50,
        );

        // Padded user/assistant pairs that each exceed 50 tokens.
        $messages = [];
        for ($i = 0; $i < 4; ++$i) {
            $messages[] = $this->makeMessage(
                'user',
                \str_repeat('x', 200),
            );
            $messages[] = $this->makeMessage(
                'assistant',
                \str_repeat('y', 200),
            );
        }

        // Last message: assistant with unclosed tool calls.
        $messages[] = $this->makeAssistantWithToolCalls(['unclosed_tc']);

        $result = $this->compactor->prepare($messages, $tightSettings);

        self::assertNull(
            $result,
            'prepare() should return null: retained tail always contains unclosed batch',
        );
    }

    /**
     * Thesis: An unclosed assistant tool-call batch in the summarize prefix
     * is not accepted as a safe cut — partition validity rejects it even
     * when cross-boundary checks pass.
     *
     * Construction: [valid-prefix, assistant(tc), user-breaking, tool(tc), tail…]
     * The user-breaking message sits between the assistant tool call and its
     * result, making the summarize prefix invalid when cut after tool(tc).
     * Cross-boundary passes (no tool result in retain from summarize), but
     * isValidSequence on the summarize prefix catches the unclosed batch
     * at the user-breaking message.
     */
    public function testUnclosedBatchInSummarizePrefixNotSafe(): void
    {
        $tightSettings = new CompactionConfig(
            enabled: true,
            keepRecentTokens: 60,
        );

        // Valid prefix.
        $messages = [];
        $messages[] = $this->makeMessage('user', 'start');
        $messages[] = $this->makeMessage('assistant', 'ok');

        // Block that creates the invalid summarize prefix when cut after
        // the tool message: assistant opens tc1, user breaks open batch,
        // tool result for tc1.
        $messages[] = $this->makeAssistantWithToolCalls(['tc1']);
        $messages[] = $this->makeMessage('user', 'breaking open batch');
        $messages[] = $this->makeToolResult('tc1');

        // Padding: messages large enough to push total above keepRecentTokens.
        for ($i = 0; $i < 3; ++$i) {
            $messages[] = $this->makeMessage(
                'assistant',
                \str_repeat('pad', 30),
            );
        }

        $result = $this->compactor->prepare($messages, $tightSettings);

        // Every boundary >= 1 produces an invalid partition (either
        // summarize-prefix unclosed batch or retain-tail unclosed
        // batch) and boundary=0 is rejected by findSafeBoundary.
        self::assertNull(
            $result,
            'prepare() should return null: no boundary produces two valid partitions',
        );
    }

    /**
     * Thesis: A valid assistant tool-call + tool result group is still
     * retained together after the partition validity strengthening —
     * both partitions pass isValidSequence.
     */
    public function testValidToolCallGroupRetainedTogetherAccepted(): void
    {
        $tightSettings = new CompactionConfig(
            enabled: true,
            keepRecentTokens: 200,
        );

        // Prefix padding.
        $messages = [];
        for ($i = 0; $i < 10; ++$i) {
            $messages[] = $this->makeMessage(
                'user',
                \str_repeat('history-', 30),
            );
            $messages[] = $this->makeMessage(
                'assistant',
                \str_repeat('response-', 30),
            );
        }

        // Tool-call group near the end — full open/close.
        $messages[] = $this->makeAssistantWithToolCalls(['group_tc1', 'group_tc2']);
        $messages[] = $this->makeToolResult('group_tc1');
        $messages[] = $this->makeToolResult('group_tc2');

        $result = $this->compactor->prepare($messages, $tightSettings);

        self::assertNotNull(
            $result,
            'Valid tool-call group should not block compaction',
        );

        // If the tool-call group ends up in the retained tail, it must be
        // complete (all three messages present).
        $retained = $result->retainedTailMessages;
        $foundAssistant = false;
        $foundTc1 = false;
        $foundTc2 = false;

        foreach ($retained as $msg) {
            if ('assistant' === $msg->role) {
                $tcIds = $this->extractToolCallIdsFromMessage($msg);

                if (\in_array('group_tc1', $tcIds, true)) {
                    $foundAssistant = true;
                }
            }

            if ('tool' === $msg->role && 'group_tc1' === $msg->toolCallId) {
                $foundTc1 = true;
            }

            if ('tool' === $msg->role && 'group_tc2' === $msg->toolCallId) {
                $foundTc2 = true;
            }
        }

        // Either the entire group is retained or none of it is.
        if ($foundAssistant || $foundTc1 || $foundTc2) {
            self::assertTrue(
                $foundAssistant && $foundTc1 && $foundTc2,
                'Tool-call group must be retained together or not at all',
            );
        }
    }

    /**
     * Thesis: An orphan retained tool result with a call ID remains unsafe
     * under partition validation — prepare() does not accept a boundary
     * that splits the assistant from its tool result.
     *
     * Construction: [assistant(tc), tool(tc), ...padded-history...]
     * The tool-call group is entirely in the summarize zone.  Any orphan
     * tool result in the retained tail with a call ID and no matching
     * assistant is rejected by cross-boundary checks.
     */
    public function testOrphanRetainedToolResultUnsafe(): void
    {
        $tightSettings = new CompactionConfig(
            enabled: true,
            keepRecentTokens: 200,
        );

        // A tool-call group entirely in the early part of history.
        $messages = [];
        $messages[] = $this->makeAssistantWithToolCalls(['orphan_tc']);
        $messages[] = $this->makeToolResult('orphan_tc');

        // Padded history to push the boundary beyond the early group.
        for ($i = 0; $i < 15; ++$i) {
            $messages[] = $this->makeMessage(
                'user',
                \str_repeat('x', 200),
            );
            $messages[] = $this->makeMessage(
                'assistant',
                \str_repeat('y', 200),
            );
        }

        $result = $this->compactor->prepare($messages, $tightSettings);

        // Compaction should succeed: the orphan group is entirely early
        // and doesn't prevent finding a safe boundary.
        self::assertNotNull($result, 'Compaction should still succeed with orphan tool group early in history');

        foreach ($result->retainedTailMessages as $msg) {
            self::assertFalse(
                'tool' === $msg->role && 'orphan_tc' === $msg->toolCallId,
                'Orphan tool result must not appear in retained tail',
            );
        }
    }

    /**
     * Extract tool_call_ids from a message for test assertions.
     *
     * @return list<string>
     */
    private function extractToolCallIdsFromMessage(AgentMessage $message): array
    {
        $toolCalls = $message->metadata['tool_calls'] ?? null;

        if (!\is_array($toolCalls)) {
            return [];
        }

        $ids = [];

        foreach ($toolCalls as $tc) {
            if (\is_array($tc) && \is_string($tc['id'] ?? null) && '' !== $tc['id']) {
                $ids[] = $tc['id'];
            }
        }

        return $ids;
    }
}
