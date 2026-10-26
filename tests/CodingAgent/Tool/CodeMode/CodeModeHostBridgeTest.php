<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Pipeline\TransitionFinalizer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Tool\ToolCall as DomainToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Application\Pipeline\CodeModeBackgroundCoordinationHandler;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\ToolBatchSnapshotCleanupHookSubscriber;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeIpc;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeIpc
 *
 * @requires OS Linux
 */
final class CodeModeHostBridgeTest extends IsolatedKernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('code-mode-bridge');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testAcceptAfterChildExitStillReceivesQueuedReturnFrame(): void
    {
        $socketPath = $this->tmpDir.'/bridge.sock';
        $server = stream_socket_server('unix://'.$socketPath, $errno, $errstr);
        $this->assertIsResource($server, \sprintf('Failed to listen: [%d] %s', $errno, $errstr));
        stream_set_blocking($server, false);

        $childScript = $this->tmpDir.'/child.php';
        file_put_contents($childScript, <<<'PHP'
<?php
declare(strict_types=1);
$socket = getenv('HATFIELD_CODE_MODE_SOCKET');
$connection = stream_socket_client('unix://'.$socket, $errno, $errstr, 5.0);
if (false === $connection) {
    fwrite(STDERR, "connect failed: $errstr\n");
    exit(2);
}
$payload = json_encode(['type' => 'return', 'ok' => true, 'result' => 42], JSON_THROW_ON_ERROR);
$packet = pack('N', strlen($payload)).$payload;
fwrite($connection, $packet);
fclose($connection);
exit(0);
PHP);

        $process = new Process([\PHP_BINARY, $childScript], $this->tmpDir, [
            'HATFIELD_CODE_MODE_SOCKET' => $socketPath,
        ]);
        $process->start();

        $this->waitUntilProcessExits($process);
        $this->assertFalse($process->isRunning(), 'Child must exit before accept to prove the queued-connection race');
        $this->assertSame(0, $process->getExitCode());

        // Same order the host uses after observing child death: accept once more.
        $connection = @stream_socket_accept($server, 0.0);
        $this->assertIsResource($connection, 'Queued connection must still be accept()able after peer exit');
        stream_set_blocking($connection, false);

        $frame = CodeModeIpc::read($connection);
        fclose($connection);
        fclose($server);

        $this->assertSame([
            'type' => 'return',
            'ok' => true,
            'result' => 42,
        ], $frame);
    }

    public function testServeReadsReturnQueuedDuringPostWaitOutputDrain(): void
    {
        [$host, $peer] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        stream_set_blocking($host, false);
        $process = new class($peer) extends Process {
            private int $polls = 0;
            private bool $armed = false;

            public function __construct(private mixed $peer)
            {
                parent::__construct(['unused']);
                $this->armed = true;
            }

            public function isRunning(): bool
            {
                if (!$this->armed) {
                    return false;
                }
                if (2 === ++$this->polls) {
                    // First readiness wait saw no data. Its subsequent output
                    // drain unblocks a child which queues the frame and exits.
                    CodeModeIpc::write($this->peer, ['type' => 'return', 'ok' => true, 'result' => 42]);
                }

                return $this->polls < 2;
            }
        };
        try {
            $method = new \ReflectionMethod(CodeModeHostBridge::class, 'serve');
            $this->assertSame(42, $method->invoke($this->bridge(), $host, $process, null, new NullCancellationToken(), 5, hrtime(true)));
        } finally {
            fclose($host);
            fclose($peer);
        }
    }

    public function testExecuteReturnsScalarAndArrayFromFastScripts(): void
    {
        $bridge = $this->bridge();

        $this->assertSame(42, $this->runScript($bridge, 'return 42;'));
        $this->assertSame(
            ['direct' => true, 'n' => 2],
            $this->runScript($bridge, "return ['direct' => true, 'n' => 2];"),
        );
    }

    public function testExecuteToonHelpersDecodeAndRejectGarbage(): void
    {
        $bridge = $this->bridge();

        $decoded = $this->runScript($bridge, 'return toon_decode("a: 1\\n");');
        $this->assertSame(['a' => 1], $decoded);

        $encoded = $this->runScript($bridge, "return toon_encode(['x' => 2]);");
        $this->assertSame('x: 2', $encoded);

        try {
            // Unbalanced quotes are a real decode failure; many malformed maps still parse.
            $this->runScript($bridge, "return toon_decode('\"unterminated');");
            $this->fail('Expected ToolCallException for garbage TOON');
        } catch (ToolCallException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testUnsupportedToolsRejectedBeforeInvocation(): void
    {
        $calls = [];
        $bridge = $this->bridgeWithToolbox(static function (string $name) use (&$calls): never {
            $calls[] = $name;
            throw new \RuntimeException('toolbox should not execute '.$name);
        });

        foreach (['subagent', 'fork', 'agent_resume', 'agent_steer', 'notify_parent', 'ask_human'] as $toolName) {
            try {
                $this->runScript($bridge, "return tool('".$toolName."', ['x' => 1]);");
                $this->fail('Expected rejection for '.$toolName);
            } catch (ToolCallException $exception) {
                $this->assertStringContainsString($toolName, $exception->getMessage());
                $this->assertStringContainsString('not supported inside code_mode', $exception->getMessage());
            }
        }

        $this->assertSame([], $calls, 'Unsupported tools must be rejected before toolbox invocation');
    }

    public function testLossyReturnValuesAreRejected(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript($bridge, 'return INF;');
            $this->fail('Expected ToolCallException for INF');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('unsupported IPC value', $exception->getMessage());
            $this->assertFalse($exception->retryable());
        }

        try {
            $this->runScript($bridge, 'return function () {};');
            $this->fail('Expected ToolCallException for Closure');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('unsupported IPC value', $exception->getMessage());
            $this->assertFalse($exception->retryable());
        }

        try {
            $this->runScript($bridge, 'return new DateTimeImmutable("2026-01-01T00:00:00Z");');
            $this->fail('Expected ToolCallException for object');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('unsupported IPC value', $exception->getMessage());
            $this->assertFalse($exception->retryable());
        }
    }

    public function testMemoryLimitIsAppliedAndFatalSurfacesOnStderr(): void
    {
        $bridge = $this->bridge();

        try {
            // Force an allocation larger than the configured child memory_limit without sleeping.
            $this->runScript(
                $bridge,
                '$chunks = []; for ($i = 0; $i < 64; ++$i) { $chunks[] = str_repeat("x", 1024 * 1024); } return count($chunks);',
                memoryLimitMb: 8,
            );
            $this->fail('Expected memory exhaustion failure');
        } catch (ToolCallException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('Allowed memory size', $message);
        }
    }

    public function testRequestedTimeoutIsCappedBySmallerParentBudget(): void
    {
        $bridge = $this->bridge();
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $this->assertInstanceOf(StackToolExecutionContextAccessor::class, $accessor);

        try {
            $accessor->with(
                new ToolContext(
                    runId: 'code-mode-bridge-test',
                    turnNo: 1,
                    toolCallId: 'code-mode-bridge-test-request-cap',
                    toolName: 'code_mode',
                    cancellationToken: new NullCancellationToken(),
                    timeoutSeconds: 1,
                    orderIndex: 0,
                    humanInputAnswer: null,
                    stepId: 'code-mode-bridge-test-step',
                    parentModel: null,
                ),
                static function () use ($bridge): mixed {
                    return $bridge->execute(
                        'for ($i = 0, $end = hrtime(true) + 3_000_000_000; hrtime(true) < $end; ++$i) {} return $i;',
                        120,
                        256,
                    );
                },
            );
            $this->fail('Expected timeout');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('timed out after 1 seconds', $exception->getMessage());
        }
    }

    public function testRequestedMemoryLimitIsPropagatedToChildIni(): void
    {
        $bridge = $this->bridge();

        $limit = $this->runScript(
            $bridge,
            'return ini_get("memory_limit");',
            memoryLimitMb: 64,
        );

        $this->assertSame('64M', $limit);
    }

    public function testNestedToolReceivesRemainingScriptBudget(): void
    {
        $seenTimeouts = [];
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $this->assertInstanceOf(StackToolExecutionContextAccessor::class, $accessor);

        $locator = new class($accessor, $seenTimeouts) implements \Psr\Container\ContainerInterface {
            /** @param list<int|null> $seenTimeouts */
            public function __construct(
                private StackToolExecutionContextAccessor $accessor,
                private array &$seenTimeouts,
            ) {
            }

            public function get(string $id): mixed
            {
                if ('toolbox' !== $id) {
                    throw new \RuntimeException('Unexpected locator id: '.$id);
                }

                $accessor = $this->accessor;
                $seenTimeouts = &$this->seenTimeouts;

                return new class($accessor, $seenTimeouts) implements \Symfony\AI\Agent\Toolbox\ToolboxInterface {
                    /** @param list<int|null> $seenTimeouts */
                    public function __construct(
                        private StackToolExecutionContextAccessor $accessor,
                        private array &$seenTimeouts,
                    ) {
                    }

                    public function getTools(): array
                    {
                        return [];
                    }

                    public function execute(\Symfony\AI\Platform\Result\ToolCall $toolCall): \Symfony\AI\Agent\Toolbox\ToolResult
                    {
                        $context = $this->accessor->current();
                        $this->seenTimeouts[] = $context?->timeoutSeconds();

                        return new \Symfony\AI\Agent\Toolbox\ToolResult($toolCall, ['timeout' => $context?->timeoutSeconds()]);
                    }
                };
            }

            public function has(string $id): bool
            {
                return 'toolbox' === $id;
            }
        };

        $bridge = new CodeModeHostBridge(
            $accessor,
            self::getContainer()->get(ToolRuntime::class),
            $locator,
            self::getContainer()->get(RuntimeProcessConfig::class),
            self::getContainer()->get(OutputCap::class),
            self::getContainer()->get(DeferredToolCompletionRepository::class),
            self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
            self::getContainer()->get(ToolPayloadStoreInterface::class),
            self::getContainer()->get('agent.command.bus'),
        );

        $result = $accessor->with(
            new ToolContext(
                runId: 'code-mode-bridge-test',
                turnNo: 1,
                toolCallId: 'code-mode-bridge-test-budget',
                toolName: 'code_mode',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: 5,
                orderIndex: 0,
                humanInputAnswer: null,
                stepId: 'code-mode-bridge-test-step',
                parentModel: null,
            ),
            static fn (): mixed => $bridge->execute("\$end = hrtime(true) + 1_200_000_000; while (hrtime(true) < \$end) {} return tool('read', ['path' => 'README.md']);", 5, 256),
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('timeout', $result);
        $this->assertIsInt($result['timeout']);
        // Parent budget is 5s; after waiting >1s the nested call must see a reduced remaining budget.
        $this->assertLessThan(5, $result['timeout']);
        $this->assertGreaterThan(0, $result['timeout']);
        $this->assertSame([$result['timeout']], $seenTimeouts);
    }

    public function testNestedCodeModeEnvelopeIsUnwrappedToRawValue(): void
    {
        $seen = [];
        $bridge = $this->bridgeWithToolbox(static function (string $name) use (&$seen): void {
            $seen[] = $name;
        });

        // Replace toolbox to return a nested code_mode envelope and assert unwrap.
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $this->assertInstanceOf(StackToolExecutionContextAccessor::class, $accessor);
        $locator = new class($accessor) implements \Psr\Container\ContainerInterface {
            public function __construct(private StackToolExecutionContextAccessor $accessor)
            {
            }

            public function get(string $id): mixed
            {
                if ('toolbox' !== $id) {
                    throw new \RuntimeException('Unexpected locator id: '.$id);
                }

                return new class implements \Symfony\AI\Agent\Toolbox\ToolboxInterface {
                    public function getTools(): array
                    {
                        return [];
                    }

                    public function execute(\Symfony\AI\Platform\Result\ToolCall $toolCall): \Symfony\AI\Agent\Toolbox\ToolResult
                    {
                        return new \Symfony\AI\Agent\Toolbox\ToolResult(
                            $toolCall,
                            [
                                'code_mode_value' => ['nested' => true],
                                'code_mode_diagnostics' => ['stdout' => 'child-out'],
                            ],
                        );
                    }
                };
            }

            public function has(string $id): bool
            {
                return 'toolbox' === $id;
            }
        };

        $bridge = new CodeModeHostBridge(
            $accessor,
            self::getContainer()->get(ToolRuntime::class),
            $locator,
            self::getContainer()->get(RuntimeProcessConfig::class),
            self::getContainer()->get(OutputCap::class),
            self::getContainer()->get(DeferredToolCompletionRepository::class),
            self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
            self::getContainer()->get(ToolPayloadStoreInterface::class),
            self::getContainer()->get('agent.command.bus'),
        );

        $result = $this->runScript($bridge, "return tool('code_mode', ['script' => 'return 1;']);");
        $this->assertSame(['nested' => true], $result);
    }

    public function testToonEncodeRejectsUnsupportedValuesAndDecodeKeepsScalarText(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript($bridge, 'return toon_encode(function () {});');
            $this->fail('Expected ToolCallException for unsupported toon_encode input');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('unsupported IPC value', $exception->getMessage());
            $this->assertFalse($exception->retryable());
        }

        $this->assertSame('plain text', $this->runScript($bridge, "return toon_decode('plain text');"));
    }

    public function testStdoutAndStderrDiagnosticsAreReturnedSeparately(): void
    {
        $bridge = $this->bridge();

        $result = $this->runScript($bridge, 'echo "hello-out"; fwrite(STDERR, "hello-err\\n"); return 7;');
        $this->assertInstanceOf(CodeModeExecutionResult::class, $result);
        $this->assertSame(7, $result->result);
        $this->assertStringContainsString('hello-out', $result->diagnostics['stdout'] ?? '');
        $this->assertStringContainsString('hello-err', $result->diagnostics['stderr'] ?? '');
    }

    public function testNullReturnIsPreserved(): void
    {
        $bridge = $this->bridge();

        $this->assertNull($this->runScript($bridge, 'return null;'));
        $this->assertNull($this->runScript($bridge, '// no return'));
    }

    public function testDieWithoutReturnReportsExitWithoutReturning(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript($bridge, "die('died-message');");
            $this->fail('Expected ToolCallException for die without return');
        } catch (ToolCallException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('exited without returning a value', $message);
            $this->assertStringContainsString('died-message', $message);
            $this->assertStringContainsString('exit code 0', $message);
        }
    }

    public function testToolRejectsExtraArguments(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript($bridge, "return tool('read', ['path' => 'README.md'], 'extra');");
            $this->fail('Expected ToolCallException for extra tool() arguments');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('at most two arguments', $exception->getMessage());
        }
    }

    public function testDiagnosticPathsUseStableScriptNameAndAdjustedLine(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript($bridge, "throw new RuntimeException('boom-line');");
            $this->fail('Expected ToolCallException for thrown exception');
        } catch (ToolCallException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('boom-line', $message);
            // Path normalization applies to process diagnostics; exception messages from
            // IPC may not include file paths. Force a warning path assertion via stderr.
        }

        try {
            $this->runScript($bridge, '$x = $undefinedVar; return 1;');
            // warning + return still succeeds with diagnostics
        } catch (ToolCallException $exception) {
            $this->fail('Undefined variable warning should not fail the script: '.$exception->getMessage());
        }

        $result = $this->runScript($bridge, '$x = $undefinedVar; return 1;');
        $this->assertInstanceOf(CodeModeExecutionResult::class, $result);
        $stderr = $result->diagnostics['stderr'] ?? '';
        $stdout = $result->diagnostics['stdout'] ?? '';
        $combined = $stdout."\n".$stderr;
        $this->assertStringContainsString('Warning:', $combined);
        $this->assertStringContainsString('script.php(', $combined);
        $this->assertDoesNotMatchRegularExpression('#/tmp/[^\\s:]+/script\\.php#', $combined);
        $this->assertMatchesRegularExpression('#script\\.php\\(1\\)#', $combined);
        $this->assertSame(1, substr_count($combined, 'Undefined variable'));
        // Source prevention: display_errors=0 + xdebug.mode=off keeps the
        // warning on stderr once, without an stdout mirror or Xdebug stack.
        $this->assertSame('', $stdout);
        $this->assertNotSame('', $stderr);
        $this->assertStringNotContainsString('Stack', $stderr);
        $this->assertStringNotContainsString('Call Stack', $stderr);
    }

    public function testHugeStdoutPreservesHeadAndTailThroughHostCapture(): void
    {
        $bridge = $this->bridge();
        // Host regression: >50k stdout must keep unique head and tail markers.
        // Lower layers cannot prove Symfony Process capture without a real subprocess.
        $result = $this->runScript(
            $bridge,
            'echo "HEAD-MARKER-".str_repeat("X", 60000)."TAIL-MARKER"; return "tiny";',
        );
        $this->assertInstanceOf(CodeModeExecutionResult::class, $result);
        $this->assertSame('tiny', $result->result);
        $stdout = $result->diagnostics['stdout'] ?? '';
        $this->assertNotSame('', $stdout);
        $this->assertGreaterThan(50_000, \strlen($stdout));
        $this->assertStringStartsWith('HEAD-MARKER-', $stdout);
        $this->assertStringEndsWith('TAIL-MARKER', $stdout);
        $this->assertStringContainsString(str_repeat('X', 60_000), $stdout);

        $block = \Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnostics::renderBlock($result->diagnostics);
        $this->assertStringStartsWith("code_mode diagnostics\nstdout:\n", $block);
        $this->assertStringNotContainsString('diagnostics truncated', $block);
        $this->assertSame(\strlen($stdout), \strlen($block) - \strlen("code_mode diagnostics\nstdout:\n"));
    }

    public function testHugeStdoutEarlyExitPreservesHeadAndTailMarkers(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript(
                $bridge,
                'echo "ERR-HEAD-".str_repeat("Y", 60000)."ERR-TAIL"; exit(0);',
            );
            $this->fail('Expected ToolCallException for early exit without return');
        } catch (ToolCallException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('exited without returning a value', $message);
            $this->assertStringContainsString('ERR-HEAD-', $message);
            $this->assertStringContainsString('ERR-TAIL', $message);
            $this->assertStringContainsString(str_repeat('Y', 60_000), $message);
            $this->assertStringNotContainsString('diagnostics truncated', $message);
        }
    }

    public function testTypeErrorUsesNormalizedScriptPath(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript($bridge, 'strlen([]);');
            $this->fail('Expected ToolCallException for TypeError');
        } catch (ToolCallException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('script.php(', $message);
            $this->assertDoesNotMatchRegularExpression('#/tmp/[^\\s:]+/script\\.php#', $message);
            $this->assertMatchesRegularExpression('#script\\.php\\(\\d+\\)#', $message);
        }
    }

    public function testParseErrorReportsNormalizedScriptPathAndLine(): void
    {
        $bridge = $this->bridge();

        try {
            $this->runScript($bridge, 'echo (');
            $this->fail('Expected ToolCallException for parse error');
        } catch (ToolCallException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('script.php(', $message);
            $this->assertDoesNotMatchRegularExpression('#/tmp/[^\\s:]+/script\\.php#', $message);
            $this->assertMatchesRegularExpression('#script\\.php\\(\\d+\\)#', $message);
        }
    }

    public function testFusedNativePackagingResolvesPhpFromPath(): void
    {
        $finder = new \Symfony\Component\Process\ExecutableFinder();
        $phpFromPath = $finder->find('php');
        $this->assertIsString($phpFromPath);
        $this->assertNotSame('', $phpFromPath);

        $locator = new class implements \Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator {
            public function path(): string
            {
                return '/tmp/hatfield.linux-amd64';
            }

            /** @return list<string> */
            public function command(): array
            {
                return ['/tmp/hatfield.linux-amd64'];
            }
        };

        $bridge = new CodeModeHostBridge(
            self::getContainer()->get(StackToolExecutionContextAccessor::class),
            self::getContainer()->get(ToolRuntime::class),
            new class implements \Psr\Container\ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new \RuntimeException('toolbox should not be needed');
                }

                public function has(string $id): bool
                {
                    return false;
                }
            },
            new RuntimeProcessConfig($locator, $this->tmpDir),
            self::getContainer()->get(OutputCap::class),
            self::getContainer()->get(DeferredToolCompletionRepository::class),
            self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
            self::getContainer()->get(ToolPayloadStoreInterface::class),
            self::getContainer()->get('agent.command.bus'),
        );

        $result = $this->runScript($bridge, 'return "path-php-ok";');
        $this->assertSame('path-php-ok', $result);
    }

    #[DataProvider('retainedOutputs')]
    public function testLargeStreamsRemainRecoverableWithoutLosingReturnValue(string $script, string $expected): void
    {
        $run = $this->retainedRun();
        $container = self::getContainer();
        $context = $container->get(ActiveRunContextInterface::class);
        $state = $context->createNew($run);
        $events = (new EventFactory())->eventsFromSpecs($run, 1, 1, [
            ['type' => 'run_started', 'payload' => ['messages' => []]],
            ['type' => 'turn_advanced', 'payload' => ['turn_no' => 1, 'step_id' => 'code-mode-bridge-test-step']],
            ['type' => 'llm_step_completed', 'payload' => ['step_id' => 'code-mode-bridge-test-step', 'assistant_message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'code-mode-bridge-test-1', 'name' => 'code_mode', 'arguments' => [], 'order_index' => 0]]]]],
        ]);
        $reducer = $container->get(RunStateReducer::class);
        $commit = new RunCommit($context,
            $container->get(PreparedTransitionEventStoreInterface::class), new NullLogger(),
            $container->get(TransitionFinalizer::class),
            new HookDispatcher([$container->get(ToolBatchSnapshotCleanupHookSubscriber::class)]));
        $state = $commit->commit($state, $reducer->replay($state, $events), $events);
        $cap = new OutputCap(new OutputCapConfig(storageDir: $this->tmpDir.'/artifacts'), new LockFactory(new FlockStore($this->tmpDir)), new NullLogger());
        $result = $this->runScript($this->bridgeWithToolbox(static fn () => null, $cap), $script, runId: $run);
        $call = new DomainToolCall('code-mode-bridge-test-1', 'code_mode', [], 0, $run);
        $raw = ['code_mode_value' => $result instanceof CodeModeExecutionResult ? $result->result : $result, 'code_mode_diagnostics' => $result instanceof CodeModeExecutionResult ? $result->diagnostics : []];
        $domain = new ToolResult($call->toolCallId, 'code_mode', [['type' => 'text', 'text' => json_encode($raw, \JSON_THROW_ON_ERROR)]], ['raw_result' => $raw]);
        $domain = (new CodeModeDiagnosticsToolResultProcessor())->process($domain, $call);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $payloads = $container->get(ToolPayloadStoreInterface::class);
        $domain = (new OutputCapToolResultProcessor($cap, AttributeSerializerValidatorTestFactory::denormalizer(), $payloads, $repository))->process($domain, $call);
        $paths = [];
        $modelText = $domain->details['model_notifications'][0]['text'] ?? $domain->content[0]['text'];
        preg_match_all('/Saved full output: (.+)/', $modelText, $matches);
        foreach ($matches[1] as $path) {
            $paths[$path] = hash_file('sha256', $path);
            $this->assertStringContainsString($expected, file_get_contents($path));
        }
        $this->assertNotEmpty($paths);
        $correlation = $repository->findByRunAndToolCall($run, $call->toolCallId);
        $terminal = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, $domain->content, $domain->details);
        $failing = new MessageBus([$container->get(ToolPayloadMiddleware::class), new HandleMessageMiddleware(new HandlersLocator([
            ToolPayloadMessage::class => [static fn () => throw new \RuntimeException('lost send')],
        ]))]);
        try {
            $failing->dispatch($terminal);
            $this->fail('The publication failure must escape.');
        } catch (HandlerFailedException $exception) {
            $this->assertStringContainsString('lost send', $exception->getMessage());
        }
        $container->get(CodeModeBackgroundCoordinationHandler::class)->controllerStarting(new ControllerSessionStartingEvent($run));
        $cap->cleanupRun($run, 'controller_restart');
        $repair = $container->get(SessionRepairService::class)->repair($run, true, Uuid::v7()->toRfc4122());
        $this->assertNull($repair->refusalReason);
        $this->assertSame(2, $repair->activeOperationsRedriven, 'Repair requests the retained result and its ordinary continuation.');
        $reference = $repository->findMetadataByDeferredId($correlation->deferredId)->acceptedResultReference;
        $this->assertEquals($terminal, $payloads->read($reference));
        $state = $context->requireLoaded($run);
        $admission = $container->get(ToolCallResultHandler::class)->handle($payloads->read($reference), $state);
        $state = $commit->commit($state, $admission->nextState, $admission->events, postCommitActions: $admission->postCommitActions);
        $this->assertSame('completed', $repository->status($correlation->deferredId));
        $events = (new EventFactory())->eventsFromSpecs($run, 1, $state->lastSeq + 1, [['type' => 'agent_end', 'payload' => ['reason' => 'completed']]]);
        $commit->commit($state, $reducer->replay($state, $events), $events);
        $cap->cleanupRun($run, 'controller_shutdown');
        foreach ($paths as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', $path), 'Committed AgentEnd must retain referenced output.');
            $this->assertDirectoryDoesNotExist(\dirname($path, 3).'/tool-payloads', 'Terminal cleanup still deletes disposable payloads.');
        }
        $container->get(HatfieldSessionStore::class)->deleteSession($run);
        $context->release($run);
        foreach ($paths as $path => $hash) {
            $this->assertFileDoesNotExist($path, 'Permanent deletion still owns retained attachments.');
        }
    }

    public static function retainedOutputs(): iterable
    {
        yield 'large PHP value' => ['return str_repeat("V", 70000);', str_repeat('V', 70000)];
        yield 'sub-MiB diagnostics' => ['fwrite(STDOUT, str_repeat("D", 70000)); return 42;', str_repeat('D', 70000)];
        yield 'above-MiB streams' => ['for ($i=0; $i<160; ++$i) { fwrite(STDOUT, str_repeat("O",8192)); fwrite(STDERR, str_repeat("O",8192)); } return ["answer"=>42];', str_repeat('O', 1310720)];
    }

    /** @return iterable<string, array{string, string}> */
    public static function diagnosticFailureModes(): iterable
    {
        yield 'early exit' => ['exit', 'exit code 7'];
        yield 'script exception' => ['error', 'SCRIPT_FAILURE'];
        yield 'wall deadline' => ['timeout', 'timed out'];
        yield 'cancellation' => ['cancel', 'cancelled'];
    }

    #[DataProvider('diagnosticFailureModes')]
    public function testLargeFailureDiagnosticsKeepAnExactArtifact(string $mode, string $message): void
    {
        $run = $this->retainedRun();
        $cap = new OutputCap(new OutputCapConfig(storageDir: $this->tmpDir.'/artifacts'), new LockFactory(new FlockStore($this->tmpDir)), new NullLogger());
        $bridge = $this->bridgeWithToolbox(static fn () => null, $cap);
        $marker = $this->tmpDir.'/ready-to-cancel';
        $token = new class($marker) implements CancellationTokenInterface {
            public function __construct(private readonly string $marker)
            {
            }

            public function isCancellationRequested(): bool
            {
                clearstatcache(true, $this->marker);

                return is_file($this->marker);
            }
        };
        // The writer flushes all diagnostic bytes before publishing its cancel
        // marker. The wait exposes the real subprocess stop/deadline contract.
        $ending = match ($mode) {
            'exit' => 'exit(7);',
            'error' => 'throw new RuntimeException("SCRIPT_FAILURE");',
            'timeout' => 'sleep(3); return 42;',
            'cancel' => 'file_put_contents('.var_export($marker, true).', "ready"); sleep(3); return 42;',
        };
        try {
            $this->runScript($bridge, 'for ($i=0; $i<160; ++$i) fwrite(STDERR, str_repeat("E",8192)); '.$ending, timeoutSeconds: 1, cancelToken: $token, runId: $run);
            $this->fail('Expected failed execution');
        } catch (ToolCallException $exception) {
            $this->assertSame(ToolCallException::class, $exception::class);
            $this->assertFalse($exception->retryable());
            $this->assertNull($exception->hint());
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertLessThan(2048, \strlen($exception->getMessage()));
            $this->assertSame(1, preg_match('/Saved full output: (.+)/', $exception->getMessage(), $matches));
            $cap->cleanupRun($run, 'controller_shutdown');
            $this->assertSame(hash('sha256', str_repeat('E', 1310720)), hash_file('sha256', $matches[1]));
        } finally {
            $cap->cleanupRun('code-mode-bridge-test', 'test');
        }
    }

    public function testLargeInvalidUtf8DiagnosticsAreRefused(): void
    {
        $bridge = $this->bridge();
        $this->expectException(MalformedToolResultException::class);
        $this->runScript($bridge, 'for ($i=0; $i<160; ++$i) fwrite(STDOUT, str_repeat("O",8192)); echo "\\xff"; return 42;');
    }

    public function testBackgroundLaunchWithoutRegisteredOwnerIsRefusedBeforeStartingScript(): void
    {
        $marker = $this->tmpDir.'/must-not-run';
        try {
            $this->bridge()->execute('file_put_contents('.var_export($marker, true).', "ran");', backgrounded: true);
            $this->fail('Background launch needs an owner-qualified invocation.');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('top-level code_mode', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($marker);
    }

    private function bridge(): CodeModeHostBridge
    {
        $bridge = self::getContainer()->get('test.code_mode_host_bridge');
        $this->assertInstanceOf(CodeModeHostBridge::class, $bridge);

        return $bridge;
    }

    /**
     * @param callable(string): mixed $executor
     */
    private function bridgeWithToolbox(callable $executor, ?OutputCap $outputCap = null): CodeModeHostBridge
    {
        $locator = new class($executor) implements \Psr\Container\ContainerInterface {
            public function __construct(private mixed $executor)
            {
            }

            public function get(string $id): mixed
            {
                if ('toolbox' !== $id) {
                    throw new \RuntimeException('Unexpected locator id: '.$id);
                }

                return new class($this->executor) implements \Symfony\AI\Agent\Toolbox\ToolboxInterface {
                    public function __construct(private mixed $executor)
                    {
                    }

                    public function getTools(): array
                    {
                        return [];
                    }

                    public function execute(\Symfony\AI\Platform\Result\ToolCall $toolCall): \Symfony\AI\Agent\Toolbox\ToolResult
                    {
                        ($this->executor)($toolCall->getName());

                        return new \Symfony\AI\Agent\Toolbox\ToolResult($toolCall, null);
                    }
                };
            }

            public function has(string $id): bool
            {
                return 'toolbox' === $id;
            }
        };

        return new CodeModeHostBridge(
            self::getContainer()->get(StackToolExecutionContextAccessor::class),
            self::getContainer()->get(ToolRuntime::class),
            $locator,
            self::getContainer()->get(RuntimeProcessConfig::class),
            $outputCap ?? self::getContainer()->get(OutputCap::class),
            self::getContainer()->get(DeferredToolCompletionRepository::class),
            self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
            self::getContainer()->get(ToolPayloadStoreInterface::class),
            self::getContainer()->get('agent.command.bus'),
        );
    }

    private function retainedRun(): string
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('retained diagnostics');
        $call = new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation(Uuid::v7()->toRfc4122(), $run, 1, 'code-mode-bridge-test-step', 1, 'key', 'code-mode-bridge-test-1', 'code_mode', [], 0);
        \Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration::registerDeferredInvocation(self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class), $call);

        return $run;
    }

    private function runScript(
        CodeModeHostBridge $bridge,
        string $script,
        int $timeoutSeconds = 10,
        int $memoryLimitMb = 256,
        ?int $parentTimeoutSeconds = 10,
        ?CancellationTokenInterface $cancelToken = null,
        string $runId = 'code-mode-bridge-test',
    ): mixed {
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $this->assertInstanceOf(StackToolExecutionContextAccessor::class, $accessor);

        return $accessor->with(
            new ToolContext(
                runId: $runId,
                turnNo: 1,
                toolCallId: 'code-mode-bridge-test-1',
                toolName: 'code_mode',
                cancellationToken: $cancelToken ?? new NullCancellationToken(),
                timeoutSeconds: $parentTimeoutSeconds,
                orderIndex: 0,
                humanInputAnswer: null,
                stepId: 'code-mode-bridge-test-step',
                parentModel: null,
            ),
            static fn (): mixed => $bridge->execute($script, $timeoutSeconds, $memoryLimitMb),
        );
    }

    private function waitUntilProcessExits(Process $process, float $timeoutSeconds = 2.0): void
    {
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
        while ($process->isRunning()) {
            if (hrtime(true) >= $deadline) {
                $this->fail('Timed out waiting for child process exit (safety cap, not a sync strategy).');
            }
            usleep(1_000);
        }
    }
}
