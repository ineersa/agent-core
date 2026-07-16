<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotCompactor;
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
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

/**
 * Replacement contract: fork must respect canonical compaction prepare() no-op (no force summarization).
 */
final class ForkCanonicalCompactionContractTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('fork-canonical-compaction');
        $configDir = $this->projectDir.'/config';
        TestDirectoryIsolation::ensureDirectory($configDir);
        file_put_contents($configDir.'/COMPACTION.md', "Summarize.\n\n{date}\n{cwd}{custom_instructions_part}");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testUnderThresholdUsesCanonicalNoOpWithoutPlatformInvocation(): void
    {
        $platform = new ForkCompactionRecordingPlatform();
        $compactionService = $this->createCompactionService();
        $orchestrator = $this->createVirtualOrchestrator($compactionService, $platform);
        $compactor = new ForkSnapshotCompactor($orchestrator);

        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi there'),
        ];

        $this->assertFalse($compactionService->prepare($messages)->isReady());

        $result = $compactor->compact($messages, 'parent-run-1');

        $this->assertFalse($result->compacted);
        $this->assertSame(2, \count($result->messages));
        $this->assertSame(0, $platform->invokeCount);
    }

    private function createCompactionService(): CompactionServiceInterface
    {
        $tokenEstimator = new CompactionTokenEstimator();
        $sequenceValidator = new AgentMessageToolCallSequenceValidator();
        $boundarySelector = new CompactionBoundarySelector($tokenEstimator, $sequenceValidator);
        $digestService = new ToolResultDigestService($tokenEstimator);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
            compaction: new CompactionConfig(keepRecentTokens: 50000, model: 'llama_cpp/test'),
        );

        $pathResolver = new SettingsPathResolver($this->projectDir, $this->projectDir);
        $promptBuilder = new CompactionPromptBuilder(
            $pathResolver,
            new StringTemplateRenderer(),
            $appConfig,
            $this->projectDir,
        );

        return new CompactionService(new SessionCompactor(
            $tokenEstimator,
            $digestService,
            $boundarySelector,
            $promptBuilder,
        ), $appConfig);
    }

    private function createVirtualOrchestrator(CompactionServiceInterface $compactionService, PlatformInterface $platform): VirtualCompactionOrchestrator
    {
        $tokenEstimator = new CompactionTokenEstimator();
        $sequenceValidator = new AgentMessageToolCallSequenceValidator();
        $boundarySelector = new CompactionBoundarySelector($tokenEstimator, $sequenceValidator);
        $digestService = new ToolResultDigestService($tokenEstimator);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
            compaction: new CompactionConfig(keepRecentTokens: 50000, model: 'llama_cpp/test'),
        );

        $pathResolver = new SettingsPathResolver($this->projectDir, $this->projectDir);
        $promptBuilder = new CompactionPromptBuilder(
            $pathResolver,
            new StringTemplateRenderer(),
            $appConfig,
            $this->projectDir,
        );

        return new VirtualCompactionOrchestrator(
            $compactionService,
            new SessionCompactor($tokenEstimator, $digestService, $boundarySelector, $promptBuilder),
            $appConfig->compaction,
            new ForkCompactionFixedActiveModelResolver(),
            $platform,
            $boundarySelector,
            $tokenEstimator,
        );
    }

    private function userMessage(string $text): AgentMessage
    {
        return new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => $text]]);
    }

    private function assistantMessage(string $text): AgentMessage
    {
        return new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => $text]]);
    }
}

final class ForkCompactionRecordingPlatform implements PlatformInterface
{
    public int $invokeCount = 0;

    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
    {
        ++$this->invokeCount;

        return new PlatformInvocationResult(
            assistantMessage: new AssistantMessage(new Text('summary')),
            usage: [],
            stopReason: 'stop',
            error: null,
        );
    }
}

final class ForkCompactionFixedActiveModelResolver implements ActiveModelResolverInterface
{
    public function getActiveModel(string $runId): ?string
    {
        return $this->resolveActiveModel($runId);
    }

    public function resolveActiveModel(string $runId): ?string
    {
        return 'llama_cpp/test';
    }
}
