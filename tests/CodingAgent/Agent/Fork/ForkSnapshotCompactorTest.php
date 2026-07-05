<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionResult;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotCompactor;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Compaction\CompactionPromptBuilder;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
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

/**
 * Tests for ForkSnapshotCompactor.
 *
 * Test thesis:
 *   - No compaction when messages fit within the token budget.
 *   - Compacts retained tail when over budget and carries forward a
 *     prior compact_summary summary.
 *   - Never mutates the input array.
 *   - No-summary bail: without a prior compact_summary, messages pass
 *     through unchanged (v1 behavior, no LLM available).
 *   - Respects safe boundary (no split tool-call/tool-result groups).
 *   - Summary message is placed AFTER the immutable prologue
 *     (system/user-context), never before it.
 */
#[CoversClass(ForkSnapshotCompactor::class)]
#[CoversClass(ForkCompactionResult::class)]
final class ForkSnapshotCompactorTest extends TestCase
{
    private ForkSnapshotCompactor $compactor;
    private CompactionConfig $config;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('fork-compactor-test');

        // Create minimal COMPACTION.md template (SessionCompactor needs it).
        $configDir = $this->projectDir.'/config';
        TestDirectoryIsolation::ensureDirectory($configDir);
        file_put_contents($configDir.'/COMPACTION.md', "Test compaction prompt.\n\n{date}\n{cwd}{custom_instructions_part}");

