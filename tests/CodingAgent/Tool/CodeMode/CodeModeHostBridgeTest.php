<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeIpc;
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

    private function bridge(): CodeModeHostBridge
    {
        $bridge = self::getContainer()->get('test.code_mode_host_bridge');
        $this->assertInstanceOf(CodeModeHostBridge::class, $bridge);

        return $bridge;
    }

    private function runScript(CodeModeHostBridge $bridge, string $script): mixed
    {
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $this->assertInstanceOf(StackToolExecutionContextAccessor::class, $accessor);

        return $accessor->with(
            new ToolContext(
                runId: 'code-mode-bridge-test',
                turnNo: 1,
                toolCallId: 'code-mode-bridge-test-1',
                toolName: 'code_mode',
                cancellationToken: new NullCancellationToken(),
                timeoutSeconds: 10,
                orderIndex: 0,
                executionMode: ToolExecutionMode::Sequential,
                batchToolCallCount: 1,
                humanInputAnswer: null,
                stepId: 'code-mode-bridge-test-step',
                parentModel: null,
            ),
            static fn (): mixed => $bridge->execute($script),
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
