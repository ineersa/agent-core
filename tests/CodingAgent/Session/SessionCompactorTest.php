<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\CompactResultDTO;
use Ineersa\CodingAgent\Session\CompactionPreparationDTO;
use Ineersa\CodingAgent\Session\CompactionPreparationResultDTO;
use Ineersa\CodingAgent\Session\CompactionPromptBuilder;
use Ineersa\CodingAgent\Session\CompactionSkipReasonEnum;
use Ineersa\CodingAgent\Session\SessionCompactor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

#[CoversClass(SessionCompactor::class)]
#[CoversClass(CompactionPreparationDTO::class)]
#[CoversClass(CompactResultDTO::class)]
#[CoversClass(CompactionPreparationResultDTO::class)]
#[CoversClass(CompactionSkipReasonEnum::class)]
#[CoversClass(CompactionConfig::class)]
#[CoversClass(CompactionPromptBuilder::class)]
final class SessionCompactorTest extends TestCase
{
    private SessionCompactor $compactor;
    private CompactionConfig $settings;
    private string $projectDir;
    private string $homeDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('compaction-test');
        $this->homeDir = TestDirectoryIsolation::createOsTempDir('compaction-test-home');

        // Ensure the built-in COMPACTION.md is available for the prompt builder.
        $configDir = $this->projectDir.'/config';
        TestDirectoryIsolation::ensureDirectory($configDir);

