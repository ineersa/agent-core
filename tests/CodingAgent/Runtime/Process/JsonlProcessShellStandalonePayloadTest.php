<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient::send
 */
final class JsonlProcessShellStandalonePayloadTest extends TestCase
{
    private string $tmpDir;
    private string $fakeScript;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('jsonl-shell');
        $this->fakeScript = $this->tmpDir.'/controller.php';

        file_put_contents($this->fakeScript, <<<'PHP'
<?php
$dumpFile = null;
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--commands-dump=')) {
        $dumpFile = substr($arg, strlen('--commands-dump='));
    }
}
if (null === $dumpFile) {
    fwrite(STDERR, "missing --commands-dump\n");
    exit(1);
}

fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);

$commands = [];
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ('' === $line) {
        continue;
    }
    $commands[] = json_decode($line, true);
    $cmd = end($commands);
    if (!is_array($cmd)) {
        continue;
    }
    if ('start_run' === ($cmd['type'] ?? '')) {
        fwrite(STDOUT, json_encode(['type' => 'run.started', 'runId' => $cmd['runId'] ?? 'run', 'seq' => 1, 'payload' => ['status' => 'running']]) . "\n");
        fflush(STDOUT);
    }
    if ('shell_command' === ($cmd['type'] ?? '')) {
        file_put_contents($dumpFile, json_encode($commands));
        exit(0);
    }
}
file_put_contents($dumpFile, json_encode($commands));
exit(0);
PHP);
        chmod($this->fakeScript, 0o755);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testSendShellCommandForwardsStandalonePayload(): void
    {
        $commandsFile = $this->tmpDir.'/commands.json';
        $dumpFlag = '--commands-dump='.$commandsFile;

        $runtimeConfig = new RuntimeProcessConfig(
            executableLocator: new class($this->fakeScript, $dumpFlag) implements AppExecutableLocator {
                public function __construct(
                    private string $fakeScript,
                    private string $dumpFlag,
                ) {
                }

                public function command(): array
                {
                    return [\PHP_BINARY, $this->fakeScript, $this->dumpFlag];
                }

                public function path(): string
                {
                    return $this->fakeScript;
                }
            },
            runtimeCwd: $this->tmpDir,
        );

        $client = new JsonlProcessAgentSessionClient(
            runtimeConfig: $runtimeConfig,
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            logger: new TestLogger(),
        );

        $runId = 'run-shell-'.bin2hex(random_bytes(4));
        $client->start(new \Ineersa\CodingAgent\Runtime\Contract\StartRunRequest(
            prompt: 'hello',
            runId: $runId,
        ));

        $client->send($runId, new UserCommand(
            type: 'shell_command',
            text: 'ls -1',
            payload: ['standalone' => true],
        ));

        $deadline = time() + 5;
        while (!is_file($commandsFile) && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($commandsFile);
        $commands = json_decode((string) file_get_contents($commandsFile), true);
        $this->assertIsArray($commands);

        $shell = null;
        foreach ($commands as $command) {
            if (\is_array($command) && 'shell_command' === ($command['type'] ?? '')) {
                $shell = $command;
                break;
            }
        }

        $this->assertNotNull($shell, 'shell_command JSONL line must be written');
        $this->assertSame('ls -1', $shell['payload']['text'] ?? null);
        $this->assertTrue($shell['payload']['standalone'] ?? false);
    }
}
