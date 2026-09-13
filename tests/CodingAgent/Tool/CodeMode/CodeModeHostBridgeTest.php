<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeIpc;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Symfony\Component\Process\Process;

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

        foreach (['subagent', 'fork', 'agent_resume', 'ask_human'] as $toolName) {
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
            $this->assertTrue(
                str_contains($exception->getMessage(), 'non-finite float')
                || str_contains($exception->getMessage(), 'Inf and NaN'),
                $exception->getMessage(),
            );
        }

        try {
            $this->runScript($bridge, 'return function () {};');
            $this->fail('Expected ToolCallException for Closure');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('Closure', $exception->getMessage());
        }

        try {
            $this->runScript($bridge, 'return new DateTimeImmutable("2026-01-01T00:00:00Z");');
            $this->fail('Expected ToolCallException for object');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('unsupported object', $exception->getMessage());
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
                    executionMode: ToolExecutionMode::Sequential,
                    batchToolCallCount: 1,
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

    public function testScriptWallBudgetHonorsParentTimeoutCeiling(): void
    {
        $bridge = $this->bridge();
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $this->assertInstanceOf(StackToolExecutionContextAccessor::class, $accessor);

        try {
            $accessor->with(
                new ToolContext(
                    runId: 'code-mode-bridge-test',
                    turnNo: 1,
                    toolCallId: 'code-mode-bridge-test-timeout',
                    toolName: 'code_mode',
                    cancellationToken: new NullCancellationToken(),
                    timeoutSeconds: 1,
                    orderIndex: 0,
                    executionMode: ToolExecutionMode::Sequential,
                    batchToolCallCount: 1,
                    humanInputAnswer: null,
                    stepId: 'code-mode-bridge-test-step',
                    parentModel: null,
                ),
                static function () use ($bridge): mixed {
                    // Busy-wait instead of sleep so cancellation/budget polling stays active.
                    return $bridge->execute('for ($i = 0, $end = hrtime(true) + 3_000_000_000; hrtime(true) < $end; ++$i) {} return $i;', 60, 256);
                },
            );
            $this->fail('Expected timeout');
        } catch (ToolCallException $exception) {
            $this->assertStringContainsString('timed out after 1 seconds', $exception->getMessage());
        }
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
                executionMode: ToolExecutionMode::Sequential,
                batchToolCallCount: 1,
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
                            new \Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult(
                                ['nested' => true],
                                ['stdout' => 'child-out'],
                            ),
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
            $this->assertStringContainsString('Closure', $exception->getMessage());
        }

        $this->assertSame('plain text', $this->runScript($bridge, "return toon_decode('plain text');"));
    }

    public function testStdoutAndStderrDiagnosticsAreReturnedSeparately(): void
    {
        $bridge = $this->bridge();

        $result = $this->runScript($bridge, 'echo "hello-out"; fwrite(STDERR, "hello-err\\n"); return 7;');
        $this->assertInstanceOf(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult::class, $result);
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
        $this->assertInstanceOf(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult::class, $result);
        $stderr = $result->diagnostics['stderr'] ?? '';
        $this->assertStringContainsString('script.php(', $stderr);
        $this->assertDoesNotMatchRegularExpression('#/tmp/[^\\s:]+/script\\.php#', $stderr);
        $this->assertMatchesRegularExpression('#script\\.php\\(1\\)#', $stderr);
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
        );

        $result = $this->runScript($bridge, 'return "path-php-ok";');
        $this->assertSame('path-php-ok', $result);
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
    private function bridgeWithToolbox(callable $executor): CodeModeHostBridge
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
        );
    }

    private function runScript(
        CodeModeHostBridge $bridge,
        string $script,
        int $timeoutSeconds = 10,
        int $memoryLimitMb = 256,
        ?int $parentTimeoutSeconds = 10,
    ): mixed {
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $this->assertInstanceOf(StackToolExecutionContextAccessor::class, $accessor);

        return $accessor->with(
            new ToolContext(
                runId: 'code-mode-bridge-test',
                turnNo: 1,
                toolCallId: 'code-mode-bridge-test-1',
                toolName: 'code_mode',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: $parentTimeoutSeconds,
                orderIndex: 0,
                executionMode: ToolExecutionMode::Sequential,
                batchToolCallCount: 1,
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
