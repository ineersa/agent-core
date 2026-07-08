<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Tests\Support\Fake\FakePlatform;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionSummarizationException;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionSummarizer;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Compaction\CompactionPreparationDTO;
use Ineersa\CodingAgent\Compaction\CompactionPromptBuilder;
use Ineersa\CodingAgent\Compaction\CompactionService;
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
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

#[CoversClass(ForkCompactionSummarizer::class)]
final class ForkCompactionSummarizerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('fork-summarizer-test');
        $configDir = $this->projectDir.'/config';
        TestDirectoryIsolation::ensureDirectory($configDir);
        file_put_contents($configDir.'/COMPACTION.md', "Test compaction prompt.\n\n{date}\n{cwd}{custom_instructions_part}");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testSummarizeUsesTrivialTextForSingleMessageCompaction(): void
    {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $compactionService = new CompactionService($this->createSessionCompactor(), $appConfig);
        $platform = new FakePlatform([]);

        $summarizer = new ForkCompactionSummarizer(
            compactionService: $compactionService,
            platform: $platform,
            compactionConfig: new CompactionConfig(model: 'openai/gpt-test'),
        );

        $prep = new CompactionPreparationDTO(
            messagesToSummarize: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Only parent turn']]),
            ],
            retainedTailMessages: [],
            tokenEstimateBefore: 1,
            messagesCompacted: 1,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            priorSummaryPresent: false,
        );

        $summary = $summarizer->summarize($prep, 'openai/parent-model');

        $this->assertSame('Only parent turn', $summary);
        $this->assertCount(0, $platform->invocations);
    }

    public function testSummarizeBuildsEffectiveSummaryUsingCompactionService(): void
    {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $compactionService = new CompactionService($this->createSessionCompactor(), $appConfig);
        $summaryBody = str_repeat('condensed context ', 30);
        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text($summaryBody)),
                usage: [],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $summarizer = new ForkCompactionSummarizer(
            compactionService: $compactionService,
            platform: $platform,
            compactionConfig: new CompactionConfig(model: 'openai/gpt-test'),
        );

        $prep = new CompactionPreparationDTO(
            messagesToSummarize: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => str_repeat('old conversation ', 200)]]),
            ],
            retainedTailMessages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'recent tail message']]),
            ],
            tokenEstimateBefore: 8000,
            messagesCompacted: 1,
            messagesRetained: 1,
            firstRetainedIndex: 1,
            priorSummaryPresent: false,
        );

        $summary = $summarizer->summarize($prep, 'openai/parent-model');

        $this->assertStringContainsString('condensed context', $summary);
        $this->assertCount(1, $platform->invocations);
        $this->assertSame('openai/gpt-test', $platform->invocations[0]->model);
    }

    public function testSummarizeWithoutModelThrowsStructuredToolCallException(): void
    {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $compactionService = new CompactionService($this->createSessionCompactor(), $appConfig);
        $summarizer = new ForkCompactionSummarizer(
            compactionService: $compactionService,
            platform: new FakePlatform([]),
            compactionConfig: new CompactionConfig(model: null),
        );

        $prep = new CompactionPreparationDTO(
            messagesToSummarize: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'history one']]),
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'history two']]),
            ],
            retainedTailMessages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'tail']]),
            ],
            tokenEstimateBefore: 100,
            messagesCompacted: 2,
            messagesRetained: 1,
            firstRetainedIndex: 2,
            priorSummaryPresent: false,
        );

        try {
            $summarizer->summarize($prep, null);
            $this->fail('Expected ForkCompactionSummarizationException');
        } catch (ForkCompactionSummarizationException $exception) {
            $this->assertInstanceOf(ToolCallException::class, $exception);
            $this->assertStringContainsString('no summarization model', $exception->getMessage());
            $this->assertNotNull($exception->hint());
            $this->assertStringContainsString('compaction.model', (string) $exception->hint());
        }
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
}
