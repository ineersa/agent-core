<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotCompactor;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer;
use Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder;
use Ineersa\CodingAgent\Compaction\VirtualCompactionOrchestratorInterface;
use Ineersa\CodingAgent\Compaction\VirtualCompactionResult;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for ForkContextBuilder.
 *
 * Test thesis:
 *   - The builder ties sanitize → compact → resolve → prompt together.
 *   - The parent message list is unchanged after build().
 *   - The snapshot contains the fork task user message referencing the task.
 *   - The snapshot contains the FORK_CHILD system append.
 */
#[CoversClass(ForkContextBuilder::class)]
final class ForkContextBuilderTest extends TestCase
{
    private ForkContextBuilder $builder;
    private ForkSnapshotSanitizer $sanitizer;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('fork-builder-test');
        $this->sanitizer = new ForkSnapshotSanitizer();
        $forkPromptBuilder = new ForkTaskPromptBuilder();
        $configResolver = new ForkConfigResolver(new ForksConfigDTO());

        $orchestrator = $this->createStub(VirtualCompactionOrchestratorInterface::class);
        $orchestrator->method('compactForRun')->willReturnCallback(
            static function (string $runId, array $messages): VirtualCompactionResult {
                if ([] === $messages) {
                    return new VirtualCompactionResult(compactedMessages: [], compacted: false);
                }

                $summary = new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'Synthetic fork compaction summary for '.$runId]],
                    metadata: ['compact_summary' => true],
                );
                $tail = [];
                foreach ($messages as $message) {
                    if ('assistant' === $message->role) {
                        $tail[] = $message;
                    }
                }

                return new VirtualCompactionResult(
                    compactedMessages: [$summary, ...$tail],
                    compacted: true,
                    summaryText: 'Synthetic fork compaction summary',
                    summarizedCount: max(0, \count($messages) - \count($tail)),
                );
            },
        );

        $forkCompactor = new ForkSnapshotCompactor($orchestrator);

        $this->builder = new ForkContextBuilder(
            sanitizer: $this->sanitizer,
            compactor: $forkCompactor,
            promptBuilder: $forkPromptBuilder,
            configResolver: $configResolver,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testBuildSanitizeCompactPromptPipeline(): void
    {
        // Build parent messages including a fork launch (should be sanitized away).
        $parentMessages = [
            $this->userMessage('Old conversation'),
            $this->assistantMessage('Old response'),
            $this->userMessage('Launch fork'),
            $this->assistantMessage('Calling fork', [
                ['id' => 'call_fork_1', 'name' => 'fork', 'arguments' => ['task' => 'do work']],
            ]),
            $this->toolMessage('call_fork_1', 'launched'),
        ];

        $task = 'Implement feature X';
        $snapshot = $this->builder->build($parentMessages, $task, 'parent-run-1');

        // Sanitization should have removed the launch messages, then fork compaction
        // should produce a compact_summary message instead of raw transcript.
        $this->assertCount(2, $snapshot->messages);
        $this->assertTrue($snapshot->messages[0]->metadata['compact_summary'] ?? false);
        $this->assertStringContainsString('Synthetic fork compaction summary', $snapshot->messages[0]->content[0]['text']);
        $this->assertSame('Old response', $snapshot->messages[1]->content[0]['text']);

        // Snapshot contains the fork task user message referencing the task.
        $this->assertStringContainsString($task, $snapshot->forkTaskUserMessage);

        // Snapshot contains the FORK_CHILD system append.
        $this->assertStringContainsString('delegated child agent', $snapshot->forkSystemPromptAppend);

        // Resolved model should be null (session fallback, no configured model).
        $this->assertNull($snapshot->resolvedModel);
    }

    public function testParentMessagesUnchangedAfterBuild(): void
    {
        $parentMessages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi'),
        ];

        $originalCount = \count($parentMessages);
        $originalContent = $parentMessages[0]->content[0]['text'];

        $this->builder->build($parentMessages, 'Test task', 'parent-run-1');

        $this->assertCount($originalCount, $parentMessages);
        $this->assertSame($originalContent, $parentMessages[0]->content[0]['text']);
    }

    public function testBuildWithLargeMessagesTriggersCompaction(): void
    {
        // Include a prior compact_summary plus long tail; fork still refreshes
        // compacted context for the current snapshot.
        $priorSummary = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Prior session summary for context.']],
            metadata: ['compact_summary' => true],
        );

        $parentMessages = [$priorSummary];
        for ($i = 0; $i < 30; ++$i) {
            $parentMessages[] = $this->userMessage("Long message {$i} that takes up token budget. ".str_repeat('x', 80));
            $parentMessages[] = $this->assistantMessage("Long response {$i} with substantial text. ".str_repeat('y', 80));
        }

        $snapshot = $this->builder->build($parentMessages, 'Test task', 'parent-run-1');

        // After compaction, there should be fewer messages than the original 61.
        $this->assertLessThan(\count($parentMessages), \count($snapshot->messages));
    }

    public function testBuildUsesConfiguredForkModel(): void
    {
        $configResolver = new ForkConfigResolver(new ForksConfigDTO(model: 'openai/gpt-4'));
        $orchestrator = $this->createStub(VirtualCompactionOrchestratorInterface::class);
        $orchestrator->method('compactForRun')->willReturn(new VirtualCompactionResult(
            compactedMessages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'summary']], metadata: ['compact_summary' => true]),
                $this->assistantMessage('Hello'),
            ],
            compacted: true,
            summaryText: 'summary',
            summarizedCount: 1,
        ));
        $builder = new ForkContextBuilder(
            sanitizer: $this->sanitizer,
            compactor: new ForkSnapshotCompactor($orchestrator),
            promptBuilder: new ForkTaskPromptBuilder(),
            configResolver: $configResolver,
        );

        $snapshot = $builder->build(
            [$this->userMessage('Hi'), $this->assistantMessage('Hello')],
            'Task',
            'parent-run-1',
        );

        $this->assertSame('openai/gpt-4', $snapshot->resolvedModel);
    }

    public function testBuildEmptyMessages(): void
    {
        $snapshot = $this->builder->build([], 'Empty test', 'parent-run-1');

        $this->assertCount(0, $snapshot->messages);
        $this->assertStringContainsString('Empty test', $snapshot->forkTaskUserMessage);
        $this->assertStringContainsString('delegated child agent', $snapshot->forkSystemPromptAppend);
        $this->assertNull($snapshot->resolvedModel);
    }

    public function testBuildProducesCompactedSnapshotWithoutPriorSummary(): void
    {
        $parent = [];
        for ($i = 0; $i < 10; ++$i) {
            $parent[] = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Parent user '.$i.' '.str_repeat('p', 100)]]);
            $parent[] = new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Parent assistant '.$i.' '.str_repeat('a', 100)]]);
        }

        $snapshot = $this->builder->build($parent, 'Investigate export feedback', 'parent-run-1');

        $this->assertStringContainsString('Investigate export feedback', $snapshot->forkTaskUserMessage);
        $this->assertTrue($snapshot->messages[0]->metadata['compact_summary'] ?? false);
        $this->assertLessThan(\count($parent), \count($snapshot->messages));
    }

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
}
