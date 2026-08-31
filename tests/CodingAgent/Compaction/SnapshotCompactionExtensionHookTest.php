<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\CodingAgent\Compaction\CompactionBoundarySelector;
use Ineersa\CodingAgent\Compaction\CompactionHookDispatcher;
use Ineersa\CodingAgent\Compaction\CompactionPromptBuilder;
use Ineersa\CodingAgent\Compaction\CompactionService;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Compaction\SessionCompactor;
use Ineersa\CodingAgent\Compaction\ToolResultDigestService;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionCompactionHookDispatcher;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

/**
 * Thesis: public snapshot hooks can replace fork snapshot summaries without model invoke;
 * CompactRun watermark hooks are not used on this path; empty continue keeps model path.
 */
final class SnapshotCompactionExtensionHookTest extends TestCase
{
    public function testSnapshotReplacementSkipsPlatformAndUsesHookSummary(): void
    {
        $projectDir = TestDirectoryIsolation::createOsTempDir('snapshot-ext-hook');
        $homeDir = TestDirectoryIsolation::createOsTempDir('snapshot-ext-hook-home');
        TestDirectoryIsolation::ensureDirectory($projectDir.'/config');
        file_put_contents($projectDir.'/config/COMPACTION.md', "Test compaction prompt.\n\n{date}\n{cwd}{custom_instructions_part}");

        try {
            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $projectDir,
                compaction: new CompactionConfig(
                    autoEnabled: true,
                    keepRecentTokens: 50,
                ),
            );
            $pathResolver = new SettingsPathResolver($projectDir, $homeDir);
            $tokenEstimator = new CompactionTokenEstimator();
            $sessionCompactor = new SessionCompactor(
                $tokenEstimator,
                new ToolResultDigestService($tokenEstimator),
                new CompactionBoundarySelector(
                    $tokenEstimator,
                    new AgentMessageToolCallSequenceValidator(),
                ),
                new CompactionPromptBuilder(
                    $pathResolver,
                    new StringTemplateRenderer(),
                    $appConfig,
                    $projectDir,
                ),
            );

            $marker = 'OM_SNAPSHOT_REPLACEMENT_MARKER_UNIQUE';
            $registry = new ExtensionHookRegistry();
            $registry->addBeforeCompactionHook(new class($marker) implements BeforeCompactionHookInterface {
                public function __construct(private string $marker)
                {
                }

                public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
                {
                    $this->assertSame('fork', $context->trigger);
                    if (null !== $context->requiredStartSeq || null !== $context->requiredEndSeq) {
                        throw new \RuntimeException('snapshot path must use null coverage watermark');
                    }

                    return BeforeCompactionHookResultDTO::replaceSummary($this->marker);
                }

                private function assertSame(mixed $expected, mixed $actual): void
                {
                    if ($expected !== $actual) {
                        throw new \RuntimeException('unexpected trigger');
                    }
                }
            });

            $platform = new class implements PlatformInterface {
                public int $invokes = 0;

                public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
                {
                    ++$this->invokes;
                    throw new \LogicException('Model must not run for snapshot extension replacement.');
                }
            };

            $service = new CompactionService(
                $sessionCompactor,
                $appConfig,
                $this->createModelSelectionStub(),
                $platform,
                new ExtensionCompactionHookDispatcher($registry, new CompactionHookDispatcher([]), new NullLogger()),
                new NullLogger(),
            );

            $messages = [];
            for ($i = 0; $i < 12; ++$i) {
                $messages[] = new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => str_repeat('token-heavy body message '.$i.' ', 20)]],
                );
            }

            $result = $service->compactMessages(
                runId: 'parent-run',
                turnNo: 2,
                messages: $messages,
                trigger: 'fork',
                activeModel: 'test-model',
            );

            $this->assertFalse($result->isFailure());
            $this->assertSame(0, $platform->invokes);
            $found = false;
            foreach ($result->messages as $message) {
                if (true !== ($message->metadata['compact_summary'] ?? false)) {
                    continue;
                }
                foreach ($message->content as $block) {
                    if (('text' === ($block['type'] ?? '')) && str_contains((string) ($block['text'] ?? ''), $marker)) {
                        $found = true;
                    }
                }
            }
            $this->assertTrue($found, 'snapshot replacement summary must be present');
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
            TestDirectoryIsolation::removeDirectory($homeDir);
        }
    }

    public function testEmptySnapshotContinueUsesModelPath(): void
    {
        $projectDir = TestDirectoryIsolation::createOsTempDir('snapshot-ext-continue');
        $homeDir = TestDirectoryIsolation::createOsTempDir('snapshot-ext-continue-home');
        TestDirectoryIsolation::ensureDirectory($projectDir.'/config');
        file_put_contents($projectDir.'/config/COMPACTION.md', "Test compaction prompt.\n\n{date}\n{cwd}{custom_instructions_part}");

        try {
            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $projectDir,
                compaction: new CompactionConfig(
                    autoEnabled: true,
                    keepRecentTokens: 50,
                ),
            );
            $pathResolver = new SettingsPathResolver($projectDir, $homeDir);
            $tokenEstimator = new CompactionTokenEstimator();
            $sessionCompactor = new SessionCompactor(
                $tokenEstimator,
                new ToolResultDigestService($tokenEstimator),
                new CompactionBoundarySelector(
                    $tokenEstimator,
                    new AgentMessageToolCallSequenceValidator(),
                ),
                new CompactionPromptBuilder(
                    $pathResolver,
                    new StringTemplateRenderer(),
                    $appConfig,
                    $projectDir,
                ),
            );

            $registry = new ExtensionHookRegistry();
            $registry->addBeforeCompactionHook(new class implements BeforeCompactionHookInterface {
                public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
                {
                    return BeforeCompactionHookResultDTO::continue();
                }
            });

            $platform = new class implements PlatformInterface {
                public int $invokes = 0;

                public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
                {
                    ++$this->invokes;

                    return new PlatformInvocationResult(
                        assistantMessage: new \Symfony\AI\Platform\Message\AssistantMessage(
                            new \Symfony\AI\Platform\Message\Content\Text('MODEL_SNAPSHOT_SUMMARY'),
                        ),
                    );
                }
            };

            $service = new CompactionService(
                $sessionCompactor,
                $appConfig,
                $this->createModelSelectionStub(),
                $platform,
                new ExtensionCompactionHookDispatcher($registry, new CompactionHookDispatcher([]), new NullLogger()),
                new NullLogger(),
            );

            $messages = [];
            for ($i = 0; $i < 12; ++$i) {
                $messages[] = new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => str_repeat('token-heavy body message '.$i.' ', 20)]],
                );
            }

            $result = $service->compactMessages(
                runId: 'parent-run',
                turnNo: 2,
                messages: $messages,
                trigger: 'fork',
                activeModel: 'test-model',
            );

            $this->assertFalse($result->isFailure());
            $this->assertSame(1, $platform->invokes);
            $found = false;
            foreach ($result->messages as $message) {
                foreach ($message->content as $block) {
                    if (('text' === ($block['type'] ?? '')) && str_contains((string) ($block['text'] ?? ''), 'MODEL_SNAPSHOT_SUMMARY')) {
                        $found = true;
                    }
                }
            }
            $this->assertTrue($found);
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
            TestDirectoryIsolation::removeDirectory($homeDir);
        }
    }

    private function createModelSelectionStub(): ModelSelectionService
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: '/',
        );
        $sessionMetaRc = new \ReflectionClass(HatfieldSessionStore::class);
        $sessionMetaStore = $sessionMetaRc->newInstanceWithoutConstructor();
        $modelResolver = new ModelResolver($appConfig, $sessionMetaStore);
        $settingsWriter = (new \ReflectionClass(SettingsOverrideWriter::class))->newInstanceWithoutConstructor();

        return new ModelSelectionService($appConfig, $modelResolver, $settingsWriter, $sessionMetaStore);
    }
}
