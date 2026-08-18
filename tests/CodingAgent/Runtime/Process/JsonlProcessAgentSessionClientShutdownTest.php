<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * /reload teardown contract: shutdown() must synchronously stop a spawned
 * controller process and be safe/idempotent when nothing is running.
 */
final class JsonlProcessAgentSessionClientShutdownTest extends TestCase
{
    private string $tmpDir;

    private string $fakeScript;

    private string $pidFile;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('jsonl-shutdown');
        $this->pidFile = $this->tmpDir.'/controller.pid';
        $this->fakeScript = $this->tmpDir.'/controller.php';

        // Fake controller: records its PID, announces runtime.ready, then
        // stays alive until killed (mirrors the real controller lifecycle).
        file_put_contents($this->fakeScript, <<<'PHP'
<?php
file_put_contents($argv[1], (string) getmypid());
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);
fgets(STDIN); // consume the start_run command
fwrite(STDOUT, json_encode(['type' => 'run.started', 'runId' => 'test-run', 'seq' => 1, 'payload' => ['status' => 'running']]) . "\n");
fflush(STDOUT);
while (true) {
    usleep(100_000);
}
PHP);
        chmod($this->fakeScript, 0o755);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function testShutdownStopsSpawnedControllerProcess(): void
    {
        $client = $this->createClient();
        $client->start(new StartRunRequest(
            prompt: 'hello',
            runId: 'session-42',
        ));

        $pid = $this->waitForPidFile();
        $this->assertTrue($this->processAlive($pid), 'Controller must be running after start()');

        $client->shutdown();

        $this->assertFalse($this->processAlive($pid), 'Controller must be stopped by shutdown()');
    }

    #[Test]
    public function testShutdownIsSafeAndIdempotentWithoutProcess(): void
    {
        $client = $this->createClient();

        // No process was ever spawned — shutdown must be a safe no-op.
        $client->shutdown();
        $client->shutdown();

        $this->addToAssertionCount(1);
    }

    private function createClient(): JsonlProcessAgentSessionClient
    {
        $runtimeConfig = new RuntimeProcessConfig(
            executableLocator: new class($this->fakeScript, $this->pidFile) implements AppExecutableLocator {
                public function __construct(
                    private readonly string $script,
                    private readonly string $pidFile,
                ) {
                }

                public function command(): array
                {
                    return [\PHP_BINARY, $this->script, $this->pidFile];
                }

                public function path(): string
                {
                    return $this->script;
                }
            },
            runtimeCwd: $this->tmpDir,
        );

        return new JsonlProcessAgentSessionClient(
            runtimeConfig: $runtimeConfig,
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            toolFilterConfig: new ToolFilterRuntimeConfig(),
            logger: new TestLogger(),
        );
    }

    private function waitForPidFile(): int
    {
        $timeout = time() + 5;
        while (!is_file($this->pidFile)) {
            if (time() > $timeout) {
                $this->fail('Timeout waiting for controller PID file at '.$this->pidFile);
            }
            usleep(50_000);
        }

        $pid = (int) file_get_contents($this->pidFile);

        return $pid;
    }

    private function processAlive(int $pid): bool
    {
        // posix_kill($pid, 0) probes existence without signalling.
        // After proc_close() the child is reaped, so a dead PID returns false.
        return \function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}