        $tokenEstimator = new CompactionTokenEstimator();
        $sequenceValidator = new AgentMessageToolCallSequenceValidator();
        $boundarySelector = new CompactionBoundarySelector($tokenEstimator, $sequenceValidator);
        $digestService = new ToolResultDigestService($tokenEstimator);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );

        $pathResolver = new SettingsPathResolver($this->projectDir, $this->projectDir);
        $templateRenderer = new StringTemplateRenderer();
        $promptBuilder = new CompactionPromptBuilder(
            $pathResolver,
            $templateRenderer,
            $appConfig,
            $this->projectDir,
        );

        $sessionCompactor = new SessionCompactor(
            $tokenEstimator,
            $digestService,
            $boundarySelector,
            $promptBuilder,
        );

        $this->compactor = new ForkSnapshotCompactor($sessionCompactor);

        // Small budget to force compaction in tests.
        $this->config = new CompactionConfig(keepRecentTokens: 50);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    // ── Tests ────────────────────────────────────────────────────────────

    public function testNoCompactionWhenUnderBudget(): void
    {
        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi!'),
        ];

        // Large budget — messages fit without compaction.
        $largeConfig = new CompactionConfig(keepRecentTokens: 50000);

        $result = $this->compactor->compact($messages, $largeConfig);

        $this->assertFalse($result->compacted);
        $this->assertCount(2, $result->messages);
        $this->assertNull($result->summaryText);
        $this->assertSame(0, $result->summarizedCount);
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
            $this->userMessage('Old message with lots of padding '.str_repeat('x', 200)),
            $this->assistantMessage('Old response with lots of padding '.str_repeat('y', 200)),
            $this->userMessage('More old content '.str_repeat('z', 200)),
            $this->assistantMessage('More old response '.str_repeat('w', 200)),
        ];

        $result = $this->compactor->compact($allMessages, $this->config);

        // Should compact because budget is tight and messages are long.
        $this->assertTrue($result->compacted, 'Expected compaction to occur with tight budget and long messages');

        // The first message in the retained tail should be the newly created
        // compact_summary carrying forward the prior summary text.
        $this->assertTrue($result->messages[0]->metadata['compact_summary'] ?? false);
        $this->assertStringContainsString('Previous conversation summary', $result->messages[0]->content[0]['text']);

        // Summary message has the expected <summary> wrapper structure.
        $this->assertStringContainsString('<summary>', $result->messages[0]->content[0]['text']);
        $this->assertStringContainsString('</summary>', $result->messages[0]->content[0]['text']);
    }

    public function testNoSummaryBailWithoutPriorCompactSummary(): void
    {
        // No prior compact_summary — v1 should return unchanged even if over budget.
        $messages = [
            $this->userMessage('Message one '.str_repeat('x', 200)),
            $this->assistantMessage('Response one '.str_repeat('y', 200)),
        ];

        $result = $this->compactor->compact($messages, $this->config);

        // Without a prior summary to carry forward, messages pass through unchanged.
        $this->assertFalse($result->compacted, 'No-summary bail: expected no compaction without prior summary');
        $this->assertNull($result->summaryText);
        $this->assertCount(2, $result->messages);
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

        $this->compactor->compact($messages, $this->config);

        $this->assertCount($originalCount, $messages);
    }

    public function testEmptyInput(): void
    {
        $result = $this->compactor->compact([], $this->config);

        $this->assertFalse($result->compacted);
        $this->assertCount(0, $result->messages);
    }

    public function testSingleMessageFits(): void
    {
        $messages = [$this->userMessage('Just one message')];

        $result = $this->compactor->compact($messages, new CompactionConfig(keepRecentTokens: 50000));

        $this->assertFalse($result->compacted);
        $this->assertCount(1, $result->messages);
    }

    public function testSafeBoundaryRespected(): void
    {
        // Build messages where a naive cut would split a tool-call group.
        // Create many old messages to force compaction, then a complete
        // tool-call group near the end.
        $priorSummary = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Prior session summary.']],
            metadata: ['compact_summary' => true],
        );

        $messages = [$priorSummary];
        for ($i = 0; $i < 15; ++$i) {
            $messages[] = $this->userMessage("Old user turn {$i} with lots of text for token consumption ".str_repeat('a', 60));
            $messages[] = $this->assistantMessage("Old assistant turn {$i} ".str_repeat('b', 60));
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
        $result = $this->compactor->compact($messages, $this->config);

        $this->assertTrue($result->compacted, 'Expected compaction to occur for the safe-boundary fixture');

        // Verify no tool-call group is split — no orphan tool messages.
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

            // A user message resets the batch; after user we expect
            // only tool results belonging to a preceding assistant.
            // For this test we focus on orphan detection.
        }

        $this->assertFalse($foundOrphan, 'Found orphan tool message — safe boundary was violated');
    }

    public function testPrologueOrdering(): void
    {
        // Regression: compacted messages must place the summary AFTER the
        // immutable system/user-context prologue, never before it.
        $priorSummary = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Prior session summary here.']],
            metadata: ['compact_summary' => true],
        );

        $messages = [
            $this->systemMessage('You are a helpful assistant.'),
            $this->systemMessage('Custom instructions.'),
            $priorSummary,
        ];

        // Add enough body content to force compaction.
        for ($i = 0; $i < 15; ++$i) {
            $messages[] = $this->userMessage("Long message {$i} that consumes token budget aggressively. ".str_repeat('x', 100));
            $messages[] = $this->assistantMessage("Long response {$i} ".str_repeat('y', 100));
        }

        $result = $this->compactor->compact($messages, $this->config);

        $this->assertTrue($result->compacted, 'Expected compaction for prologue-ordering fixture');

        // Find the summary message index.
        $summaryIndex = null;
        foreach ($result->messages as $idx => $msg) {
            if (true === ($msg->metadata['compact_summary'] ?? false)) {
                $summaryIndex = $idx;
                break;
            }
        }

        $this->assertNotNull($summaryIndex, 'Summary message must be present');

        // Find the last prologue message index (system/user-context).
        $lastPrologueIndex = -1;
        foreach ($result->messages as $idx => $msg) {
            if ('system' === $msg->role || 'user-context' === $msg->role) {
                $lastPrologueIndex = $idx;
            }
        }

        // The summary must appear AFTER the last prologue message.
        $this->assertGreaterThan(
            $lastPrologueIndex,
            $summaryIndex,
            'Summary message must be placed after the immutable prologue (system/user-context), not before it.',
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function userMessage(string $content): AgentMessage
    {
        return new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $content]],
        );
    }

    private function systemMessage(string $content): AgentMessage
    {
        return new AgentMessage(
            role: 'system',
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
}
