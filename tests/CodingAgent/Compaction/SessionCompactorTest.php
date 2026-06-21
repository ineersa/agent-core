<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Compaction\CompactionPreparationDTO;
use Ineersa\CodingAgent\Compaction\CompactionPreparationResultDTO;
use Ineersa\CodingAgent\Compaction\CompactionPromptBuilder;
use Ineersa\CodingAgent\Compaction\CompactionSkipReasonEnum;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Compaction\CompactResultDTO;
use Ineersa\CodingAgent\Compaction\SessionCompactor;
use Ineersa\CodingAgent\Compaction\ToolResultDigestService;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
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
#[CoversClass(CompactionBoundarySelector::class)]
#[CoversClass(ToolResultDigestService::class)]
#[CoversClass(CompactionTokenEstimator::class)]
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

        $tokenEstimator = new CompactionTokenEstimator();
        $digestService = new ToolResultDigestService($tokenEstimator);
        $boundarySelector = new CompactionBoundarySelector(
            $tokenEstimator,
            new AgentMessageToolCallSequenceValidator(),
        );

        $this->compactor = new SessionCompactor(
            $tokenEstimator,
            $digestService,
            $boundarySelector,
            $promptBuilder,
        );
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

    // ── prepare(): skip reasons ──────────────────────────────────────

    /**
     * Thesis: prepare() returns TooFewMessages skip reason for 0 or 1 message.
     */
    public function testPrepareReturnsTooFewMessages(): void
    {
        $result0 = $this->compactor->prepare([], $this->settings);
        $this->assertFalse($result0->isReady());
        $this->assertSame(CompactionSkipReasonEnum::TooFewMessages, $result0->skipReason);

        $result1 = $this->compactor->prepare(
            [$this->makeMessage('user', 'hello')],
            $this->settings,
        );
        $this->assertFalse($result1->isReady());
        $this->assertSame(CompactionSkipReasonEnum::TooFewMessages, $result1->skipReason);
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

        $this->assertFalse($result->isReady());
        $this->assertSame(CompactionSkipReasonEnum::BelowKeepRecentTokens, $result->skipReason);
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

        $this->assertTrue($result->isReady(), 'Should produce preparation for long session');
        $prep = $result->preparation;
        $this->assertNotNull($prep);
        $this->assertGreaterThan(0, $prep->messagesCompacted, 'Should compact some messages');
        $this->assertGreaterThan(0, $prep->messagesRetained, 'Should retain some messages');
        $this->assertSame($total, $prep->messagesCompacted + $prep->messagesRetained, 'All messages accounted for');
        $this->assertSame($prep->messagesCompacted, $prep->firstRetainedIndex, 'First retained index matches compacted count');
        $this->assertGreaterThan(0, $prep->tokenEstimateBefore, 'Token estimate before should be positive');
        $this->assertSameSize($prep->messagesToSummarize, range(0, $prep->messagesCompacted - 1));
        $this->assertSameSize($prep->retainedTailMessages, range(0, $prep->messagesRetained - 1));
    }

    /**
     * Thesis: The first body message in retainedTailMessages matches
     * the original at firstRetainedIndex.  Prologue (if any) precedes
     * the body tail, so retainedTailMessages after the prologue prefix
     * matches messages starting at firstRetainedIndex.
     */
    public function testRetainedTailMatchesOriginalContinuity(): void
    {
        $messages = $this->makeLongConversation(30);

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        // Count leading prologue messages in retained tail.
        $prologueInRetained = 0;
        foreach ($prep->retainedTailMessages as $msg) {
            if ('system' === $msg->role || 'user-context' === $msg->role) {
                ++$prologueInRetained;
            } else {
                break;
            }
        }

        // The first body message in retainedTailMessages (after prologue)
        // should match the original at firstRetainedIndex.
        $this->assertSame(
            $messages[$prep->firstRetainedIndex],
            $prep->retainedTailMessages[$prologueInRetained],
            'First body message in retained tail must match original at firstRetainedIndex',
        );

        // firstRetainedIndex accounts for prologue offset.
        $this->assertGreaterThanOrEqual(
            $prologueInRetained,
            $prep->firstRetainedIndex,
            'firstRetainedIndex must be >= prologue count',
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

        $messages = array_merge($messages, $this->makeLongConversation(20));

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);
        $this->assertTrue($prep->priorSummaryPresent, 'Should detect prior compact summary');
    }

    /**
     * Thesis: prepare() reports priorSummaryPresent=false for clean conversation.
     */
    public function testPriorCompactSummaryNotDetected(): void
    {
        $messages = $this->makeLongConversation(30);

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);
        $this->assertFalse($prep->priorSummaryPresent);
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
            $messages[] = $this->makeMessage('user', 'Question '.$i.' '.str_repeat('pad ', 20));
            $messages[] = $this->makeMessage('assistant', 'Answer '.$i.' '.str_repeat('pad ', 20));
        }

        for ($i = 0; $i < 10; ++$i) {
            $messages[] = $this->makeMessage('assistant', 'Follow-up '.$i.' '.str_repeat('pad ', 20));
        }

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);
        $this->assertGreaterThan(0, $prep->messagesCompacted);
        $this->assertGreaterThan(0, $prep->messagesRetained);
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

        $this->assertTrue($result->isReady(), 'Tool-call group near end should still produce a compaction');
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        $retainedRoles = array_map(static fn (AgentMessage $m): string => $m->role, $prep->retainedTailMessages);

        $this->assertContains('assistant', $retainedRoles, 'Assistant tool-call expected in retained tail');
        $this->assertContains('tool', $retainedRoles, 'Tool results expected in retained tail');

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

        $this->assertContains('call_1', $retainedCallIds, 'call_1 must be in retained tail');
        $this->assertContains('call_2', $retainedCallIds, 'call_2 must be in retained tail');
    }

    /**
     * Thesis: No orphan tool result is retained.
     */
    public function testNoOrphanToolResult(): void
    {
        $messages = [];
        $messages[] = $this->makeAssistantWithToolCalls(['orphan_call']);
        $messages[] = $this->makeToolResult('orphan_call');

        $messages = array_merge($messages, $this->makeLongConversation(15));

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady(), 'Orphan early in history should still produce compaction');
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        foreach ($prep->retainedTailMessages as $msg) {
            if ('tool' === $msg->role && 'orphan_call' === $msg->toolCallId) {
                $this->fail('Orphan tool result retained — its assistant call was summarized away');
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

        $messages = array_merge($messages, $this->makeLongConversation(15));

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

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
                $this->assertArrayNotHasKey(
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
        $messages[] = $this->makeMessage('assistant', 'After tool '.str_repeat('pad ', 20));
        $messages[] = $this->makeMessage('user', 'Latest '.str_repeat('pad ', 20));

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady(), 'Compaction should succeed: boundary moved earlier');
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        $foundInSummarize = false;
        $foundInRetain = false;

        foreach ($prep->messagesToSummarize as $msg) {
            if ('tool' === $msg->role && 'boundary_call' === $msg->toolCallId) {
                $foundInSummarize = true;
            }

            $toolCalls = $msg->metadata['tool_calls'] ?? null;

            if (\is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    if ('boundary_call' === ($tc['id'] ?? null)) {
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
                    if ('boundary_call' === ($tc['id'] ?? null)) {
                        $foundInRetain = true;
                    }
                }
            }
        }

        $this->assertFalse(
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
        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages($prep, null);

        // Last message is the user prompt.
        $last = \count($summarizationMessages) - 1;
        $this->assertSame('user', $summarizationMessages[$last]->role);

        // The prompt should contain rendered template content (date, cwd, etc.)
        $promptText = $summarizationMessages[$last]->content[0]['text'];
        $this->assertStringContainsString((string) date('Y'), $promptText, 'Prompt should contain current year');

        // Messages before the prompt are the digested summarize partition.
        $digestedCount = \count($summarizationMessages) - 1; // all except the prompt
        $this->assertCount($prep->messagesCompacted, \array_slice($summarizationMessages, 0, $digestedCount));
    }

    /**
     * Thesis: Custom instructions are injected into the rendered prompt.
     */
    public function testBuildSummarizationMessagesWithCustomInstructions(): void
    {
        $messages = $this->makeLongConversation(20);

        $result = $this->compactor->prepare($messages, $this->settings);
        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages(
            $prep,
            'summarize only database decisions',
        );
        $last = \count($summarizationMessages) - 1;
        $promptText = $summarizationMessages[$last]->content[0]['text'];

        $this->assertStringContainsString('summarize only database decisions', $promptText);
        $this->assertStringContainsString('Additional user instructions for this compaction:', $promptText);
    }

    /**
     * Thesis: Empty/whitespace custom instructions don't append instructions block.
     */
    public function testBuildSummarizationMessagesEmptyCustomInstructions(): void
    {
        $messages = $this->makeLongConversation(20);

        $result = $this->compactor->prepare($messages, $this->settings);
        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        $summarizationMessages = $this->compactor->buildSummarizationMessages($prep, '   ');
        $last = \count($summarizationMessages) - 1;
        $promptText = $summarizationMessages[$last]->content[0]['text'];

        $this->assertStringNotContainsString('Additional user instructions', $promptText);
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
            content: [['type' => 'text', 'text' => 'Large tool output: '.str_repeat('data ', 500)]],
            toolCallId: 'digest_test',
            toolName: 'bash',
        );

        // Add padding to trigger compaction.
        $messages = array_merge($messages, $this->makeLongConversation(10));

        $result = $this->compactor->prepare($messages, $this->settings);
        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

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
            $this->assertStringContainsString('tool output elided before summarization', $text, 'Tool message should be digested');
            $this->assertStringContainsString('tool_call_id:', $text);
            $this->assertStringContainsString('estimated_tokens:', $text);
            $this->assertStringContainsString('char_count:', $text);

            // The digest is a placeholder — original full text is truncated.
            $this->assertStringContainsString('preview_start:', $text);
            $this->assertStringContainsString('preview_end:', $text);

            $foundDigest = true;

            break;
        }

        $this->assertTrue($foundDigest, 'Should find at least one digested tool message');
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
        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

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

        $this->assertTrue($foundMarker, 'User message marker should pass through undigested');
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
        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        $compacted = $this->compactor->buildCompactedMessages(
            'This is the summary text.',
            $prep,
        );

        $this->assertSame('user', $compacted->summaryMessage->role);
        $this->assertTrue(
            (bool) ($compacted->summaryMessage->metadata['compact_summary'] ?? false),
            'Summary message should have compact_summary metadata',
        );
        $this->assertStringContainsString(
            'The conversation history before this point was compacted',
            $compacted->summaryMessage->content[0]['text'],
        );
        $this->assertStringContainsString('This is the summary text.', $compacted->summaryMessage->content[0]['text']);
        $this->assertStringContainsString('</summary>', $compacted->summaryMessage->content[0]['text']);

        $this->assertCount($prep->messagesRetained + 1, $compacted->compactedMessages);
        $this->assertSame($compacted->summaryMessage, $compacted->compactedMessages[0]);
        $this->assertSame($prep->retainedTailMessages, \array_slice($compacted->compactedMessages, 1));

        $this->assertSame($prep->tokenEstimateBefore, $compacted->tokenEstimateBefore);
        $this->assertGreaterThan(0, $compacted->tokenEstimateAfter);
        $this->assertGreaterThan(
            $compacted->tokenEstimateAfter,
            $compacted->tokenEstimateBefore,
            'Token estimate after should be less than before',
        );

        $this->assertSame($prep->messagesCompacted, $compacted->messagesCompacted);
        $this->assertSame($prep->messagesRetained, $compacted->messagesRetained);
        $this->assertSame($prep->firstRetainedIndex, $compacted->firstRetainedIndex);
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
            $messages[] = $this->makeMessage('user', str_repeat('x', 200));
            $messages[] = $this->makeMessage('assistant', str_repeat('y', 200));
        }

        $messages[] = $this->makeAssistantWithToolCalls(['unclosed_tc']);

        $result = $this->compactor->prepare($messages, $tightSettings);

        $this->assertFalse($result->isReady());
        $this->assertSame(CompactionSkipReasonEnum::NoSafeBoundary, $result->skipReason);
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
            $messages[] = $this->makeMessage('assistant', str_repeat('pad', 30));
        }

        $result = $this->compactor->prepare($messages, $tightSettings);

        $this->assertFalse($result->isReady());
        $this->assertSame(CompactionSkipReasonEnum::NoSafeBoundary, $result->skipReason);
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
            $messages[] = $this->makeMessage('user', str_repeat('history-', 30));
            $messages[] = $this->makeMessage('assistant', str_repeat('response-', 30));
        }

        $messages[] = $this->makeAssistantWithToolCalls(['group_tc1', 'group_tc2']);
        $messages[] = $this->makeToolResult('group_tc1');
        $messages[] = $this->makeToolResult('group_tc2');

        $result = $this->compactor->prepare($messages, $tightSettings);

        $this->assertTrue($result->isReady(), 'Valid tool-call group should not block compaction');
        $prep = $result->preparation;
        $this->assertNotNull($prep);

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
            $this->assertTrue(
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
            $messages[] = $this->makeMessage('user', str_repeat('x', 200));
            $messages[] = $this->makeMessage('assistant', str_repeat('y', 200));
        }

        $result = $this->compactor->prepare($messages, $tightSettings);

        $this->assertTrue($result->isReady(), 'Compaction should succeed with orphan tool group early in history');
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        foreach ($prep->retainedTailMessages as $msg) {
            $this->assertFalse(
                'tool' === $msg->role && 'orphan_tc' === $msg->toolCallId,
                'Orphan tool result must not appear in retained tail',
            );
        }
    }

    // ── Immutable prologue ──────────────────────────────────────────

    /**
     * Thesis: Leading system and user-context messages are retained
     * unchanged at the front and excluded from the summarization partition.
     * Without the prologue fix, system/user-context would be included in
     * messagesToSummarize and removed from compacted output.
     */
    public function testPrologueRetainedAndExcludedFromSummarization(): void
    {
        $systemMsg = $this->makeMessage('system', 'You are a coding assistant.');
        $contextMsg = $this->makeMessage('user-context', '<skills_instructions>...</skills_instructions>');

        $messages = [
            $systemMsg,
            $contextMsg,
        ];
        $messages = array_merge($messages, $this->makeLongConversation(20));

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertTrue($result->isReady(), 'Should produce preparation');
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        // Prologue must NOT be in the summarization partition.
        foreach ($prep->messagesToSummarize as $msg) {
            $this->assertNotSame(
                'system',
                $msg->role,
                'System message must never appear in messagesToSummarize',
            );
            $this->assertNotSame(
                'user-context',
                $msg->role,
                'User-context message must never appear in messagesToSummarize',
            );
        }

        // Prologue must be at the front of retainedTailMessages.
        $this->assertSame('system', $prep->retainedTailMessages[0]->role);
        $this->assertSame('user-context', $prep->retainedTailMessages[1]->role);

        // firstRetainedIndex reflects the global index (prologue + body boundary).
        // Since prologue is at indices 0-1, first body message retained is at
        // index 2 + bodySafeBoundary.
        $prologueCount = 2;
        $this->assertGreaterThanOrEqual(
            $prologueCount,
            $prep->firstRetainedIndex,
            'firstRetainedIndex must be at least prologue count',
        );

        // messagesCompacted counts only body messages summarized away
        // (not prologue).  firstRetainedIndex > messagesCompacted when
        // prologue exists.
        $this->assertLessThan(
            $prep->firstRetainedIndex,
            $prep->messagesCompacted,
            'messagesCompacted must be less than firstRetainedIndex when prologue exists',
        );

        // All messages accounted for.
        $totalMessages = \count($messages);
        $this->assertSame(
            $totalMessages,
            $prep->messagesCompacted + $prep->messagesRetained,
            'All messages accounted for (compacted + retained = total)',
        );
    }

    /**
     * Thesis: When messages consist only of immutable prologue,
     * prepare() returns TooFewMessages (nothing compactable).
     */
    public function testPrologueOnlyReturnsTooFewMessages(): void
    {
        $messages = [
            $this->makeMessage('system', 'You are a coding assistant.'),
            $this->makeMessage('user-context', '<skills>...</skills>'),
        ];

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertFalse($result->isReady());
        $this->assertSame(CompactionSkipReasonEnum::TooFewMessages, $result->skipReason);
    }

    /**
     * Thesis: When the compactable body fits within keepRecentTokens,
     * prepare() returns BelowKeepRecentTokens even if total messages
     * (including prologue) exceed the budget.
     */
    public function testPrologueWithShortBodyReturnsBelowKeepRecentTokens(): void
    {
        $messages = [
            $this->makeMessage('system', 'You are a coding assistant.'),
            $this->makeMessage('user-context', '<skills_instructions>...</skills_instructions>'),
            $this->makeMessage('user', 'hi'),
            $this->makeMessage('assistant', 'hello'),
        ];

        $result = $this->compactor->prepare($messages, $this->settings);

        $this->assertFalse($result->isReady());
        $this->assertSame(CompactionSkipReasonEnum::BelowKeepRecentTokens, $result->skipReason);
    }

    /**
     * Thesis: buildCompactedMessages places prologue before the summary
     * message, preserving prompt-cache locality for the system prompt
     * and agent-instruction context.
     */
    public function testBuildCompactedMessagesPutsPrologueBeforeSummary(): void
    {
        $systemMsg = $this->makeMessage('system', 'You are a coding assistant.');
        $contextMsg = $this->makeMessage('user-context', '<skills>...</skills>');

        $messages = [
            $systemMsg,
            $contextMsg,
        ];
        $messages = array_merge($messages, $this->makeLongConversation(20));

        $result = $this->compactor->prepare($messages, $this->settings);
        $this->assertTrue($result->isReady());
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        $compacted = $this->compactor->buildCompactedMessages(
            'Summary of the old conversation.',
            $prep,
        );

        // Prologue must be at the very front.
        $this->assertSame('system', $compacted->compactedMessages[0]->role);
        $this->assertSame('user-context', $compacted->compactedMessages[1]->role);

        // Summary message must come after prologue.
        $this->assertSame('user', $compacted->compactedMessages[2]->role);
        $this->assertTrue(
            (bool) ($compacted->compactedMessages[2]->metadata['compact_summary'] ?? false),
            'Third message should be the compact summary',
        );

        // Body tail starts after summary.
        $this->assertSame(
            \count($prep->retainedTailMessages) + 1,
            \count($compacted->compactedMessages),
            'compactedMessages = prologue + summary + body tail',
        );
    }

    // ── Bounded user-boundary preference ─────────────────────────────

    /**
     * Thesis: In a single-user-turn session with many completed
     * assistant/tool-call groups, boundary selection picks a useful
     * safe boundary near the target rather than walking back to the
     * oldest user message.
     *
     * Without the bounded search fix, findSafeBoundary would walk past
     * many safe assistant boundaries and collapse to the first user
     * message, making compaction useless.
     *
     * A tight keepRecentTokens pushes the tentative boundary deep into
     * the tool-call region, forcing the algorithm to choose between
     * a nearby safe assistant boundary and a far-away user boundary.
     */
    public function testBoundarySelectionDoesNotCollapseToOldestUserMessage(): void
    {
        $tightSettings = new CompactionConfig(
            autoEnabled: true,
            keepRecentTokens: 50,
        );

        // Build a session resembling a single-user-turn investigation:
        //   user request → many assistant(tool_calls) → tool result pairs → final answer
        $messages = [];

        // Long initial conversation (padding to push tentative boundary inward).
        $messages = array_merge($messages, $this->makeLongConversation(8));

        // User's investigative request.
        $messages[] = $this->makeMessage('user', 'Investigate this issue: '.str_repeat('x', 100));

        // Many completed assistant/tool-call groups.
        $toolCallCount = 15;
        for ($i = 0; $i < $toolCallCount; ++$i) {
            $tcId = "call_{$i}";
            $messages[] = $this->makeAssistantWithToolCalls([$tcId]);
            $messages[] = $this->makeToolResult($tcId);
        }

        // Final assistant answer.
        $messages[] = $this->makeMessage('assistant', 'Final conclusion after investigation.');

        $result = $this->compactor->prepare($messages, $tightSettings);

        $this->assertTrue($result->isReady(), 'Should produce a useful compaction');
        $prep = $result->preparation;
        $this->assertNotNull($prep);

        // The boundary must be in the tool-call region (second half of
        // messages), not collapsed to the oldest user message at index ~16.
        //
        // With 8 pairs (16 messages) of padding + 1 user request + 15 tool
        // groups (30 messages) + 1 final = 48 total.  keepRecentTokens=50
        // pushes the tentative boundary to around index 40 (deep in the
        // tool-call groups).  The bounded user-preference window should
        // accept a nearby safe assistant boundary rather than walking
        // all the way back to index 16.
        $totalMessages = \count($messages);
        $this->assertGreaterThan(
            (int) ($totalMessages / 2),
            $prep->firstRetainedIndex,
            \sprintf(
                'Boundary (%d) should be in second half of %d messages, not collapsed to oldest user',
                $prep->firstRetainedIndex,
                $totalMessages,
            ),
        );

        // A useful number of messages should be compacted.
        // With the bounded search, we should compact ~39 messages (all padding
        // + user request + most tool-call groups).
        $this->assertGreaterThan(
            15,
            $prep->messagesCompacted,
            'Should compact a useful number of messages, not just a handful',
        );

        // The boundary should be closer to the end than to the beginning
        // of the tool-call region, proving we didn't walk back to the user.
        // With 48 messages, a boundary > 24 means we're in the second half.
        $this->assertGreaterThan(
            30,
            $prep->firstRetainedIndex,
            \sprintf(
                'Boundary should be deep in tool-call region, got firstRetainedIndex=%d',
                $prep->firstRetainedIndex,
            ),
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
                'This is a long user message number '.$i.'. '.str_repeat('padding ', 20),
            );
            $messages[] = $this->makeMessage(
                'assistant',
                'This is a long assistant message number '.$i.'. '.str_repeat('response padding ', 20),
            );
        }

        return $messages;
    }
}
