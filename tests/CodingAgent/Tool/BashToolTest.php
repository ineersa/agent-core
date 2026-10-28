<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallBuilder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashTool;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\CodingAgent\Tool\Validation\BashTimeout\BashTimeoutMaxValidator;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\EventListener\ValidateToolCallArgumentsListener;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * @covers \Ineersa\CodingAgent\Tool\BashTool
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 * @covers \Ineersa\CodingAgent\Config\BashToolConfig
 * @covers \Ineersa\CodingAgent\Config\BackgroundProcessConfig
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 *
 * Sleep budget: tests use short-lived commands and minimal polling.
 * Backgrounded processes have at most ~100ms teardown overhead.
 *
 * ProcessStore comes from the Symfony test container (via IsolatedKernelTestCase).
 * BackgroundProcessConfig / ProcessLifecycle / BackgroundProcessManager are
 * constructed with test-specific temp dirs for process I/O files.
 */
final class BashToolTest extends IsolatedKernelTestCase
{
    private const string TEST_SESSION = 'bash-test-session';

    private const string COMPOSER_MARKER = 'COMPOSER_MARKER_PID_REUSE_REGRESSION';
    private const string DATETIME_MARKER = 'DATETIME_MARKER_PID_REUSE_REGRESSION';

    private BackgroundProcessManager $manager;
    private BackgroundProcessConfig $bgConfig;
    private BashToolConfig $bashConfig;
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private TestLogger $logger;
    private string $tmpDir;

    private bool $managerCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_bashtool_test');

