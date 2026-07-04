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
use PHPUnit\Framework\TestCase;

/**
 * Controller subprocess env must route Doctrine transports to messenger_transport
 * connection, not default app DB.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient
 */
final class JsonlProcessAgentSessionClientTransportDsnTest extends TestCase
{
    private string $tmpDir;

    private string $fakeScript;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('jsonl-dsn');
        $this->fakeScript = $this->tmpDir.'/controller.php';

        file_put_contents($this->fakeScript, <<<'PHP'
<?php
$dumpFile = null;
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--env-dump=')) {
        $dumpFile = substr($arg, strlen('--env-dump='));
    }
}
if (null === $dumpFile) {
    fwrite(STDERR, "missing --env-dump=\n");
    exit(1);
}
$keys = [
    'HATFIELD_RUN_CONTROL_TRANSPORT_DSN',
    'HATFIELD_LLM_TRANSPORT_DSN',
    'HATFIELD_TOOL_TRANSPORT_DSN',
    'HATFIELD_AGENT_TRANSPORT_DSN',
    'HATFIELD_MCP_TRANSPORT_DSN',
];
$out = [];
foreach ($keys as $key) {
    $out[$key] = getenv($key) ?: '';
}
file_put_contents($dumpFile, json_encode($out));
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);
$line = fgets(STDIN);
fwrite(STDOUT, json_encode(['type' => 'run.started', 'runId' => 'test-run', 'seq' => 1, 'payload' => ['status' => 'running']]) . "\n");
exit(0);
PHP);
        chmod($this->fakeScript, 0o755);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testSpawnedControllerUsesMessengerTransportDoctrineConnection(): void
    {
        $envDump = $this->tmpDir.'/env.json';
        $dumpFlag = '--env-dump='.$envDump;

        $runtimeConfig = new RuntimeProcessConfig(
            executableLocator: new class($this->fakeScript, $dumpFlag) implements AppExecutableLocator {
                public function __construct(
                    private readonly string $script,
                    private readonly string $dumpFlag,
                ) {
                }

                public function command(): array
                {
                    return [\PHP_BINARY, $this->script, $this->dumpFlag];
                }

                public function path(): string
                {
                    return $this->script;
                }
            },
            runtimeCwd: $this->tmpDir,
        );

        $client = new JsonlProcessAgentSessionClient(
            runtimeConfig: $runtimeConfig,
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            logger: new TestLogger(),
        );

        $client->start(new StartRunRequest(
            prompt: 'hello',
            runId: 'session-42',
        ));

        $timeout = time() + 5;
        while (!is_file($envDump) || 0 === filesize($envDump)) {
            if (time() > $timeout) {
                self::fail('Timeout waiting for env dump at '.$envDump);
            }
            usleep(50_000);
        }

        $raw = file_get_contents($envDump);
        self::assertIsString($raw);
        /** @var array<string, string> $env */
        $env = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        foreach ($env as $key => $dsn) {
            self::assertStringStartsWith('doctrine://messenger_transport?', $dsn, $key);
        }

        self::assertStringContainsString('run_control_session-42', $env['HATFIELD_RUN_CONTROL_TRANSPORT_DSN']);
        self::assertStringContainsString('llm_session-42', $env['HATFIELD_LLM_TRANSPORT_DSN']);
        self::assertStringContainsString('tool_session-42', $env['HATFIELD_TOOL_TRANSPORT_DSN']);
        self::assertStringNotContainsString('doctrine://default', implode(' ', $env));
    }
}