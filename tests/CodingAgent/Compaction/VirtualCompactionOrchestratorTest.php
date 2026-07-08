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
        $this->assertTrue($result->compactedMessages[0]->metadata['compact_summary'] ?? false);
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

    private function createOrchestrator(
        ActiveModelResolverInterface $activeModelResolver,
        FakePlatform $platform,
        CompactionConfig $compactionConfig,
    ): VirtualCompactionOrchestrator {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $sessionCompactor = $this->createSessionCompactor($appConfig);
        $compactionService = new CompactionService($sessionCompactor, $appConfig);

        return new VirtualCompactionOrchestrator(
            compactionService: $compactionService,
            sessionCompactor: $sessionCompactor,
            compactionConfig: $compactionConfig,
            activeModelResolver: $activeModelResolver,
            platform: $platform,
        );
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
