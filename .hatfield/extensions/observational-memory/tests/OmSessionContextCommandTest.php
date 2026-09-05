<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
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
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmStatusCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmViewCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Thesis: session id is resolved lazily from the live TUI context at command
 * invocation; registration-time string caching is forbidden. Hosts use
 * plain notify(); host maps info to markdown style; failures surface a fixed safe
 * message without exception text.
 */
final class OmSessionContextCommandTest extends IsolatedKernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('om-session-cmd');
    }

    protected function tearDown(): void
    {
        \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function requireSessionIdReadsLiveContextAfterRegistrationBind(): void
    {
        $sessionContext = new OmSessionContext();
        $tui = $this->mutableTui('session-at-register');
        $sessionContext->bindTui($tui);

        $tui->sessionId = 'session-after-register';
        $this->assertSame('session-after-register', $sessionContext->requireSessionId());

        $tui->sessionId = 'session-later-again';
        $this->assertSame('session-later-again', $sessionContext->requireSessionId());
    }

    #[Test]
    public function statusCommandUsesSessionIdResolvedAtInvocation(): void
    {
        $sessionContext = new OmSessionContext();
        $tui = $this->mutableTui('session-at-register');
        $sessionContext->bindTui($tui);
        $tui->sessionId = 'session-after-register';

        $seenRunId = null;
        $handler = new class($sessionContext, $seenRunId) implements ExtensionCommandHandlerInterface {
            public function __construct(
                private OmSessionContext $sessionContext,
                private ?string &$seenRunId,
            ) {
            }

            public function handle(string $args, CommandContextInterface $context): void
            {
                $this->seenRunId = $this->sessionContext->requireSessionId();
                $context->notify('ok:'.$this->seenRunId, 'info');
            }
        };

        $messages = [];
        $handler->handle('', $this->collectingContext($messages));

        $this->assertSame('session-after-register', $seenRunId);
        $this->assertSame(['info:ok:session-after-register'], $messages);
    }

    #[Test]
    public function infoNotifyIsUsedForStatusAndView(): void
    {
        $runId = 'om-status-view-run';
        $dbPath = $this->seedObservation($runId);
        $sessionContext = new OmSessionContext();
        $sessionContext->bindTui($this->mutableTui($runId));

        $query = new OmQueryService($this->apiWithCwd($this->tmpDir), OmSettings::fromArray([
            'storage' => ['database' => $dbPath],
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [],
        ]));

        $statusMessages = [];
        (new OmStatusCommandHandler($query, $sessionContext))->handle('', $this->collectingContext($statusMessages));
        $this->assertCount(1, $statusMessages);
        $this->assertStringStartsWith('info:', $statusMessages[0]);
        $this->assertStringContainsString('## Observational memory', $statusMessages[0]);

        $viewMessages = [];
        (new OmViewCommandHandler($query, $sessionContext))->handle('', $this->collectingContext($viewMessages));
        $this->assertCount(1, $viewMessages);
        $this->assertStringStartsWith('info:', $viewMessages[0]);
        $this->assertStringContainsString('## Observations', $viewMessages[0]);
        $this->assertStringContainsString('`[aaaaaaaaaaaa]`', $viewMessages[0]);
    }

    #[Test]
    public function statusCommandEmitsFixedErrorWithoutExceptionTextWhenSessionMissing(): void
    {
        $sessionContext = new OmSessionContext();
        $handler = new OmStatusCommandHandler(
            new OmQueryService($this->unusedApi(), OmSettings::fromArray([
                'model' => 'llama_cpp_test/test',
                'observer' => [],
                'reflector' => [],
            ])),
            $sessionContext,
        );

        $messages = [];
        $handler->handle('', $this->collectingContext($messages));

        $this->assertSame(['error:OM status unavailable.'], $messages);
        $this->assertStringNotContainsString('RuntimeException', implode("\n", $messages));
        $this->assertStringNotContainsString('No active TUI session', implode("\n", $messages));
    }

    #[Test]
    public function statusCommandStaysSafeWhenLazyGetSessionIdThrows(): void
    {
        $sessionContext = new OmSessionContext();
        $sessionContext->bindTui($this->throwingTui());
        $handler = new OmStatusCommandHandler(
            new OmQueryService($this->unusedApi(), OmSettings::fromArray([
                'model' => 'llama_cpp_test/test',
                'observer' => [],
                'reflector' => [],
            ])),
            $sessionContext,
        );

        $messages = [];
        $handler->handle('', $this->collectingContext($messages));

        $this->assertSame(['error:OM status unavailable.'], $messages);
        $this->assertStringNotContainsString('lazy session boom', implode("\n", $messages));
    }

    private function seedObservation(string $runId): string
    {
        $dbPath = $this->tmpDir.'/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath);
        $observations = new ObservationRepository($connection);
        $settings = OmSettings::fromArray([
            'model' => 'llama_cpp_test/test',
            'observer' => [],
            'reflector' => [],
        ]);
        $observationId = str_repeat('a', 64);
        $observations->commitChunkPartCoverage(
            coverageKey: 'cov-'.$runId,
            runId: $runId,
            boundaryKey: 'boundary-'.$runId,
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'chunk-'.$runId,
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-'.$runId,
            partDigest: 'part-'.$runId,
            rendererVersion: $settings->rendererVersion,
            observerSchemaVersion: $settings->observerSchemaVersion,
            observerModel: 'llama_cpp_test/test',
            observations: [[
                'observation_id' => $observationId,
                'content' => 'hyphenated commands are preferred',
                'content_hash' => hash('sha256', 'hyphenated commands are preferred'),
                'relevance' => 'high',
                'timestamp' => '2026-07-28 12:00',
                'token_count' => 12,
                'source_refs_json' => json_encode([['run_id' => $runId, 'seq' => 2]], \JSON_THROW_ON_ERROR),
            ]],
            coveredAt: '2026-07-28T12:00:00+00:00',
        );

        return $dbPath;
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }

    private function throwingTui(): object
    {
        return new class implements TuiExtensionContextInterface {
            public function getSessionId(): string
            {
                throw new \RuntimeException('lazy session boom');
            }

            public function requestRender(bool $force = false): void
            {
            }

            public function setStatus(string $key, ?string $text): void
            {
            }

            public function onTick(\Closure $listener): void
            {
            }

            public function insertOverlayAfterEditor(AbstractWidget $widget): void
            {
            }

            public function removeOverlay(AbstractWidget $widget): void
            {
            }

            public function setFocus(AbstractWidget $widget): void
            {
            }

            public function formatMuted(string $text): string
            {
                return $text;
            }

            public function formatRolePrefix(string $displayRole): string
            {
                return $displayRole;
            }

            public function turnRowsInDisplayOrder(string $sessionId): array
            {
                return [];
            }
        };
    }

    private function mutableTui(string $sessionId): object
    {
        return new class($sessionId) implements TuiExtensionContextInterface {
            public function __construct(public string $sessionId)
            {
            }

            public function getSessionId(): string
            {
                return $this->sessionId;
            }

            public function requestRender(bool $force = false): void
            {
            }

            public function setStatus(string $key, ?string $text): void
            {
            }

            public function onTick(\Closure $listener): void
            {
            }

            public function insertOverlayAfterEditor(AbstractWidget $widget): void
            {
            }

            public function removeOverlay(AbstractWidget $widget): void
            {
            }

            public function setFocus(AbstractWidget $widget): void
            {
            }

            public function formatMuted(string $text): string
            {
                return $text;
            }

            public function formatRolePrefix(string $displayRole): string
            {
                return $displayRole;
            }

            public function turnRowsInDisplayOrder(string $sessionId): array
            {
                return [];
            }
        };
    }

    private function apiWithCwd(string $cwd): ExtensionApiInterface
    {
        return new class($cwd) implements ExtensionApiInterface {
            public function __construct(private string $cwd)
            {
            }

            public function getCwd(): string
            {
                return $this->cwd;
            }

            public function getSettings(string $key): array
            {
                return [];
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

            public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
            {
            }

            public function registerPromptContributor(PromptContributorInterface $contributor): void
            {
            }

            public function registerSkill(string $skillDirectory): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerSessionStartHook(\Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
            }

            public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
            {
            }

            public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
            {
            }

            public function agent(): AgentRunnerInterface
            {
                throw new \LogicException('unused');
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

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }
        };
    }

    private function unusedApi(): ExtensionApiInterface
    {
        return $this->apiWithCwd($this->tmpDir);
    }

    /**
     * @param list<string> $messages
     */
    private function collectingContext(array &$messages): CommandContextInterface
    {
        return new class($messages) implements CommandContextInterface {
            /**
             * @param list<string> $messages
             */
            public function __construct(private array &$messages)
            {
            }

            public function notify(string $message, string $level = 'info'): void
            {
                $this->messages[] = $level.':'.$message;
            }
        };
    }
}
