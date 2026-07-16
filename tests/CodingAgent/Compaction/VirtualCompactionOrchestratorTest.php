<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
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

/**
 * Test thesis: forced fork compaction retry must apply a hard max_tokens budget on
 * attempt 2 derived from available shrink capacity so an ineffective first summary
 * can still produce a fresh concise summary and strict shrink (tokenEstimateAfter < tokenEstimateBefore).
 */
#[CoversClass(VirtualCompactionOrchestrator::class)]
final class VirtualCompactionOrchestratorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('virtual-compaction-test');
        $configDir = $this->projectDir.'/config';
        TestDirectoryIsolation::ensureDirectory($configDir);
        file_put_contents($configDir.'/COMPACTION.md', "Summarize for fork handoff.\n\n{date}\n{cwd}{custom_instructions_part}");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testForcedForkCompactionRetryAppliesHardMaxTokensBudgetOnSecondAttempt(): void
    {
        $tokenEstimator = new CompactionTokenEstimator();
        $sequenceValidator = new AgentMessageToolCallSequenceValidator();
        $boundarySelector = new CompactionBoundarySelector($tokenEstimator, $sequenceValidator);
        $digestService = new ToolResultDigestService($tokenEstimator);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
            compaction: new CompactionConfig(
                keepRecentTokens: 12,
                model: 'llama_cpp/test',
                thinkingLevel: 'high',
            ),
        );

        $pathResolver = new SettingsPathResolver($this->projectDir, $this->projectDir);
        $promptBuilder = new CompactionPromptBuilder(
            $pathResolver,
            new StringTemplateRenderer(),
            $appConfig,
            $this->projectDir,
        );

        $sessionCompactor = new SessionCompactor(
            $tokenEstimator,
            $digestService,
            $boundarySelector,
            $promptBuilder,
        );

        $compactionService = new CompactionService($sessionCompactor, $appConfig);
        $platform = new BudgetAwareCompactionPlatformFake();

        $orchestrator = new VirtualCompactionOrchestrator(
            compactionService: $compactionService,
            sessionCompactor: $sessionCompactor,
            compactionConfig: $appConfig->compaction,
            activeModelResolver: new FixedActiveModelResolver('llama_cpp/session-model'),
            platform: $platform,
            boundarySelector: $boundarySelector,
            tokenEstimator: $tokenEstimator,
        );

        $messages = $this->sessionTenStyleMessages();

        $tokenEstimateBefore = $tokenEstimator->estimateTokens($messages);

        $result = $orchestrator->compactForRun('parent-run-10', $messages, force: true);

        $this->assertTrue($result->compacted);
        $this->assertSame(2, $platform->invocationCount());
        $this->assertSame(
            BudgetAwareCompactionPlatformFake::CONCISE_SUMMARY,
            $result->summaryText,
        );

        $first = $platform->invocations[0];
        $second = $platform->invocations[1];

        $this->assertSame('llama_cpp/test', $first->model);
        $this->assertSame('llama_cpp/test', $second->model);
        $this->assertSame('high', $first->options->extraOptions['thinking_level'] ?? null);
        $this->assertSame('high', $second->options->extraOptions['thinking_level'] ?? null);
        $this->assertArrayNotHasKey('max_tokens', $first->options->extraOptions);
        $this->assertArrayHasKey('max_tokens', $second->options->extraOptions);
        $this->assertGreaterThan(0, (int) $second->options->extraOptions['max_tokens']);

        $tokenEstimateAfter = $tokenEstimator->estimateTokens($result->compactedMessages);
        $this->assertLessThan($tokenEstimateBefore, $tokenEstimateAfter);
    }

    /**
     * Session-10 style: large immutable prologue, tiny compactable body (few hundred chars eligible).
     *
     * @return list<AgentMessage>
     */
    private function sessionTenStyleMessages(): array
    {
        $agentsContext = str_repeat('AGENTS.md context line for fork parent. ', 120);
        $messages = [
            new AgentMessage(
                role: 'system',
                content: [['type' => 'text', 'text' => 'You are Hatfield main agent.']],
            ),
            new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $agentsContext]],
                metadata: ['source' => 'agents_context'],
            ),
        ];

        for ($i = 0; $i < 6; ++$i) {
            $messages[] = new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Older fork parent turn '.$i.' '.str_repeat('context-', 30)]],
            );
            $messages[] = new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'Older assistant reply '.$i.' '.str_repeat('detail-', 30)]],
            );
        }

        $messages[] = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Recent retained user tail about fork launch.']],
        );
        $messages[] = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Recent retained assistant tail.']],
        );

        return $messages;
    }
}

final class BudgetAwareCompactionPlatformFake implements PlatformInterface
{
    public const string CONCISE_SUMMARY = 'Fresh fork handoff: user wants forced compaction with strict shrink before child launch.';

    /** @var list<ModelInvocationRequest> */
    public array $invocations = [];

    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
    {
        $this->invocations[] = $request;

        $maxTokens = $request->options->extraOptions['max_tokens'] ?? null;
        $promptText = $this->flattenPromptText($request);

        $hasPositiveCap = is_int($maxTokens) && $maxTokens > 0;
        $promptCommunicatesCap = str_contains($promptText, 'max_tokens')
            || str_contains($promptText, 'output token')
            || str_contains($promptText, 'token budget')
            || str_contains($promptText, 'token cap');

        if ($hasPositiveCap && $promptCommunicatesCap) {
            return new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text(self::CONCISE_SUMMARY)),
                usage: [],
                stopReason: 'stop',
                error: null,
            );
        }

        $verbose = 'Verbose ineffective fork compaction summary that repeats retained context and does not shrink. '
            .str_repeat('padding-token-bloat ', 400);

        return new PlatformInvocationResult(
            assistantMessage: new AssistantMessage(new Text($verbose)),
            usage: [],
            stopReason: 'stop',
            error: null,
        );
    }

    public function invocationCount(): int
    {
        return \count($this->invocations);
    }

    private function flattenPromptText(ModelInvocationRequest $request): string
    {
        $parts = [];
        foreach ($request->input->messages as $message) {
            foreach ($message->content as $block) {
                if (isset($block['text']) && \is_string($block['text'])) {
                    $parts[] = $block['text'];
                }
            }
        }

        return implode("\n", $parts);
    }
}

final class FixedActiveModelResolver implements ActiveModelResolverInterface
{
    public function __construct(private readonly ?string $model)
    {
    }

    public function getActiveModel(string $runId): ?string
    {
        return $this->model;
    }

    public function resolveActiveModel(string $runId): ?string
    {
        return $this->model;
    }
}