        $builtinPath = $configDir.'/COMPACTION.md';
        // Create a minimal template for tests.
        file_put_contents($builtinPath, "Test compaction prompt.\n\n{date}\n{cwd}{custom_instructions_part}");

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );

        // SettingsPathResolver is final — use a real instance with test dirs.
        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);

        $templateRenderer = new StringTemplateRenderer();

        $promptBuilder = new CompactionPromptBuilder(
            $pathResolver,
            $templateRenderer,
            $appConfig,
            $this->projectDir,
        );

        $this->compactor = new SessionCompactor($promptBuilder);
        $this->settings = new CompactionConfig(
            autoEnabled: true,
            keepRecentTokens: 200,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->projectDir)) {
            TestDirectoryIsolation::removeDirectory($this->projectDir);
        }

        if (isset($this->homeDir)) {
            TestDirectoryIsolation::removeDirectory($this->homeDir);
        }

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeMessage(string $role, string $text, array $extra = []): AgentMessage
    {
        return new AgentMessage(
            role: $role,
            content: [['type' => 'text', 'text' => $text]],
            toolCallId: $extra['toolCallId'] ?? null,
            toolName: $extra['toolName'] ?? null,
            details: $extra['details'] ?? null,
            isError: $extra['isError'] ?? false,
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
            content: [['type' => 'text', 'text' => 'Result for '.$toolCallId]],
            toolCallId: $toolCallId,
            toolName: 'some_tool',
        );
    }

    private function makeLongConversation(int $pairs = 20): array
    {
        $messages = [];

        for ($i = 0; $i < $pairs; ++$i) {
            $messages[] = $this->makeMessage(
                'user',
                'This is a long user message number '.$i.'. '.\str_repeat('padding ', 20),
            );
            $messages[] = $this->makeMessage(
                'assistant',
                'This is a long assistant message number '.$i.'. '.\str_repeat('response padding ', 20),
            );
        }

        return $messages;
    }

    // ── prepare(): skip reasons ──────────────────────────────────────

    /**
     * Thesis: prepare() returns TooFewMessages skip reason for 0 or 1 message.
     */
    public function testPrepareReturnsTooFewMessages(): void
    {
        $result0 = $this->compactor->prepare([], $this->settings);
        self::assertFalse($result0->isReady());
        self::assertSame(CompactionSkipReasonEnum::TooFewMessages, $result0->skipReason);

        $result1 = $this->compactor->prepare(
            [$this->makeMessage('user', 'hello')],
            $this->settings,
        );
        self::assertFalse($result1->isReady());
        self::assertSame(CompactionSkipReasonEnum::TooFewMessages, $result1->skipReason);
    }

    /**
     * Thesis: prepare() returns BelowKeepRecentTokens when session fits budget.
     */
    public function testPrepareReturnsBelowKeepRecentTokens(): void
    {
        $messages = [
            $this->makeMessage('user', 'hi'),
            $this->makeMessage('assistant', 'hello'),
        ];

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertFalse($result->isReady());
        self::assertSame(CompactionSkipReasonEnum::BelowKeepRecentTokens, $result->skipReason);
    }

    // ── prepare(): long session partitions ───────────────────────────

    /**
     * Thesis: For a long conversation, prepare() returns a ready result
     * with correct counts, indexes, and token estimate.
     */
    public function testPrepareProducesPartitionsForLongSession(): void
    {
        $messages = $this->makeLongConversation(30);
        $total = \count($messages);

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady(), 'Should produce preparation for long session');
        $prep = $result->preparation;
        self::assertNotNull($prep);
        self::assertGreaterThan(0, $prep->messagesCompacted, 'Should compact some messages');
        self::assertGreaterThan(0, $prep->messagesRetained, 'Should retain some messages');
        self::assertSame($total, $prep->messagesCompacted + $prep->messagesRetained, 'All messages accounted for');
        self::assertSame($prep->messagesCompacted, $prep->firstRetainedIndex, 'First retained index matches compacted count');
        self::assertGreaterThan(0, $prep->tokenEstimateBefore, 'Token estimate before should be positive');
        self::assertSameSize($prep->messagesToSummarize, range(0, $prep->messagesCompacted - 1));
        self::assertSameSize($prep->retainedTailMessages, range(0, $prep->messagesRetained - 1));
    }

    /**
     * Thesis: The first message in retainedTailMessages matches original at firstRetainedIndex.
     */
    public function testRetainedTailMatchesOriginalContinuity(): void
    {
        $messages = $this->makeLongConversation(30);

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);
        self::assertSame(
            $messages[$prep->firstRetainedIndex],
            $prep->retainedTailMessages[0],
        );
    }

    // ── Prior compact summary detection ──────────────────────────────

    /**
     * Thesis: prepare() detects a prior compact summary among messagesToSummarize.
     */
    public function testPriorCompactSummaryDetected(): void
    {
        $summaryMsg = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Previous summary...']],
            metadata: ['compact_summary' => true],
        );

        $messages = [
            $this->makeMessage('user', 'Start'),
            $this->makeMessage('assistant', 'Response 1'),
            $summaryMsg,
        ];

        $messages = \array_merge($messages, $this->makeLongConversation(20));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);
        self::assertTrue($prep->priorSummaryPresent, 'Should detect prior compact summary');
    }

    /**
     * Thesis: prepare() reports priorSummaryPresent=false for clean conversation.
     */
    public function testPriorCompactSummaryNotDetected(): void
    {
        $messages = $this->makeLongConversation(30);

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);
        self::assertFalse($prep->priorSummaryPresent);
    }

    // ── Safe cut: user boundary ─────────────────────────────────────

    /**
     * Thesis: When no user boundary exists, the algorithm falls back to
     * an assistant-text boundary.
     */
    public function testAssistantTextBoundaryWhenNoUserBoundary(): void
    {
        $messages = [];

        for ($i = 0; $i < 5; ++$i) {
            $messages[] = $this->makeMessage('user', 'Question '.$i.' '.\str_repeat('pad ', 20));
            $messages[] = $this->makeMessage('assistant', 'Answer '.$i.' '.\str_repeat('pad ', 20));
        }

        for ($i = 0; $i < 10; ++$i) {
            $messages[] = $this->makeMessage('assistant', 'Follow-up '.$i.' '.\str_repeat('pad ', 20));
        }

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);
        self::assertGreaterThan(0, $prep->messagesCompacted);
        self::assertGreaterThan(0, $prep->messagesRetained);
    }

    // ── Safe cut: assistant tool-call groups ─────────────────────────

    /**
     * Thesis: An assistant tool-call message and its tool results are
     * retained together — the cut never splits them.
     */
    public function testToolCallGroupRetainedTogether(): void
    {
        $messages = $this->makeLongConversation(8);

        $messages[] = $this->makeAssistantWithToolCalls(['call_1', 'call_2']);
        $messages[] = $this->makeToolResult('call_1');
        $messages[] = $this->makeToolResult('call_2');

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady(), 'Tool-call group near end should still produce a compaction');
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $retainedRoles = \array_map(static fn (AgentMessage $m): string => $m->role, $prep->retainedTailMessages);

        self::assertContains('assistant', $retainedRoles, 'Assistant tool-call expected in retained tail');
        self::assertContains('tool', $retainedRoles, 'Tool results expected in retained tail');

        $retainedCallIds = [];
        foreach ($prep->retainedTailMessages as $msg) {
            if ('tool' === $msg->role) {
                $retainedCallIds[] = $msg->toolCallId;
            }

            if ('assistant' === $msg->role) {
                foreach (AgentMessageToolCallSequenceValidator::extractToolCallIds($msg) as $id) {
                    $retainedCallIds[] = $id;
                }
            }
        }

        self::assertContains('call_1', $retainedCallIds, 'call_1 must be in retained tail');
        self::assertContains('call_2', $retainedCallIds, 'call_2 must be in retained tail');
    }

    /**
     * Thesis: No orphan tool result is retained.
     */
    public function testNoOrphanToolResult(): void
    {
        $messages = [];
        $messages[] = $this->makeAssistantWithToolCalls(['orphan_call']);
        $messages[] = $this->makeToolResult('orphan_call');

        $messages = \array_merge($messages, $this->makeLongConversation(15));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady(), 'Orphan early in history should still produce compaction');
        $prep = $result->preparation;
        self::assertNotNull($prep);

        foreach ($prep->retainedTailMessages as $msg) {
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
        $messages = $this->makeLongConversation(5);
        $messages[] = $this->makeAssistantWithToolCalls(['split_call']);
        $messages[] = $this->makeToolResult('split_call');

        $messages = \array_merge($messages, $this->makeLongConversation(15));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $summarizeIds = [];

        foreach ($prep->messagesToSummarize as $msg) {
            $toolCalls = $msg->metadata['tool_calls'] ?? null;

            if (\is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    if (isset($tc['id'])) {
                        $summarizeIds[$tc['id']] = true;
                    }
                }
            }
        }

        foreach ($prep->retainedTailMessages as $msg) {
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
     * Thesis: Tool-call group spanning boundary moves boundary earlier.
     */
    public function testToolCallGroupMovesBoundaryEarlier(): void
    {
        $messages = $this->makeLongConversation(8);

        $messages[] = $this->makeAssistantWithToolCalls(['boundary_call']);
        $messages[] = $this->makeToolResult('boundary_call');
        $messages[] = $this->makeMessage('assistant', 'After tool '.\str_repeat('pad ', 20));
        $messages[] = $this->makeMessage('user', 'Latest '.\str_repeat('pad ', 20));

        $result = $this->compactor->prepare($messages, $this->settings);

        self::assertTrue($result->isReady(), 'Compaction should succeed: boundary moved earlier');
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $foundInSummarize = false;
        $foundInRetain = false;

        foreach ($prep->messagesToSummarize as $msg) {
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

        foreach ($prep->retainedTailMessages as $msg) {
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

        self::assertFalse(
            $foundInSummarize && $foundInRetain,
            'Tool-call group was split across partitions',
        );
    }

    // ── buildSummarizationMessages() ─────────────────────────────────

    /**
     * Thesis: buildSummarizationMessages returns [...digestedMessages, userPrompt]
     * with the prompt rendered from COMPACTION.md.
     */
    public function testBuildSummarizationMessagesStructure(): void
    {
        $messages = $this->makeLongConversation(20);

        $result = $this->compactor->prepare($messages, $this->settings);
        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages($prep, null);

        // Last message is the user prompt.
        $last = \count($summarizationMessages) - 1;
        self::assertSame('user', $summarizationMessages[$last]->role);

        // The prompt should contain rendered template content (date, cwd, etc.)
        $promptText = $summarizationMessages[$last]->content[0]['text'];
        self::assertStringContainsString((string) date('Y'), $promptText, 'Prompt should contain current year');

        // Messages before the prompt are the digested summarize partition.
        $digestedCount = \count($summarizationMessages) - 1; // all except the prompt
        self::assertCount($prep->messagesCompacted, \array_slice($summarizationMessages, 0, $digestedCount));
    }

    /**
     * Thesis: Custom instructions are injected into the rendered prompt.
     */
    public function testBuildSummarizationMessagesWithCustomInstructions(): void
    {
        $messages = $this->makeLongConversation(20);

        $result = $this->compactor->prepare($messages, $this->settings);
        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages(
            $prep,
            'summarize only database decisions',
        );
        $last = \count($summarizationMessages) - 1;
        $promptText = $summarizationMessages[$last]->content[0]['text'];

        self::assertStringContainsString('summarize only database decisions', $promptText);
        self::assertStringContainsString('Additional user instructions for this compaction:', $promptText);
    }

    /**
     * Thesis: Empty/whitespace custom instructions don't append instructions block.
     */
    public function testBuildSummarizationMessagesEmptyCustomInstructions(): void
    {
        $messages = $this->makeLongConversation(20);

        $result = $this->compactor->prepare($messages, $this->settings);
        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages($prep, '   ');
        $last = \count($summarizationMessages) - 1;
        $promptText = $summarizationMessages[$last]->content[0]['text'];

        self::assertStringNotContainsString('Additional user instructions', $promptText);
    }

    // ── Token estimation (model-facing text, no JSON) ─────────────────

    /**
     * Thesis: estimateTokens counts only model-facing text, not JSON/metadata.
     */
    public function testEstimateTokensIsTextOnly(): void
    {
        $msg = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'hello world']],
            metadata: ['compact_summary' => true, 'large_key' => \str_repeat('x', 1000)],
        );

        $tokens = $this->compactor->estimateTokens([$msg]);

        // ~11 chars for "hello world" → ceil(11/3.25) = 4 tokens
        // If JSON was included, it would be hundreds.
        self::assertLessThan(10, $tokens, 'Token estimate should be text-only, not JSON-envelope');
    }

    /**
     * Thesis: A custom-role message includes the [role] prefix in estimation.
     */
    public function testEstimateTokensCustomRole(): void
    {
        $msg = new AgentMessage(
            role: 'custom_role',
            content: [['type' => 'text', 'text' => 'hello']],
        );

        $tokens = $this->compactor->estimateTokens([$msg]);

        // '[custom_role] hello' ≈ 20 chars → ceil(20/3.25) ≈ 7
        self::assertGreaterThan(3, $tokens, 'Custom role prefix adds to token estimate');
        self::assertLessThan(15, $tokens);
    }

    /**
     * Thesis: A message with no text content estimates to 0 tokens.
     */
    public function testEstimateTokensEmptyContent(): void
    {
        $msg = new AgentMessage(
            role: 'assistant',
            content: [],
        );

        $tokens = $this->compactor->estimateTokens([$msg]);

        self::assertSame(0, $tokens);
    }

    // ── Tool-result digest ───────────────────────────────────────────

    /**
     * Thesis: Tool results in the summarize partition are replaced with
     * deterministic digest placeholders in buildSummarizationMessages output.
     */
    public function testToolResultsAreDigested(): void
    {
        $messages = $this->makeLongConversation(8);

        // Add a tool call + result pair.
        $messages[] = $this->makeAssistantWithToolCalls(['digest_test']);
        $messages[] = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => 'Large tool output: '.\str_repeat('data ', 500)]],
            toolCallId: 'digest_test',
            toolName: 'bash',
        );

        // Add padding to trigger compaction.
        $messages = \array_merge($messages, $this->makeLongConversation(10));

        $result = $this->compactor->prepare($messages, $this->settings);
        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages($prep, null);

        // Find tool messages in the digest partition (before the prompt).
        $foundDigest = false;
        $lastPrompt = \count($summarizationMessages) - 1;

        for ($i = 0; $i < $lastPrompt; ++$i) {
            $msg = $summarizationMessages[$i];

            if ('tool' !== $msg->role) {
                continue;
            }

            $text = $msg->content[0]['text'] ?? '';
            self::assertStringContainsString('[Tool result:', $text, 'Tool message should be digested');
            self::assertStringContainsString('tool_call_id:', $text);
            self::assertStringContainsString('estimated tokens:', $text);
            self::assertStringContainsString('char count:', $text);

            // The digest is a placeholder — original full text is truncated.
            // The preview snippet may include the start of the original output.
            self::assertStringContainsString('estimated tokens:', $text);
            self::assertStringContainsString('char count:', $text);
            self::assertStringContainsString('--- content preview ---', $text);
            self::assertStringContainsString('--- end preview ---', $text);

            $foundDigest = true;

            break;
        }

        self::assertTrue($foundDigest, 'Should find at least one digested tool message');
    }

    /**
     * Thesis: Non-tool messages in the summarize partition pass through
     * unchanged in buildSummarizationMessages.
     */
    public function testNonToolMessagesAreNotDigested(): void
    {
        $messages = $this->makeLongConversation(12);

        // Add a known user message early that will be in summarize partition.
        $marker = $this->makeMessage('user', 'MARKER: important user context');
        array_unshift($messages, $marker);

        $result = $this->compactor->prepare($messages, $this->settings);
        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages($prep, null);

        $foundMarker = false;
        $lastPrompt = \count($summarizationMessages) - 1;

        for ($i = 0; $i < $lastPrompt; ++$i) {
            $msg = $summarizationMessages[$i];
            $text = $msg->content[0]['text'] ?? '';

            if ('user' === $msg->role && str_contains($text, 'MARKER: important user context')) {
                $foundMarker = true;

                break;
            }
        }

        self::assertTrue($foundMarker, 'User message marker should pass through undigested');
    }

    // ── buildCompactedMessages() ────────────────────────────────────

    /**
     * Thesis: buildCompactedMessages produces a summary message with
     * correct role, metadata, prefix/suffix, and message order.
     */
    public function testBuildCompactedMessagesStructure(): void
    {
        $messages = $this->makeLongConversation(20);

        $result = $this->compactor->prepare($messages, $this->settings);
        self::assertTrue($result->isReady());
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $compacted = $this->compactor->buildCompactedMessages(
            'This is the summary text.',
            $prep,
        );

        self::assertSame('user', $compacted->summaryMessage->role);
        self::assertTrue(
            (bool) ($compacted->summaryMessage->metadata['compact_summary'] ?? false),
            'Summary message should have compact_summary metadata',
        );
        self::assertStringContainsString(
            'The conversation history before this point was compacted',
            $compacted->summaryMessage->content[0]['text'],
        );
        self::assertStringContainsString('This is the summary text.', $compacted->summaryMessage->content[0]['text']);
        self::assertStringContainsString('</summary>', $compacted->summaryMessage->content[0]['text']);

        self::assertCount($prep->messagesRetained + 1, $compacted->compactedMessages);
        self::assertSame($compacted->summaryMessage, $compacted->compactedMessages[0]);
        self::assertSame($prep->retainedTailMessages, \array_slice($compacted->compactedMessages, 1));

        self::assertSame($prep->tokenEstimateBefore, $compacted->tokenEstimateBefore);
        self::assertGreaterThan(0, $compacted->tokenEstimateAfter);
        self::assertGreaterThan(
            $compacted->tokenEstimateAfter,
            $compacted->tokenEstimateBefore,
            'Token estimate after should be less than before',
        );

        self::assertSame($prep->messagesCompacted, $compacted->messagesCompacted);
        self::assertSame($prep->messagesRetained, $compacted->messagesRetained);
        self::assertSame($prep->firstRetainedIndex, $compacted->firstRetainedIndex);
    }

    // ── Partition validity ───────────────────────────────────────────

    /**
     * Thesis: Unclosed assistant tool-call batch in retained tail makes
     * prepare() return NoSafeBoundary.
     */
    public function testUnclosedBatchInRetainedTailReturnsNull(): void
    {
        $tightSettings = new CompactionConfig(
            autoEnabled: true,
            keepRecentTokens: 50,
        );

        $messages = [];
        for ($i = 0; $i < 4; ++$i) {
            $messages[] = $this->makeMessage('user', \str_repeat('x', 200));
            $messages[] = $this->makeMessage('assistant', \str_repeat('y', 200));
        }

        $messages[] = $this->makeAssistantWithToolCalls(['unclosed_tc']);

        $result = $this->compactor->prepare($messages, $tightSettings);

        self::assertFalse($result->isReady());
        self::assertSame(CompactionSkipReasonEnum::NoSafeBoundary, $result->skipReason);
    }

    /**
     * Thesis: Unclosed batch in summarize prefix not accepted as safe cut.
     */
    public function testUnclosedBatchInSummarizePrefixNotSafe(): void
    {
        $tightSettings = new CompactionConfig(
            autoEnabled: true,
            keepRecentTokens: 60,
        );

        $messages = [];
        $messages[] = $this->makeMessage('user', 'start');
        $messages[] = $this->makeMessage('assistant', 'ok');
        $messages[] = $this->makeAssistantWithToolCalls(['tc1']);
        $messages[] = $this->makeMessage('user', 'breaking open batch');
        $messages[] = $this->makeToolResult('tc1');

        for ($i = 0; $i < 3; ++$i) {
            $messages[] = $this->makeMessage('assistant', \str_repeat('pad', 30));
        }

        $result = $this->compactor->prepare($messages, $tightSettings);

        self::assertFalse($result->isReady());
        self::assertSame(CompactionSkipReasonEnum::NoSafeBoundary, $result->skipReason);
    }

    /**
     * Thesis: Valid tool-call group retained together still accepted
     * after partition validity strengthening.
     */
    public function testValidToolCallGroupRetainedTogetherAccepted(): void
    {
        $tightSettings = new CompactionConfig(
            autoEnabled: true,
            keepRecentTokens: 200,
        );

        $messages = [];
        for ($i = 0; $i < 10; ++$i) {
            $messages[] = $this->makeMessage('user', \str_repeat('history-', 30));
            $messages[] = $this->makeMessage('assistant', \str_repeat('response-', 30));
        }

        $messages[] = $this->makeAssistantWithToolCalls(['group_tc1', 'group_tc2']);
        $messages[] = $this->makeToolResult('group_tc1');
        $messages[] = $this->makeToolResult('group_tc2');

        $result = $this->compactor->prepare($messages, $tightSettings);

        self::assertTrue($result->isReady(), 'Valid tool-call group should not block compaction');
        $prep = $result->preparation;
        self::assertNotNull($prep);

        $retained = $prep->retainedTailMessages;
        $foundAssistant = false;
        $foundTc1 = false;
        $foundTc2 = false;

        foreach ($retained as $msg) {
            if ('assistant' === $msg->role) {
                $tcIds = AgentMessageToolCallSequenceValidator::extractToolCallIds($msg);

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

        if ($foundAssistant || $foundTc1 || $foundTc2) {
            self::assertTrue(
                $foundAssistant && $foundTc1 && $foundTc2,
                'Tool-call group must be retained together or not at all',
            );
        }
    }

    /**
     * Thesis: Orphan retained tool result is unsafe — prepare() succeeds
     * with orphan group early in history.
     */
    public function testOrphanRetainedToolResultUnsafe(): void
    {
        $tightSettings = new CompactionConfig(
            autoEnabled: true,
            keepRecentTokens: 200,
        );

        $messages = [];
        $messages[] = $this->makeAssistantWithToolCalls(['orphan_tc']);
        $messages[] = $this->makeToolResult('orphan_tc');

        for ($i = 0; $i < 15; ++$i) {
            $messages[] = $this->makeMessage('user', \str_repeat('x', 200));
            $messages[] = $this->makeMessage('assistant', \str_repeat('y', 200));
        }

        $result = $this->compactor->prepare($messages, $tightSettings);

        self::assertTrue($result->isReady(), 'Compaction should succeed with orphan tool group early in history');
        $prep = $result->preparation;
        self::assertNotNull($prep);

        foreach ($prep->retainedTailMessages as $msg) {
            self::assertFalse(
                'tool' === $msg->role && 'orphan_tc' === $msg->toolCallId,
                'Orphan tool result must not appear in retained tail',
            );
        }
    }
}
