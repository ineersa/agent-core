<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\BuildCompactionMemoryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: OM public compaction hook dispatches JSON-safe jobs and polls OM SQLite only;
 * success returns replacement, timeout/failure cancel without model/history calls.
 */
final class OmBeforeCompactionHookTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-before-compact');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testSuccessPathReturnsReplacementFromResultRow(): void
    {
        $settings = OmSettings::fromArray([
            'enabled' => true,
            'observer' => ['model' => 'llama_cpp_test/test', 'schema_version' => 'o1', 'renderer_version' => 'r1'],
            'reflector' => ['model' => 'llama_cpp_test/test'],
        ]);
        $api = new class($this->projectDir, $settings) implements ExtensionApiInterface {
            /** @var list<ExtensionAgentJobRequestDTO> */
            public array $jobs = [];

            public int $agentCalls = 0;

            public function __construct(
                private readonly string $cwd,
                private readonly OmSettings $settings,
            ) {
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function getSettings(string $key): array
            {
                return [
                    'enabled' => true,
                    'observer' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'o1',

                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'rv1',
                        'context_window_ratio' => 0.65,
                    ],
                    'pools' => [
                    ],
                    'compaction' => [
                    ],
                ];
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                $self = $this;

                return new class($self) implements AgentRunnerInterface {
                    public function __construct(private readonly object $parent)
                    {
                    }

                    public function run(AgentCallRequestDTO $request): void
                    {
                        ++$this->parent->agentCalls;
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 128000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                return new class implements SessionEventReaderInterface {
                    public function readRange(string $runId, int $startSeq, int $endSeq): iterable
                    {
                        return [];
                    }
                };
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
                $this->jobs[] = $request;
                $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($this->settings, $this->cwd);
                $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, new NullLogger());
                $repo = new CompactionRepository($connection);
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
                $payload = $request->payload;
                $repo->commitSuccess(
                    requestId: (string) $payload['request_id'],
                    resultId: 'result-1',
                    runId: (string) $payload['run_id'],
                    requiredStartSeq: (int) $payload['required_start_seq'],
                    requiredEndSeq: (int) $payload['required_end_seq'],
                    requiredWatermark: (int) $payload['required_end_seq'],
                    requestFingerprint: (string) $payload['request_fingerprint'],
                    observationSetHash: 'obs-set',
                    replacementText: 'OM replacement summary',
                    reflectorModel: 'llama_cpp_test/test',
                    reflectorSchemaVersion: 'om-reflector-v1',
                    reflections: [],
                    now: $now,
                    metadata: [
                        'source' => 'test',
                        'compression_level' => 0,
                        'observation_count' => 3,
                        'reflection_count' => 1,
                    ],
                );
            }
        };

        $hook = new OmBeforeCompactionHook($api, $settings, new NullLogger(), pollIntervalMicros: 1_000);
        $result = $hook->beforeCompaction(new BeforeCompactionHookContextDTO(
            runId: 'run-1',
            turnNo: 1,
            trigger: 'manual',
            requiredStartSeq: 1,
            requiredEndSeq: 10,
            tokenEstimateBefore: 100,
            messagesCompacted: 3,
            messagesRetained: 2,
            firstRetainedIndex: 3,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: 'provider/model',
            thinkingLevel: null,
        ));

        $this->assertTrue($result->hasReplacementSummary());
        $this->assertSame('OM replacement summary', $result->replacementSummary);
        $this->assertCount(1, $api->jobs);
        $this->assertSame(BuildCompactionMemoryJobHandler::HANDLER_ID, $api->jobs[0]->handlerId);
        $this->assertSame(0, $api->agentCalls);
        $this->assertArrayHasKey('request_id', $api->jobs[0]->payload);
        $this->assertSame(1, $api->jobs[0]->payload['required_start_seq']);
        $this->assertSame(10, $api->jobs[0]->payload['required_end_seq']);
        $this->assertSame('observational_memory', $result->metadata['om_source'] ?? null);
        $this->assertIsArray($result->metadata['om_provenance'] ?? null);
        $this->assertSame(0, $result->metadata['om_provenance']['compression_level'] ?? null);
        $this->assertSame(3, $result->metadata['om_provenance']['observation_count'] ?? null);
        $this->assertSame(1, $result->metadata['om_provenance']['reflection_count'] ?? null);
    }

    public function testTimeoutCancelsWithoutPersistingTimedOutState(): void
    {
        $settings = OmSettings::fromArray([
            'enabled' => true,
            'observer' => ['model' => 'llama_cpp_test/test', 'schema_version' => 'o1', 'renderer_version' => 'r1'],
            'reflector' => ['model' => 'llama_cpp_test/test'],
        ]);
        $api = new class($this->projectDir) implements ExtensionApiInterface {
            public int $dispatches = 0;

            public function __construct(private readonly string $cwd)
            {
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function getSettings(string $key): array
            {
                return [
                    'enabled' => true,
                    'observer' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'o1',

                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'rv1',
                        'context_window_ratio' => 0.65,
                    ],
                    'pools' => [
                    ],
                    'compaction' => [
                    ],
                ];
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                return new class implements AgentRunnerInterface {
                    public function run(AgentCallRequestDTO $request): void
                    {
                        throw new \LogicException('agent run must not be called on hot path');
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 128000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                throw new \LogicException('sessionEvents must not be called on hot path');
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
                ++$this->dispatches;
            }
        };

        $hook = new OmBeforeCompactionHook($api, $settings, new NullLogger(), pollIntervalMicros: 20_000);
        $result = $hook->beforeCompaction(new BeforeCompactionHookContextDTO(
            runId: 'run-timeout',
            turnNo: 1,
            trigger: 'auto',
            requiredStartSeq: 1,
            requiredEndSeq: 5,
            tokenEstimateBefore: 100,
            messagesCompacted: 2,
            messagesRetained: 1,
            firstRetainedIndex: 2,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: null,
            thinkingLevel: null,
        ));

        $this->assertTrue($result->cancels());
        $this->assertStringContainsString('timed out', (string) $result->cancelReason);
        $this->assertSame(1, $api->dispatches);

        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = OmDatabaseFactory::connect($dbPath, new NullLogger());
        $failedTimedOut = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM om_compaction_result WHERE status = 'failed' AND failure_code = 'timed_out'",
        );
        $this->assertSame(0, $failedTimedOut);
    }

    public function testDurableFailedResultCancelsImmediatelyWithoutRedispatchWait(): void
    {
        $settings = OmSettings::fromArray([
            'enabled' => true,
            'observer' => ['model' => 'llama_cpp_test/test', 'schema_version' => 'o1', 'renderer_version' => 'r1'],
            'reflector' => ['model' => 'llama_cpp_test/test'],
        ]);
        $api = new class($this->projectDir, $settings) implements ExtensionApiInterface {
            public int $dispatches = 0;

            public function __construct(
                private readonly string $cwd,
                private readonly OmSettings $settings,
            ) {
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function getSettings(string $key): array
            {
                return [
                    'enabled' => true,
                    'observer' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'o1',

                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'rv1',
                        'context_window_ratio' => 0.65,
                    ],
                    'pools' => [
                    ],
                    'compaction' => [
                    ],
                ];
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                return new class implements AgentRunnerInterface {
                    public function run(AgentCallRequestDTO $request): void
                    {
                        throw new \LogicException('agent run must not be called on hot path');
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 128000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                throw new \LogicException('sessionEvents must not be called on hot path');
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
                ++$this->dispatches;
            }

            public function seedFailedRequest(string $requestId, string $runId, int $endSeq, string $fingerprint): void
            {
                $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($this->settings, $this->cwd);
                $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, new NullLogger());
                $repo = new CompactionRepository($connection);
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
                $repo->ensureRequest($requestId, $runId, 1, $endSeq, $endSeq, $fingerprint, $now);
                $repo->commitFailure(
                    requestId: $requestId,
                    resultId: 'fail-result',
                    runId: $runId,
                    requiredStartSeq: 1,
                    requiredEndSeq: $endSeq,
                    requiredWatermark: $endSeq,
                    requestFingerprint: $fingerprint,
                    failureCode: 'no_observations',
                    now: $now,
                );
            }
        };

        // Pre-seed using the same deterministic request id the hook will compute.
        $context = new BeforeCompactionHookContextDTO(
            runId: 'run-failed',
            turnNo: 1,
            trigger: 'manual',
            requiredStartSeq: 1,
            requiredEndSeq: 4,
            tokenEstimateBefore: 100,
            messagesCompacted: 2,
            messagesRetained: 1,
            firstRetainedIndex: 2,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: null,
            thinkingLevel: null,
        );
        $fingerprint = \Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity::compactionRequestFingerprint([
            'run_id' => $context->runId,
            'required_start_seq' => $context->requiredStartSeq,
            'required_end_seq' => $context->requiredEndSeq,
            'required_watermark' => $context->requiredEndSeq,
            'custom_instructions' => $context->customInstructions ?? '',
            'observer_model' => 'llama_cpp_test/test',
            'observer_context_window' => 128000,
            'observer_context_window_ratio' => $settings->observerContextWindowRatio,
            'renderer_version' => $settings->rendererVersion,
            'observer_schema_version' => $settings->observerSchemaVersion,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_context_window' => 128000,
            'reflector_context_window_ratio' => $settings->reflectorContextWindowRatio,
            'reflector_schema_version' => $settings->reflectorSchemaVersion,
            'observations_max_tokens' => $settings->observationsMaxTokens,
            'reflections_max_tokens' => $settings->reflectionsMaxTokens,
        ]);
        $requestId = \Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity::compactionRequestId(
            $context->runId,
            $context->requiredStartSeq,
            $context->requiredEndSeq,
            $fingerprint,
        );
        $api->seedFailedRequest($requestId, $context->runId, $context->requiredEndSeq, $fingerprint);

        $started = microtime(true);
        $hook = new OmBeforeCompactionHook($api, $settings, new NullLogger(), pollIntervalMicros: 50_000);
        $result = $hook->beforeCompaction($context);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->cancels());
        $this->assertStringContainsString('no_observations', (string) $result->cancelReason);
        $this->assertSame(0, $api->dispatches, 'durable failed result must not redispatch');
        $this->assertLessThan(2.0, $elapsed, 'must cancel immediately without timeout wait');
    }

    public function testTerminalWithoutResultCancelsImmediatelyWithoutDispatch(): void
    {
        $settings = OmSettings::fromArray([
            'enabled' => true,
            'observer' => ['model' => 'llama_cpp_test/test', 'schema_version' => 'o1', 'renderer_version' => 'r1'],
            'reflector' => ['model' => 'llama_cpp_test/test'],
        ]);
        $api = new class($this->projectDir, $settings) implements ExtensionApiInterface {
            public int $dispatches = 0;

            public function __construct(
                private readonly string $cwd,
                private readonly OmSettings $settings,
            ) {
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function getSettings(string $key): array
            {
                return [
                    'enabled' => true,
                    'observer' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'o1',

                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'rv1',
                        'context_window_ratio' => 0.65,
                    ],
                    'pools' => [
                    ],
                    'compaction' => [
                    ],
                ];
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                return new class implements AgentRunnerInterface {
                    public function run(AgentCallRequestDTO $request): void
                    {
                        throw new \LogicException('agent run must not be called on hot path');
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 128000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                throw new \LogicException('sessionEvents must not be called on hot path');
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
                ++$this->dispatches;
            }

            public function seedTerminalRequestWithoutResult(string $requestId, string $runId, int $endSeq, string $fingerprint): void
            {
                $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($this->settings, $this->cwd);
                $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, new NullLogger());
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
                $connection->insert('om_compaction_request', [
                    'request_id' => $requestId,
                    'run_id' => $runId,
                    'required_start_seq' => 1,
                    'required_end_seq' => $endSeq,
                    'required_watermark' => $endSeq,
                    'request_fingerprint' => $fingerprint,
                    'observation_set_hash' => null,
                    'status' => CompactionRepository::STATUS_FAILED,
                    'requested_at' => $now,
                    'updated_at' => $now,
                    'completed_at' => $now,
                    'failure_code' => 'corrupt_state',
                    'failure_metadata_json' => null,
                ]);
            }
        };

        $context = new BeforeCompactionHookContextDTO(
            runId: 'run-terminal-gap',
            turnNo: 1,
            trigger: 'manual',
            requiredStartSeq: 1,
            requiredEndSeq: 3,
            tokenEstimateBefore: 100,
            messagesCompacted: 1,
            messagesRetained: 1,
            firstRetainedIndex: 1,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: null,
            thinkingLevel: null,
        );
        $fingerprint = \Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity::compactionRequestFingerprint([
            'run_id' => $context->runId,
            'required_start_seq' => $context->requiredStartSeq,
            'required_end_seq' => $context->requiredEndSeq,
            'required_watermark' => $context->requiredEndSeq,
            'custom_instructions' => $context->customInstructions ?? '',
            'observer_model' => 'llama_cpp_test/test',
            'observer_context_window' => 128000,
            'observer_context_window_ratio' => $settings->observerContextWindowRatio,
            'renderer_version' => $settings->rendererVersion,
            'observer_schema_version' => $settings->observerSchemaVersion,
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_context_window' => 128000,
            'reflector_context_window_ratio' => $settings->reflectorContextWindowRatio,
            'reflector_schema_version' => $settings->reflectorSchemaVersion,
            'observations_max_tokens' => $settings->observationsMaxTokens,
            'reflections_max_tokens' => $settings->reflectionsMaxTokens,
        ]);
        $requestId = \Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity::compactionRequestId(
            $context->runId,
            $context->requiredStartSeq,
            $context->requiredEndSeq,
            $fingerprint,
        );
        $api->seedTerminalRequestWithoutResult($requestId, $context->runId, $context->requiredEndSeq, $fingerprint);

        $started = microtime(true);
        $hook = new OmBeforeCompactionHook($api, $settings, new NullLogger(), pollIntervalMicros: 50_000);
        $result = $hook->beforeCompaction($context);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->cancels());
        $this->assertStringContainsString('no result row', (string) $result->cancelReason);
        $this->assertSame(0, $api->dispatches);
        $this->assertLessThan(2.0, $elapsed);
    }

    public function testMalformedResultMetadataCancelsWithoutLeakingRawContent(): void
    {
        $settings = OmSettings::fromArray([
            'enabled' => true,
            'observer' => ['model' => 'llama_cpp_test/test', 'schema_version' => 'o1', 'renderer_version' => 'r1'],
            'reflector' => ['model' => 'llama_cpp_test/test'],
        ]);
        $api = new class($this->projectDir, $settings) implements ExtensionApiInterface {
            public int $dispatches = 0;

            public function __construct(
                private readonly string $cwd,
                private readonly OmSettings $settings,
            ) {
            }

            public function registerTool(ToolRegistrationDTO $tool): void
            {
            }

            public function registerToolCallHook(ToolCallHookInterface $hook): void
            {
            }

            public function registerToolResultHook(ToolResultHookInterface $hook): void
            {
            }

            public function getSettings(string $key): array
            {
                return [
                    'enabled' => true,
                    'observer' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'o1',

                        'context_window_ratio' => 0.65,
                    ],
                    'reflector' => [
                        'model' => 'llama_cpp_test/test',
                        'schema_version' => 'rv1',
                        'context_window_ratio' => 0.65,
                    ],
                    'pools' => [
                    ],
                    'compaction' => [
                    ],
                ];
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                return new class implements AgentRunnerInterface {
                    public function run(AgentCallRequestDTO $request): void
                    {
                        throw new \LogicException('agent run must not be called on hot path');
                    }

                    public function contextWindow(string $exactModel): ?int
                    {
                        return 128000;
                    }
                };
            }

            public function sessionEvents(): SessionEventReaderInterface
            {
                throw new \LogicException('unused');
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
                ++$this->dispatches;
                $paths = \Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths::fromSettings($this->settings, $this->cwd);
                $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, new NullLogger());
                $repo = new CompactionRepository($connection);
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
                $payload = $request->payload;
                $repo->commitSuccess(
                    requestId: (string) $payload['request_id'],
                    resultId: 'result-bad-meta',
                    runId: (string) $payload['run_id'],
                    requiredStartSeq: (int) $payload['required_start_seq'],
                    requiredEndSeq: (int) $payload['required_end_seq'],
                    requiredWatermark: (int) $payload['required_end_seq'],
                    requestFingerprint: (string) $payload['request_fingerprint'],
                    observationSetHash: 'obs-set',
                    replacementText: 'text ok',
                    reflectorModel: 'llama_cpp_test/test',
                    reflectorSchemaVersion: 'om-reflector-v1',
                    reflections: [],
                    now: $now,
                    metadata: null,
                );
                // Corrupt metadata after insert to prove fail-closed decoding.
                $connection->executeStatement(
                    'UPDATE om_compaction_result SET metadata_json = ? WHERE request_id = ?',
                    ['not-json-at-all SECRET_PROMPT_LEAK', (string) $payload['request_id']],
                );
            }
        };

        $hook = new OmBeforeCompactionHook($api, $settings, new NullLogger(), pollIntervalMicros: 1_000);
        $result = $hook->beforeCompaction(new BeforeCompactionHookContextDTO(
            runId: 'run-bad-meta',
            turnNo: 1,
            trigger: 'manual',
            requiredStartSeq: 1,
            requiredEndSeq: 2,
            tokenEstimateBefore: 50,
            messagesCompacted: 1,
            messagesRetained: 1,
            firstRetainedIndex: 1,
            priorSummaryPresent: false,
            customInstructions: null,
            resolvedModel: null,
            thinkingLevel: null,
        ));

        $this->assertTrue($result->cancels());
        $this->assertStringContainsString('metadata', (string) $result->cancelReason);
        $this->assertStringNotContainsString('SECRET_PROMPT_LEAK', (string) $result->cancelReason);
        $this->assertSame(1, $api->dispatches);
    }
}
