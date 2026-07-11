<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Tests\Support\Fake\FakePlatform;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionSummarizationException;
use Ineersa\CodingAgent\Compaction\ActiveModelResolverInterface;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Compaction\CompactionPromptBuilder;
use Ineersa\CodingAgent\Compaction\CompactionService;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Compaction\SessionCompactor;
use Ineersa\CodingAgent\Compaction\ToolResultDigestService;
use Ineersa\CodingAgent\Compaction\VirtualCompactionOrchestrator;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

#[CoversClass(VirtualCompactionOrchestrator::class)]
final class VirtualCompactionOrchestratorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('virtual-compaction-test');
        $configDir = $this->projectDir.'/config';
        TestDirectoryIsolation::ensureDirectory($configDir);
        file_put_contents($configDir.'/COMPACTION.md', "Test compaction prompt.\n\n{date}\n{cwd}{custom_instructions_part}");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testCompactionModelOverrideWinsOverCurrentSessionModel(): void
    {
        $activeModelResolver = new FakeActiveModelResolver('openai/parent-model');

        $summaryBody = str_repeat('condensed context ', 30);
        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text($summaryBody)),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $orchestrator = $this->createOrchestrator(
            activeModelResolver: $activeModelResolver,
            platform: $platform,
            compactionConfig: new CompactionConfig(model: 'openai/gpt-test', keepRecentTokens: 50),
        );

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('old conversation ', 200)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'recent tail message']]),
        ];

        $result = $orchestrator->compactForRun('parent-run-1', $messages, force: true);

        $this->assertTrue($result->compacted);
        $this->assertCount(1, $platform->invocations);
        $this->assertSame('openai/gpt-test', $platform->invocations[0]->model);
    }

    public function testUsesCurrentSessionModelFromModelSelectionNotRunStartedMetadata(): void
    {
        $activeModelResolver = new FakeActiveModelResolver('session/current-model');

        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text(str_repeat('summary ', 40))),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $orchestrator = $this->createOrchestrator(
            activeModelResolver: $activeModelResolver,
            platform: $platform,
            compactionConfig: new CompactionConfig(model: null, keepRecentTokens: 50),
        );

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('history ', 200)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'tail']]),
        ];

        $orchestrator->compactForRun('parent-run-stale-runstarted', $messages, force: true);

        $this->assertSame('session/current-model', $platform->invocations[0]->model);
    }

    public function testUnderBudgetForceStillCompacts(): void
    {
        $activeModelResolver = new FakeActiveModelResolver('openai/gpt-test');

        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text(str_repeat('summary ', 20))),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $orchestrator = $this->createOrchestrator(
            activeModelResolver: $activeModelResolver,
            platform: $platform,
            compactionConfig: new CompactionConfig(model: 'openai/gpt-test', keepRecentTokens: 50000),
        );

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi!']]),
        ];

        $result = $orchestrator->compactForRun('parent-run-1', $messages, force: true);

        $this->assertTrue($result->compacted);
        $summaryFound = false;
        foreach ($result->compactedMessages as $message) {
            if (true === ($message->metadata['compact_summary'] ?? null)) {
                $summaryFound = true;
                break;
            }
        }
        $this->assertTrue($summaryFound);
    }

    public function testWithoutModelThrowsStructuredToolCallException(): void
    {
        $activeModelResolver = new FakeActiveModelResolver(null);

        $orchestrator = $this->createOrchestrator(
            activeModelResolver: $activeModelResolver,
            platform: new FakePlatform([]),
            compactionConfig: new CompactionConfig(model: null),
        );

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'history one']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'history two']]),
        ];

        try {
            $orchestrator->compactForRun('parent-run-1', $messages, force: true);
            $this->fail('Expected ForkCompactionSummarizationException');
        } catch (ForkCompactionSummarizationException $exception) {
            $this->assertInstanceOf(ToolCallException::class, $exception);
            $this->assertStringContainsString('no summarization model', $exception->getMessage());
            $this->assertNotNull($exception->hint());
        }
    }

    public function testForcedCompactionKeepsAssistantToolCallWithMatchingToolResult(): void
    {
        $callId = 'call_00_nKk_scout';
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('session history ', 400)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('more history ', 400)]]),
            $this->makeAssistantWithToolCalls([$callId]),
            $this->makeToolResult($callId, 'scout completed successfully'),
        ];

        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text('fork handoff summary')),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $orchestrator = $this->createOrchestrator(
            activeModelResolver: new FakeActiveModelResolver('openai/gpt-test'),
            platform: $platform,
            compactionConfig: new CompactionConfig(model: 'openai/gpt-test', keepRecentTokens: 50000),
        );

        $result = $orchestrator->compactForRun('parent-run-session-8', $messages, force: true);

        $this->assertTrue($result->compacted);
        $this->assertCount(1, $platform->invocations);
        $this->assertTrue($result->compactedMessages[0]->metadata['compact_summary'] ?? false);
    }

    public function testIneffectiveFirstSummarizationRetriesWithTighterInstruction(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('alpha ', 30)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('beta ', 30)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('gamma ', 30)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('delta tail ', 30)]]),
        ];

        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text(str_repeat('verbose-summary-token ', 800))),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text('dense summary')),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $orchestrator = $this->createOrchestrator(
            activeModelResolver: new FakeActiveModelResolver('openai/gpt-test'),
            platform: $platform,
            compactionConfig: new CompactionConfig(model: 'openai/gpt-test', keepRecentTokens: 50000),
        );

        $result = $orchestrator->compactForRun('parent-run-retry', $messages, force: true);

        $this->assertTrue($result->compacted);
        $this->assertCount(2, $platform->invocations);
        $retryMessages = $platform->invocations[1]->input->messages;
        $retryPrompt = $retryMessages[\count($retryMessages) - 1]->content[0]['text'] ?? '';
        $this->assertStringContainsString('materially shorter', $retryPrompt);
    }

    public function testTwoIneffectiveSummarizationAttemptsThrowTypedException(): void
    {
        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('alpha ', 30)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('beta ', 30)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('gamma ', 30)]]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('delta tail ', 30)]]),
        ];

        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text(str_repeat('verbose-summary-token ', 800))),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text(str_repeat('still verbose summary ', 400))),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $orchestrator = $this->createOrchestrator(
            activeModelResolver: new FakeActiveModelResolver('openai/gpt-test'),
            platform: $platform,
            compactionConfig: new CompactionConfig(model: 'openai/gpt-test', keepRecentTokens: 50000),
        );

        try {
            $orchestrator->compactForRun('parent-run-fail', $messages, force: true);
            $this->fail('Expected ForkCompactionSummarizationException');
        } catch (ForkCompactionSummarizationException $exception) {
            $this->assertStringContainsString('ineffective (context did not shrink)', $exception->getMessage());
        }

        $this->assertCount(2, $platform->invocations);
    }

    /**
     * @param list<string> $toolCallIds
     */
    private function makeAssistantWithToolCalls(array $toolCallIds): AgentMessage
    {
        $toolCalls = [];
        foreach ($toolCallIds as $id) {
            $toolCalls[] = [
                'id' => $id,
                'type' => 'function',
                'function' => ['name' => 'subagent', 'arguments' => '{}'],
            ];
        }

        return new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Launching scout']],
            metadata: ['tool_calls' => $toolCalls],
        );
    }

    private function makeToolResult(string $toolCallId, string $text): AgentMessage
    {
        return new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $text]],
            toolCallId: $toolCallId,
        );
    }

    private function createOrchestrator(
        ActiveModelResolverInterface $activeModelResolver,
        FakePlatform $platform,
        CompactionConfig $compactionConfig,
    ): VirtualCompactionOrchestrator {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $sessionCompactor = $this->createSessionCompactor($appConfig);
        $boundarySelector = $this->createBoundarySelector();
        $compactionService = new CompactionService($sessionCompactor, $appConfig);

        return new VirtualCompactionOrchestrator(
            compactionService: $compactionService,
            sessionCompactor: $sessionCompactor,
            compactionConfig: $compactionConfig,
            activeModelResolver: $activeModelResolver,
            platform: $platform,
            boundarySelector: $boundarySelector,
            tokenEstimator: new CompactionTokenEstimator(),
        );
    }

    private function createBoundarySelector(): CompactionBoundarySelector
    {
        $tokenEstimator = new CompactionTokenEstimator();
        $sequenceValidator = new AgentMessageToolCallSequenceValidator();

        return new CompactionBoundarySelector($tokenEstimator, $sequenceValidator);
    }

    private function createSessionCompactor(AppConfig $appConfig): SessionCompactor
    {
        $tokenEstimator = new CompactionTokenEstimator();
        $sequenceValidator = new AgentMessageToolCallSequenceValidator();
        $boundarySelector = new CompactionBoundarySelector($tokenEstimator, $sequenceValidator);
        $digestService = new ToolResultDigestService($tokenEstimator);
        $pathResolver = new SettingsPathResolver($this->projectDir, $this->projectDir);
        $promptBuilder = new CompactionPromptBuilder(
            $pathResolver,
            new StringTemplateRenderer(),
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
}

final class FakeActiveModelResolver implements ActiveModelResolverInterface
{
    public function __construct(private ?string $model)
    {
    }

    public function getActiveModel(string $runId): ?string
    {
        return $this->model;
    }
}
