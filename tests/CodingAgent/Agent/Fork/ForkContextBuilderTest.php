<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotCompactor;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer;
use Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Compaction\CompactionPromptBuilder;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Compaction\SessionCompactor;
use Ineersa\CodingAgent\Compaction\ToolResultDigestService;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;
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

        // Create minimal COMPACTION.md template (SessionCompactor needs it).
        $configDir = $this->projectDir.'/config';
        TestDirectoryIsolation::ensureDirectory($configDir);
        \file_put_contents($configDir.'/COMPACTION.md', "Test compaction prompt.\n\n{date}\n{cwd}{custom_instructions_part}");

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
        $forkCompactor = new ForkSnapshotCompactor($sessionCompactor);
        $compactionConfig = new CompactionConfig(keepRecentTokens: 50);

        $this->sanitizer = new ForkSnapshotSanitizer();
        $forkPromptBuilder = new ForkTaskPromptBuilder();
        $configResolver = new ForkConfigResolver(ForksConfigDTO::defaultInstance());

        $this->builder = new ForkContextBuilder(
            sanitizer: $this->sanitizer,
            compactor: $forkCompactor,
            promptBuilder: $forkPromptBuilder,
            configResolver: $configResolver,
            compactionConfig: $compactionConfig,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
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

    private function createSessionCompactor(): SessionCompactor
    {
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

        return new SessionCompactor(
            $tokenEstimator,
            $digestService,
            $boundarySelector,
            $promptBuilder,
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
        $snapshot = $this->builder->build($parentMessages, $task);

        // Sanitization should have removed the launch messages.
        self::assertCount(2, $snapshot->messages);
        self::assertSame('Old conversation', $snapshot->messages[0]->content[0]['text']);
        self::assertSame('Old response', $snapshot->messages[1]->content[0]['text']);

        // Snapshot contains the fork task user message referencing the task.
        self::assertStringContainsString($task, $snapshot->forkTaskUserMessage);

        // Snapshot contains the FORK_CHILD system append.
        self::assertStringContainsString('FORK MODE IS ENABLED', $snapshot->forkSystemPromptAppend);

        // Resolved model should be null (session fallback, no configured model).
        self::assertNull($snapshot->resolvedModel);
    }

    public function testParentMessagesUnchangedAfterBuild(): void
    {
        $parentMessages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi'),
        ];

        $originalCount = \count($parentMessages);
        $originalContent = $parentMessages[0]->content[0]['text'];

        $this->builder->build($parentMessages, 'Test task');

        self::assertCount($originalCount, $parentMessages);
        self::assertSame($originalContent, $parentMessages[0]->content[0]['text']);
    }

    public function testBuildWithLargeMessagesTriggersCompaction(): void
    {
        // Include a prior compact_summary so the NOOP summary provider
        // has something to carry forward.
        $priorSummary = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Prior session summary for context.']],
            metadata: ['compact_summary' => true],
        );

        $parentMessages = [$priorSummary];
        for ($i = 0; $i < 30; ++$i) {
            $parentMessages[] = $this->userMessage("Long message {$i} that takes up token budget. " . \str_repeat('x', 80));
            $parentMessages[] = $this->assistantMessage("Long response {$i} with substantial text. " . \str_repeat('y', 80));
        }

        $snapshot = $this->builder->build($parentMessages, 'Test task');

        // After compaction, there should be fewer messages than the original 61.
        self::assertLessThan(\count($parentMessages), \count($snapshot->messages));
    }

    public function testBuildWithRequestedLevel(): void
    {
        $parentMessages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi'),
        ];

        $snapshot = $this->builder->build(
            $parentMessages,
            'Task',
            requestedLevel: ForkLevelEnum::Senior,
        );

        // The stored resolvedModel uses the level config (Senior has null model by default).
        self::assertNull($snapshot->resolvedModel);
    }

    public function testBuildWithConfiguredModelLevel(): void
    {
        $configResolver = new ForkConfigResolver(new ForksConfigDTO(
            levels: [
                'senior' => new \Ineersa\CodingAgent\Config\ForkLevelConfigDTO(
                    model: 'openai/gpt-4',
                ),
            ],
        ));

        $builder = new ForkContextBuilder(
            sanitizer: $this->sanitizer,
            compactor: new ForkSnapshotCompactor($this->createSessionCompactor()),
            promptBuilder: new ForkTaskPromptBuilder(),
            configResolver: $configResolver,
            compactionConfig: new CompactionConfig(keepRecentTokens: 50000),
        );

        $snapshot = $builder->build(
            [$this->userMessage('Hi'), $this->assistantMessage('Hello')],
            'Task',
            requestedLevel: ForkLevelEnum::Senior,
        );

        self::assertSame('openai/gpt-4', $snapshot->resolvedModel);
    }

    public function testBuildEmptyMessages(): void
    {
        $snapshot = $this->builder->build([], 'Empty test');

        self::assertCount(0, $snapshot->messages);
        self::assertStringContainsString('Empty test', $snapshot->forkTaskUserMessage);
        self::assertStringContainsString('FORK MODE IS ENABLED', $snapshot->forkSystemPromptAppend);
        self::assertNull($snapshot->resolvedModel);
        self::assertSame(ForkLevelEnum::Middle, $snapshot->level);
    }
}