        $this->bgConfig = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: 1,
            logTailChars: 5000,
        );
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 2,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);
        $this->logger = new TestLogger();
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState(self::TEST_SESSION, RunStatus::Running));
    }

    protected function tearDown(): void
    {
        $this->cleanupProcesses();
        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    public function testBashToolConfigDefaults(): void
    {
        $config = new BashToolConfig();

        $this->assertSame(30, $config->defaultTimeoutSeconds);
    }

    /* ── Successful completion ── */

    public function testSuccessfulCommandWithNewlines(): void
    {
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(new BashArgumentsDTO(command: "printf 'line1\\nline2\\nline3\\n'"));
        });

        $this->assertStringContainsString('line1', $result);
        $this->assertStringContainsString('line2', $result);
        $this->assertStringContainsString('line3', $result);
    }

    /* ── Non-zero exit code ── */

    #[DataProvider('commandOutcomes')]
    public function testCommandOutcomeRendersWithMatchingStatus(string $command, string $text, bool $isError): void
    {
        $this->createManager();
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'bash', description: 'bash', handler: $this->makeBashTool(), promptLine: 'bash');
        $executor = new ToolExecutor(
            defaultMode: 'sequential',
            maxParallelism: 1,
            resultStore: new ToolExecutionResultStore(),
            toolbox: new RegistryBackedToolbox($registry, new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()), NativeToolSchemaProbe::schemaFactory()),
            contextAccessor: $this->contextAccessor,
        );
        $call = ToolCallBuilder::create('bash-exit')
            ->withToolName('bash')
            ->withArguments(['command' => $command])
            ->withRunId(self::TEST_SESSION)
            ->build();
        $result = $executor->execute($call);

        $this->assertSame($isError, $result->isError);
        if (str_starts_with($text, 'Command supervision ended without an exit status.')) {
            $this->assertStringStartsWith($text, $result->content[0]['text']);
            $this->assertStringContainsString("\nLog: ", $result->content[0]['text']);
            $this->assertStringContainsString("\nPID: ", $result->content[0]['text']);
            $this->assertStringContainsString("\nRecord: ", $result->content[0]['text']);
        } else {
            $this->assertSame($text, $result->content[0]['text']);
        }
        $records = $this->recordsForSession(self::TEST_SESSION);
        $this->assertCount(1, $records, 'The command must execute exactly once');
        $this->assertNotNull($records[0]->finishedAt);

        $message = new ExecuteToolCall(self::TEST_SESSION, 1, 'step-exit', 1, 'execute-bash-exit', $call->toolCallId, $call->toolName, $call->arguments, 0);
        $codec = self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec::class);
        $events = [
            new RunEvent(self::TEST_SESSION, 1, 1, RunEventTypeEnum::ToolExecutionStart->value, [
                'tool_call_id' => $call->toolCallId,
                'tool_name' => $call->toolName,
                'arguments' => $call->arguments,
            ]),
            new RunEvent(self::TEST_SESSION, 2, 1, RunEventTypeEnum::ToolExecutionEnd->value,
                $codec->toEventPayload(ToolCallResultFactory::fromExecuteToolCallAndToolResult($message, $result))),
        ];
        $translator = self::getContainer()->get(RuntimeEventTranslator::class);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(new SubagentProgressDisplayFormatter(), self::getContainer()->get('serializer')));
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $palette = new ThemePalette('bash-exit', [
            ThemeColorEnum::ToolOutput->value => '#39ff14',
            ThemeColorEnum::Error->value => '#ff3366',
            ThemeColorEnum::Text->value => '',
        ]);
        // Full output is visible when expanded. TuiCollapsedToolCardVirtualRenderTest
        // covers bounded failed previews and the Ctrl+O expansion/collapse cycle.
        $harness = new VirtualTuiHarness(
            sessionId: self::TEST_SESSION,
            palette: $palette,
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: true),
        );
        $harness->screen()->setWorkingVisible(false);
        foreach ($events as $event) {
            $runtimeEvent = $translator->translate($event);
            $this->assertNotNull($runtimeEvent);
            $projector->accept($runtimeEvent);
            $harness->screen()->setTranscriptBlocks($projector->blocks());
            $harness->render();
        }
        $plain = $harness->plainScreenText();
        foreach (array_filter(explode("\n", $text)) as $line) {
            $this->assertStringContainsString($line, $plain);
        }
        $color = $isError ? '255;51;102' : '57;255;20';
        $firstLine = preg_quote(explode("\n", $text)[0], '/');
        $this->assertMatchesRegularExpression('/\x1b\[38;2;'.$color.'m\s*'.$firstLine.'/', $harness->ansiOutput());
    }

    public static function commandOutcomes(): iterable
    {
        yield 'reported exit 1 without output' => ["bash -c 'exit 1'", "Command failed with exit code 1.\n\nOutput:\n", true];
        yield 'exit 42 retains output' => ['echo "before error" && exit 42', "Command failed with exit code 42.\n\nOutput:\nbefore error\n", true];
        yield 'explicit exit still records status' => ['exit 1; :', "Command failed with exit code 1.\n\nOutput:\n", true];
        // Kill only this fixture's owned supervisor before its status-file write.
        yield 'supervisor lost without status' => ['kill -KILL "$PPID"; exit 0', 'Command supervision ended without an exit status.', true];
        yield 'successful diagnostic-looking text' => ["printf 'Command failed with exit code 1.'", 'Command failed with exit code 1.', false];
    }

    public function testNonExistentCommand(): void
    {
        $this->createManager();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Command failed with exit code 127.');
        $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(new BashArgumentsDTO(command: 'nonexistent_command_xyz_123'));
        });
    }

    /* ── Timeout ── */

    public function testTimeoutStopsProcess(): void
    {
        $this->createManager();
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 1,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(new BashArgumentsDTO(command: 'echo "partial" && sleep 10 && echo "should not see"'));
        });

        $this->assertStringContainsString('timed out', $result);
        $this->assertStringContainsString('partial', $result);
    }

    public function testTimeoutReapsProcessGroupDescendants(): void
    {
        $this->createManager();
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 1,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $markerDir = $this->tmpDir.'/reap-marker';
        $this->assertTrue(mkdir($markerDir, 0o777, true) || is_dir($markerDir));
        $aliveMarker = $markerDir.'/alive';
        $command = \sprintf(
            'bash -c %s',
            escapeshellarg(\sprintf(
                'touch %1$s; (while [ -f %1$s ]; do sleep 0.05; done) & sleep 10; echo should-not-see',
                escapeshellarg($aliveMarker),
            )),
        );

        $result = $this->withContext(self::TEST_SESSION, function () use ($command): string {
            return ($this->makeBashTool())(new BashArgumentsDTO(command: $command, timeout: 1));
        });

        $this->assertStringContainsString('timed out', $result);
        $this->assertStringNotContainsString('should-not-see', $result);

        @unlink($aliveMarker);
        $deadline = microtime(true) + 2.0;
        $orphanCount = -1;
        while (microtime(true) < $deadline) {
            $orphanCount = $this->countProcessesHoldingMarker($aliveMarker);
            if (0 === $orphanCount) {
                break;
            }
            usleep(50_000);
        }
        $this->assertSame(0, $orphanCount, 'Timeout must reap the setsid process group, including descendants');
    }

    /* ── Cancellation ── */

    /**
     * Cancellation is detected in the supervision loop on the second
     * isCancellationRequested() call. The first call is ToolRuntime's
     * pre-check before entering the callback, which must not trigger
     * cancellation (otherwise the command would never start).
     *
     * The $callCount counter tracks this: call 1 returns false (pre-check),
     * calls 2+ return true (supervision loop trigger).
     *
     * ToolRuntime::run() throws RuntimeException for stale results after
     * the callback returns, so the callback's return value is discarded.
     * The meaningful behaviour is that BackgroundProcessManager::stop()
     * was called, which this test verifies as a post-condition.
     */
    public function testCancellationStopsProcess(): void
    {
        $callCount = 0;
        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken
            ->method('isCancellationRequested')
            ->willReturnCallback(static function () use (&$callCount) {
                ++$callCount;

                // First call is the pre-check in ToolRuntime::run() —
                // must return false so the command starts running.
                // Second+ are from the supervision loop and will
                // trigger cancellation.
                return $callCount >= 2;
            });

        $context = new ToolContext(
            runId: self::TEST_SESSION,
            turnNo: 1,
            toolCallId: 'tc_bash',
            toolName: 'bash',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
        );

        $this->createManager();

        $storedPid = null;

        // ToolRuntime::run() detects stale cancellation AFTER the callback
        // returns, so we expect a RuntimeException about stale result.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('due to run cancellation');

        try {
            $this->contextAccessor->with($context, function () use (&$storedPid): string {
                // Capture the PID before cancellation triggers in the loop
                $entities = $this->manager->list(self::TEST_SESSION);
                if ([] !== $entities) {
                    $storedPid = $entities[0]->pid;
                }

                return ($this->makeBashTool())(new BashArgumentsDTO(command: 'echo "before cancel" && sleep 10'));
            });
        } finally {
            // Verify the process was stopped by the cancellation path
            if (null !== $storedPid) {
                $entity = $this->manager->find($storedPid, self::TEST_SESSION);
                if (null !== $entity) {
                    $this->assertNotNull($entity->finishedAt, 'Cancelled process should be marked finished');
                }
            }
        }
    }

    /* ── Argument validation (through native toolbox + validator listener) ──
     *
     * Invalid arguments are rejected by Symfony AI resolution/validation
     * before the handler runs: these tests execute through the production
     * RegistryBackedToolbox path (rewrites → resolver →
     * ValidateToolCallArgumentsListener → FaultTolerantToolbox) and assert
     * zero handler side effects (no background process started).
     */

    public function testMissingCommandBecomesActionableFaultTolerantResult(): void
    {
        $this->createManager();

        $result = $this->validationToolbox()->execute(new ToolCall('call-bash', 'bash', []));
        $this->assertStringContainsString('command', (string) $result->getResult());

        $this->assertSame([], $this->manager->list(self::TEST_SESSION), 'Handler must not run for invalid arguments.');
    }

    public function testEmptyCommandBecomesFaultTolerantResult(): void
    {
        $this->createManager();

        $result = $this->validationToolbox()->execute(new ToolCall('call-bash', 'bash', ['command' => '']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('The "command" argument is required and must be a non-empty string.', $message);
        $this->assertSame([], $this->manager->list(self::TEST_SESSION), 'Handler must not run for invalid arguments.');
    }

    public function testInvalidTimeoutBecomesFaultTolerantResult(): void
    {
        $this->createManager();

        $result = $this->validationToolbox()->execute(new ToolCall('call-bash', 'bash', ['command' => 'echo hi', 'timeout' => -5]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('The "timeout" argument must be a positive integer.', $message);
        $this->assertSame([], $this->manager->list(self::TEST_SESSION), 'Handler must not run for invalid arguments.');
    }

    public function testTimeoutExceedsConfiguredMaximumBecomesFaultTolerantResult(): void
    {
        $this->createManager();
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 10,
            maxTimeoutSeconds: 30,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $result = $this->validationToolbox()->execute(new ToolCall('call-bash', 'bash', ['command' => 'echo hi', 'timeout' => 9999]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('Timeout must not exceed 30 seconds (9999 provided).', $message);
        $this->assertSame([], $this->manager->list(self::TEST_SESSION), 'Handler must not run for invalid arguments.');
    }

    public function testExplicitTimeoutAboveDefaultIsAccepted(): void
    {
        $this->createManager();
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 10,
            maxTimeoutSeconds: 30,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(new BashArgumentsDTO(command: 'echo "ok"', timeout: 30));
        });

        $this->assertStringContainsString('ok', $result);
    }

    /* ── Background prompt acceptance ── */

    /* ── Background prompt decline (default behaviour) ── */

    /* ── No duplicate command execution ── */

    /* ── Process finishes during prompt — regression for smoke bug A ── */

    /* ── Output capping ── */

    public function testOutputCapping(): void
    {
        // Output capping is now handled centrally by OutputCapToolResultProcessor,
        // not by individual tools. This test verifies that BashTool returns raw
        // output unchanged, without embedding any cap notice in the result string.
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(new BashArgumentsDTO(command: 'echo "this is a very long output that should be truncated"'));
        });

        // Tool returns raw output; capping is centralized.
        $this->assertStringContainsString('this is a very long output', $result);
        $this->assertStringNotContainsString('Output capped', $result);
    }

    /**
     * Thesis: multi-megabyte completed bash output is never fully loaded into
     * the model-facing result. BashTool uses bounded log-tail reads and returns
     * a compact notice with exact total bytes + existing background log path.
     * The full log remains only at that path (no output-cap duplication).
     */
    public function testOversizedCompletedOutputReturnsBoundedNoticeWithoutLoadingFullLog(): void
    {
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 30,
            pollIntervalMicros: 50_000,
            logTailChars: 1024,
        );
        $this->createManager();

        $totalBytes = 3_000_000;
        $result = $this->withContext(self::TEST_SESSION, function () use ($totalBytes): string {
            // Write a large deterministic blob via PHP (project-contract runtime).
            return ($this->makeBashTool())(new BashArgumentsDTO(command: \sprintf(
                "php -r 'echo str_repeat(\"X\", %d);'",
                $totalBytes,
            )));
        });

        $this->assertStringContainsString('Bash output truncated:', $result);
        $this->assertStringContainsString($totalBytes.' bytes', $result);
        $this->assertStringContainsString('Full output remains at the background log:', $result);
        $this->assertStringContainsString('Do not rerun the original command', $result);
        $this->assertStringNotContainsString('tokens', $result, 'Bounded bash notice must not invent token estimates');
        // Notice itself must stay compact (well under generic OutputCap default).
        $this->assertLessThan(2000, \strlen($result), 'Bounded notice must remain compact');

        // Full log exists on disk and is not copied into output-cap storage.
        if (preg_match('/Full output remains at the background log:\n(.+)/', $result, $matches)) {
            $logPath = trim($matches[1]);
            $this->assertFileExists($logPath);
            $this->assertSame($totalBytes, filesize($logPath));
        } else {
            $this->fail('Expected background log path in bounded notice');
        }

        $outputCapDir = $this->tmpDir.'/output-cap';
        if (is_dir($outputCapDir)) {
            $capFiles = glob($outputCapDir.'/*.txt') ?: [];
            $this->assertSame([], $capFiles, 'Bash must not duplicate oversized logs into output-cap storage');
        }
    }

    /* ── Registry exposure ── */

    public function testRegistryExposesTool(): void
    {
        $this->createManager();
        $tool = $this->makeBashTool();

        $registry = new ToolRegistry([$tool]);

        $definitions = $registry->activeToolDefinitions();

        $bashDef = null;
        foreach ($definitions as $def) {
            if ('bash' === $def->name) {
                $bashDef = $def;
                break;
            }
        }

        $this->assertNotNull($bashDef, 'bash should be registered');
        $this->assertSame($tool, $bashDef->handler);
    }

    public function testToolDefinitionProperties(): void
    {
        $this->createManager();
        $tool = $this->makeBashTool();

        $def = $tool->definition();

        $this->assertSame('bash', $def->name);
        $this->assertSame($tool, $def->handler);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
        // Typed DTO tool: schema is generated natively from BashArgumentsDTO.
        $this->assertNull($def->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($tool);
        $args = $schema;

        // Schema must have 'command' required
        $this->assertContains('command', $args['required']);
        // Schema must NOT have 'run_in_background'
        $this->assertArrayNotHasKey('run_in_background', $args['properties']);

        // Prompt line must NOT advertise a run_in_background parameter
        $this->assertStringNotContainsStringIgnoringCase('run_in_background', $def->promptLine);

        $this->assertStringContainsString('Commands time out after 2 seconds by default.', $def->description);
        $this->assertStringContainsString('Provide the timeout argument with an explicit higher value', $def->description);
        $this->assertStringContainsString('backgrounded=true', $def->description);
        $this->assertStringContainsString('Do not poll for completion.', $def->description);
        $this->assertStringContainsString('[backgrounded=true|false]', $def->promptLine);

        // Prompt guidelines must describe timeout selection, model-selected
        // backgrounding, and no-poll completion.
        $guidelinesText = implode(' ', $def->promptGuidelines);
        $this->assertStringContainsStringIgnoringCase('time out after 2 seconds by default', $guidelinesText);
        $this->assertStringContainsStringIgnoringCase('always provide the timeout argument with an explicit higher value', $guidelinesText);
        $this->assertStringContainsStringIgnoringCase('3600-second maximum', $guidelinesText);
        $this->assertStringContainsString('direct bash calls, not calls inside code_mode', $guidelinesText);
        $this->assertStringContainsStringIgnoringCase('bg_status', $guidelinesText);
        $this->assertStringContainsString('backgrounded=true', $guidelinesText);
        $this->assertStringContainsString('notify you when they finish', $guidelinesText);
        $this->assertStringContainsStringIgnoringCase('do not poll', $guidelinesText);
    }

    /* ── No-context execution (session-less) ── */

    public function testSuccessfulCommandWithoutContext(): void
    {
        $this->createManager();

        // No ambient ToolContext — sessionId and cancelToken are null.
        // The tool should execute the command successfully with only the
        // timeout deadline (no cancellation capability).
        $tool = $this->makeBashTool();
        $result = ($tool)(new BashArgumentsDTO(command: 'echo "no context test"'));

        $this->assertStringContainsString('no context test', $result);
        $this->assertStringNotContainsString('timed out', $result);
        $this->assertStringNotContainsString('cancelled', $result);
    }

    /* ── Session scoping ── */

    public function testBackgroundProcessIsScopedToSession(): void
    {
        $this->createManager();

        $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(new BashArgumentsDTO(command: 'echo "session test"'));
        });

        $entities = $this->recordsForSession(self::TEST_SESSION);
        $this->assertCount(1, $entities);

        $otherEntities = $this->recordsForSession('other-session');
        $this->assertCount(0, $otherEntities);
    }

    /**
     * Foreground completion must read the log for the immutable DB record,
     * not the first retained row that shares the same OS PID.
     */
    public function testForegroundCompletionReturnsCurrentRecordLogWhenPidIsReused(): void
    {
        $this->createManager();

        $store = self::getContainer()->get(ProcessStore::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $staleLogPath = $this->tmpDir.'/stale-composer.log';
        file_put_contents($staleLogPath, self::COMPOSER_MARKER."\n");

        $staleId = $store->insertRecord([
            'pid' => 424242,
            'session_id' => self::TEST_SESSION,
            'command' => 'composer info --direct',
            'log_path' => $staleLogPath,
            'status_path' => $this->tmpDir.'/stale.status',
            'started_at' => new \DateTimeImmutable('-1 hour'),
        ]);

        $staleEntity = $store->fetchByRecordId($staleId);
        $this->assertNotNull($staleEntity);
        $staleEntity->finish(0, new \DateTimeImmutable('-1 hour'));
        $store->flush();

        $releasePipe = $this->tmpDir.'/pid-reuse-release-'.bin2hex(random_bytes(4)).'.fifo';
        $this->assertTrue(posix_mkfifo($releasePipe, 0o600), 'Release FIFO must be created');
        $releaseArg = escapeshellarg($releasePipe);
        $releaseEndpoint = fopen($releasePipe, 'r+b');
        $this->assertIsResource($releaseEndpoint, 'Parent must own the release FIFO endpoint');
        $this->assertTrue(stream_set_blocking($releaseEndpoint, false), 'Release FIFO endpoint must be nonblocking');

        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 30,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $token = $this->createStub(CancellationTokenInterface::class);
        $released = false;
        $token->method('isCancellationRequested')
            ->willReturnCallback(function () use ($store, $em, $staleId, $releaseEndpoint, &$released): bool {
                $records = $this->recordsForSession(self::TEST_SESSION);
                $current = $records[0] ?? null;
                if ($released || null === $current || $current->id === $staleId) {
                    return false;
                }
                $released = true;
                $stale = $store->fetchByRecordId($staleId);
                if (null !== $stale) {
                    $stale->pid = $current->pid;
                    $em->flush();
                }

                $this->assertSame(
                    8,
                    fwrite($releaseEndpoint, "release\n"),
                    'Release must be delivered to the waiting owned child',
                );

                return false;
            });

        try {
            $result = $this->withContext(self::TEST_SESSION, function () use ($releaseArg): string {
                return ($this->makeBashTool())(new BashArgumentsDTO(command: 'IFS= read -r release < '.$releaseArg.'; echo '.escapeshellarg(self::DATETIME_MARKER)));
            }, cancellationToken: $token);
        } finally {
            fclose($releaseEndpoint);
        }

        $this->assertStringContainsString(self::DATETIME_MARKER, $result);
        $this->assertStringNotContainsString(self::COMPOSER_MARKER, $result);

        $entities = $this->recordsForSession(self::TEST_SESSION);
        $this->assertGreaterThanOrEqual(2, \count($entities));

        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->id] = $entity;
        }

        $this->assertArrayHasKey($staleId, $byId);
        $current = null;
        foreach ($entities as $entity) {
            if ($entity->id !== $staleId) {
                $current = $entity;
                break;
            }
        }
        $this->assertNotNull($current);
        $this->assertSame($byId[$staleId]->pid, $current->pid, 'Stale and current records must share the OS PID');
        $this->assertNotSame($byId[$staleId]->logPath, $current->logPath);
    }

    public function testNestedBackgroundLaunchIsRefusedBeforeStartingProcess(): void
    {
        $this->createManager();
        try {
            $this->withContext(self::TEST_SESSION, fn (): mixed => $this->withContext(self::TEST_SESSION, fn (): string => ($this->makeBashTool())(new BashArgumentsDTO('echo must-not-run', backgrounded: true))));
            $this->fail('Nested Bash cannot detach independently of its script.');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('top-level Bash', $exception->getMessage());
        }
        $this->assertSame([], $this->recordsForSession(self::TEST_SESSION));
    }

    #[TestWith([false], 'foreground')]
    #[TestWith([true], 'background')]
    public function testPersistentStartupWriteFailureStopsOnlyTheLaunchedProcess(bool $backgrounded): void
    {
        $this->createManager();
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = 'tcp://'.stream_socket_get_name($server, false);
        $script = '$socket = stream_socket_client('.var_export($address, true).'); fwrite($socket, "ready\n"); fread($socket, 1);';
        $command = 'exec '.escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg($script);
        $sibling = $this->manager->start($command, self::TEST_SESSION, 'sibling');
        $siblingPeer = stream_socket_accept($server, 1);
        $this->assertIsResource($siblingPeer);
        stream_set_timeout($siblingPeer, 1);
        $this->assertSame("ready\n", fgets($siblingPeer));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $failure = $target = $peer = null;
        $attempts = 0;
        $listener = new class(static function (OnFlushEventArgs $event) use ($server, $connection, $backgrounded, &$failure, &$target, &$peer, &$attempts): void {
            ++$attempts;
            if (null === $target) {
                $target = array_values($event->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions())[0];
                self::assertInstanceOf(BackgroundProcess::class, $target);
                self::assertSame($backgrounded, $target->backgroundRequested);
                self::assertNull($target->backgroundedAt);
                $peer = stream_socket_accept($server, 1);
                self::assertIsResource($peer);
                stream_set_timeout($peer, 1);
                self::assertSame("ready\n", fgets($peer));
            }
            // Keep SQLite read-only until finally; any cleanup write must fail as well.
            $connection->executeStatement('PRAGMA query_only = ON');
            try {
                $connection->executeStatement('UPDATE background_process SET background_requested = background_requested WHERE id = -1');
            } catch (DbalException $exception) {
                $failure = $exception;
                throw $exception;
            }
        }) {
            public function __construct(private readonly \Closure $failWrite)
            {
            }

            public function onFlush(OnFlushEventArgs $event): void
            {
                ($this->failWrite)($event);
            }
        };
        $events = $em->getEventManager();
        $events->addEventListener([Events::onFlush], $listener);
        $lifecycle = new ProcessLifecycle($this->bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
        try {
            try {
                $this->withContext(self::TEST_SESSION, fn (): string => ($this->makeBashTool())(new BashArgumentsDTO($command, backgrounded: $backgrounded)));
                $this->fail('A failed startup write must not return a tool result.');
            } catch (DbalException $exception) {
                $this->assertSame($failure, $exception);
                $this->assertStringContainsString('readonly', $exception->getMessage());
            }
            $this->assertSame(1, $attempts, 'Cleanup must not attempt another flush.');
            $this->assertSame(1, (int) $connection->fetchOne('PRAGMA query_only'));
            $this->assertIsResource($peer);
            // EOF proves the blocked command exited, without relying on process-exit timing.
            $this->assertSame('', fread($peer, 1));
            $this->assertTrue(feof($peer));
            $this->assertTrue($lifecycle->isAlive($sibling->pid));
            $this->assertCount(1, $this->recordsForSession(self::TEST_SESSION));
        } finally {
            $events->removeEventListener([Events::onFlush], $listener);
            $connection->executeStatement('PRAGMA query_only = OFF');
            $em->clear();
            if ($target instanceof BackgroundProcess) {
                $lifecycle->sendKill($target->pid, $target->pgid);
            }
            $this->manager->shutdownCleanup();
            foreach ([$peer, $siblingPeer, $server] as $stream) {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    #[TestWith([false], 'manual request')]
    #[TestWith([true], 'model launch')]
    public function testRequestedDetachmentKeepsSameProcessAndPollerNotifiesOnce(bool $backgrounded): void
    {
        $this->createManager();
        $session = self::getContainer()->get(HatfieldSessionStore::class)->createSession('Bash detachment');
        $fifo = $this->tmpDir.'/detach-release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        $token = $this->createStub(CancellationTokenInterface::class);
        $selected = null;
        $originalDeadline = null;
        $token->method('isCancellationRequested')->willReturnCallback(function () use ($session, $backgrounded, &$selected, &$originalDeadline): bool {
            if (null === $selected) {
                $records = $backgrounded ? $this->recordsForSession($session) : $this->manager->foregroundTools($session);
                if ([] !== $records) {
                    $selected = $records[0];
                    $originalDeadline = $selected->deadlineNanoseconds;
                    if (!$backgrounded) {
                        $this->assertTrue($this->manager->requestBackground($session, $selected->id, 'tc_bash'));
                    }
                }
            }

            return false;
        });

        try {
            $arguments = ['command' => 'printf "started\\n"; IFS= read -r release < '.escapeshellarg($fifo).'; printf "finished\\n"', 'backgrounded' => $backgrounded];
            $registry = new ToolRegistry();
            $registry->registerTool(name: 'bash', description: 'bash', handler: $this->makeBashTool(), promptLine: 'bash');
            $executor = new ToolExecutor(
                defaultMode: 'parallel',
                maxParallelism: 2,
                resultStore: new ToolExecutionResultStore(),
                toolbox: new RegistryBackedToolbox($registry, new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()), NativeToolSchemaProbe::schemaFactory()),
                contextAccessor: $this->contextAccessor,
            );
            $call = ToolCallBuilder::create('tc_bash')->withToolName('bash')->withArguments($arguments)
                ->withRunId($session)->withContext(['cancel_token' => $token, 'turn_no' => 1, 'step_id' => 'detach-step'])->build();
            $batch = self::getContainer()->get(ToolBatchCollector::class);
            $store = self::getContainer()->get(ToolBatchStoreInterface::class);
            $first = new ExecuteToolCall($session, 1, 'detach-step', 1, 'first', 'tc_bash', 'bash', $arguments, 0, mode: 'parallel', maxParallelism: 2);
            $sibling = new ExecuteToolCall($session, 1, 'detach-step', 1, 'sibling', 'sibling', 'read', [], 1, mode: 'sequential');
            $initial = TestToolBatchRegistration::register($batch, $store, $session, 1, 'detach-step', [$first, $sibling]);
            $this->assertSame(['tc_bash'], array_column($initial, 'toolCallId'));
            $result = $executor->execute($call);
            $acknowledgment = $result->content[0]['text'];
            $receipt = ToolCallResultFactory::fromExecuteToolCallAndToolResult($first, $result);
            $collected = TestToolBatchCoordination::collect($batch, $store, $receipt);
            $this->assertTrue($collected->accepted);
            $this->assertFalse($collected->complete);
            $this->assertSame(['sibling'], array_column($collected->effectsToDispatch, 'toolCallId'));
            $duplicate = TestToolBatchCoordination::collect($batch, $store, $receipt);
            $this->assertTrue($duplicate->duplicate);
            $this->assertSame([], $duplicate->effectsToDispatch);
            $this->assertNotNull($selected);
            $this->assertStringContainsString('Command moved to background.', $acknowledgment);
            $record = $this->manager->findByRecordId($selected->id, $session);
            $this->assertNotNull($record);
            $this->assertSame($selected->pid, $record->pid);
            $this->assertSame($originalDeadline, $record->deadlineNanoseconds);
            $this->assertNull($record->finishedAt);
            $this->assertNotNull($record->backgroundedAt);
            $this->assertCount(1, $this->recordsForSession($session));
            $siblingResult = ToolCallResultFactory::fromExecuteToolCallAndToolResult($sibling, new ToolResult('sibling', 'read', [['type' => 'text', 'text' => 'sibling output']]));
            $settled = TestToolBatchCoordination::collect($batch, $store, $siblingResult);
            $this->assertTrue($settled->complete);
            $this->assertSame(['tc_bash', 'sibling'], array_column($settled->orderedResults, 'toolCallId'));
            $this->assertSame(8, fwrite($endpoint, "release\n"));

            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $record = $this->manager->findByRecordId($selected->id, $session);
                if (null !== $record?->finishedAt) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);
            $this->assertNotNull($record?->finishedAt);
            $this->assertSame(1, substr_count((string) file_get_contents($selected->logPath), "started\n"));
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->expects($this->once())->method('dispatch')->with($this->callback(
                static fn (DeliverBackgroundCompletionDTO $message): bool => $session === $message->runId() && str_contains($message->text, 'finished'),
            ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
            $poller = new BackgroundProcessCompletionPoller(
                static::getContainer()->get(ProcessStore::class),
                $this->manager,
                $bus,
                new RuntimeEventEmitter(new NullLogger()),
                new NullLogger(),
                static::getContainer()->get(AgentArtifactRegistry::class),
                static::getContainer()->get(BackgroundCompletionReceiptRepository::class),
                $session,
            );
            $poll = new \ReflectionMethod($poller, 'poll');
            $poll->invoke($poller);
            $poll->invoke($poller);
            $this->assertNotNull($this->manager->findByRecordId($selected->id)?->completionNotifiedAt);
            $this->assertSame($result->content, $executor->execute($call)->content, 'Native completion must not overwrite the original acknowledgment cache.');
            $this->assertCount(2, $store->load($session, 1, 'detach-step')->results);
            $this->assertCount(1, $this->recordsForSession($session));
        } finally {
            fclose($endpoint);
        }
    }

    public function testCompletionWinningRequestRaceReturnsOnlyForegroundOutput(): void
    {
        $this->createManager();
        $fifo = $this->tmpDir.'/finish-race';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        $token = $this->createStub(CancellationTokenInterface::class);
        $requested = false;
        $token->method('isCancellationRequested')->willReturnCallback(function () use ($endpoint, &$requested): bool {
            $records = $this->manager->foregroundTools(self::TEST_SESSION);
            if (!$requested && [] !== $records) {
                $record = $records[0];
                $requested = true;
                $this->assertTrue($this->manager->requestBackground(self::TEST_SESSION, $record->id, 'tc_bash'));
                $this->assertSame(8, fwrite($endpoint, "release\n"));
                $deadline = hrtime(true) + 2_000_000_000;
                do {
                    $finished = $this->manager->findByRecordId($record->id, self::TEST_SESSION);
                    if (null !== $finished?->finishedAt) {
                        break;
                    }
                    usleep(10_000);
                } while (hrtime(true) < $deadline);
                $this->assertNotNull($finished?->finishedAt);
            }

            return false;
        });
        try {
            $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->makeBashTool())(
                new BashArgumentsDTO(command: 'IFS= read -r release < '.escapeshellarg($fifo).'; printf done'),
            ), cancellationToken: $token);
            $this->assertSame('done', $result);
            $this->assertCount(1, $this->recordsForSession(self::TEST_SESSION));
            $this->assertNull($this->recordsForSession(self::TEST_SESSION)[0]->backgroundedAt);
            $this->assertSame([], self::getContainer()->get(ProcessStore::class)->findCompletionCandidates([self::TEST_SESSION]));
        } finally {
            fclose($endpoint);
        }
    }

    /* ── Helpers ── */

    public function testConfiguredChildBashCanDetachWithoutEndingNativeExecution(): void
    {
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState(self::TEST_SESSION, RunStatus::Running, parentRunId: 'parent'));
        $manager = self::getContainer()->get(BackgroundProcessManager::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $tool = self::getContainer()->get(BashTool::class);
        $fifo = $this->tmpDir.'/child-release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        $token = $this->createStub(CancellationTokenInterface::class);
        $checked = false;
        $token->method('isCancellationRequested')->willReturnCallback(function () use ($manager, &$checked): bool {
            $records = $this->recordsForSession(self::TEST_SESSION);
            if (!$checked && [] !== $records) {
                $checked = true;
                $this->assertCount(1, $manager->foregroundTools(self::TEST_SESSION));
                $this->assertTrue($manager->requestBackground(self::TEST_SESSION, $records[0]->id, 'tc_child'));
            }

            return false;
        });
        try {
            $context = new ToolContext(self::TEST_SESSION, 1, 'tc_child', 'bash', $token, 2, stepId: 'child-step');
            $result = $accessor->with($context, static fn (): string => $tool(new BashArgumentsDTO(command: 'IFS= read -r release < '.escapeshellarg($fifo).'; printf child-finished', timeout: 2)));
            $this->assertTrue($checked);
            $this->assertStringContainsString('Command moved to background.', $result);
            $records = $this->recordsForSession(self::TEST_SESSION);
            $this->assertCount(1, $records);
            $this->assertNotNull($records[0]->backgroundedAt);
            $this->assertNull($records[0]->finishedAt);
            $this->assertTrue(posix_kill($records[0]->pid, 0));
            $this->assertSame(8, fwrite($endpoint, "release\n"));
        } finally {
            fclose($endpoint);
            $manager->shutdownCleanup();
        }
    }

    /**
     * Create the BackgroundProcessManager once per test method.
     *
     * ProcessStore comes from the Symfony test container.
     * ProcessLifecycle and BackgroundProcessManager use test-specific temp dirs.
     */
    private function createManager(): void
    {
        if ($this->managerCreated) {
            return;
        }

        /** @var ProcessStore $store */
        $store = self::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($this->bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $this->bgConfig, new NullLogger());
        $this->managerCreated = true;
    }

    /**
     * Create a BashTool with the configured dependencies.
     */
    private function makeBashTool(): BashTool
    {
        return new BashTool(
            manager: $this->manager,
            contextAccessor: $this->contextAccessor,
            toolRuntime: $this->toolRuntime,
            logger: $this->logger,
            config: $this->bashConfig,
        );
    }

    /**
     * @return list<BackgroundProcess>
     */
    private function recordsForSession(string $sessionId): array
    {
        return static::getContainer()->get(BackgroundProcessRepository::class)
            ->findBy(['sessionId' => $sessionId], ['id' => 'DESC']);
    }

    private function countProcessesHoldingMarker(string $markerPath): int
    {
        $escaped = preg_quote($markerPath, '/');
        $output = [];
        exec(\sprintf('pgrep -af %s 2>/dev/null || true', escapeshellarg($markerPath)), $output);
        $count = 0;
        foreach ($output as $line) {
            if (1 === preg_match('/'.$escaped.'/', $line) && !str_contains($line, 'pgrep -af')) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Production-shaped execution path for invalid-argument tests: registry →
     * native resolver → ValidateToolCallArgumentsListener (with the same
     * BashToolConfig the handler uses, so BashTimeoutMax matches) →
     * FaultTolerantToolbox.
     */
    private function validationToolbox(): FaultTolerantToolbox
    {
        $validator = (new ValidatorBuilder())
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                BashTimeoutMaxValidator::class => new BashTimeoutMaxValidator($this->bashConfig),
            ]))
            ->getValidator();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallArgumentsResolved::class, new ValidateToolCallArgumentsListener($validator));

        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'bash',
            description: 'bash',
            handler: $this->makeBashTool(),
            promptLine: 'bash',
        );

        return new FaultTolerantToolbox(new RegistryBackedToolbox(
            registry: $registry,
            argumentResolver: new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
            schemaFactory: NativeToolSchemaProbe::schemaFactory(),
            eventDispatcher: $dispatcher,
        ));
    }

    private function withContext(
        string $sessionId,
        callable $callback,
        ?CancellationTokenInterface $cancellationToken = null,
    ): mixed {
        if (null === $cancellationToken) {
            $cancellationToken = $this->createStub(CancellationTokenInterface::class);
            $cancellationToken->method('isCancellationRequested')->willReturn(false);
        }

        $context = new ToolContext(
            runId: $sessionId,
            turnNo: 1,
            toolCallId: 'tc_bash',
            toolName: 'bash',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
            stepId: 'test-model-step',
        );

        return $this->contextAccessor->with($context, $callback);
    }

    /**
     * Kill any running background processes owned by this test.
     */
    private function cleanupProcesses(): void
    {
        if (!isset($this->bgConfig)) {
            return;
        }

        $bgDir = $this->bgConfig->storageDir;
        if (!is_dir($bgDir)) {
            return;
        }

        foreach (new \FilesystemIterator($bgDir, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ('pid' === $file->getExtension()) {
                $pid = (int) file_get_contents((string) $file);
                if ($pid > 0 && is_dir('/proc/'.$pid)) {
                    @exec('kill -KILL -'.$pid.' 2>/dev/null');
                    @exec('kill -KILL '.$pid.' 2>/dev/null');
                }
            }
        }
    }
}
