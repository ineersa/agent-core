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
    'APP_ENV',
    'APP_DEBUG',
    'HATFIELD_RUN_CONTROL_TRANSPORT_DSN',
    'HATFIELD_LLM_TRANSPORT_DSN',
    'HATFIELD_TOOL_TRANSPORT_DSN',
    'HATFIELD_AGENT_TRANSPORT_DSN',
    'HATFIELD_MCP_TRANSPORT_DSN',
    'HATFIELD_TEST_DATABASE_PATH',
    'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH',
];
$out = [];
foreach ($keys as $key) {
    $value = getenv($key);
    $out[$key] = false === $value ? '' : $value;
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
                $this->fail('Timeout waiting for env dump at '.$envDump);
            }
            usleep(50_000);
        }

        $raw = file_get_contents($envDump);
        $this->assertIsString($raw);
        /** @var array<string, string> $env */
        $env = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        foreach ([
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN',
            'HATFIELD_LLM_TRANSPORT_DSN',
            'HATFIELD_TOOL_TRANSPORT_DSN',
            'HATFIELD_AGENT_TRANSPORT_DSN',
            'HATFIELD_MCP_TRANSPORT_DSN',
        ] as $dsnKey) {
            $this->assertStringStartsWith('doctrine://messenger_transport?', $env[$dsnKey], $dsnKey);
        }

        $this->assertStringContainsString('run_control_session-42', $env['HATFIELD_RUN_CONTROL_TRANSPORT_DSN']);
        $this->assertStringContainsString('llm_session-42', $env['HATFIELD_LLM_TRANSPORT_DSN']);
        $this->assertStringContainsString('tool_session-42', $env['HATFIELD_TOOL_TRANSPORT_DSN']);
        $this->assertStringNotContainsString('doctrine://default', implode(' ', $env));
    }

    public function testSpawnedControllerDefaultsToProductionKernelEnv(): void
    {
        $saved = $this->snapshotTestDatabaseEnv();
        try {
            $this->restoreEnvVar('APP_ENV', null);
            $this->restoreEnvVar('APP_DEBUG', null);

            $envDump = $this->tmpDir.'/env-prod-defaults.json';
            $client = $this->createClientWithEnvDump($envDump);
            $client->start(new StartRunRequest(prompt: 'hello', runId: 'session-prod-defaults'));

            $env = $this->waitForEnvDump($envDump);
            $this->assertSame('prod', $env['APP_ENV']);
            $this->assertSame('0', $env['APP_DEBUG']);
        } finally {
            $this->restoreTestDatabaseEnv($saved);
        }
    }

    public function testSpawnedControllerForwardsExplicitTestDatabaseEnvFromParentProcess(): void
    {
        $saved = $this->snapshotTestDatabaseEnv();
        try {
            $appPath = 'app_test-explicit.sqlite';
            $transportPath = 'messenger_transport_test-explicit.sqlite';
            putenv('APP_ENV=test');
            $_ENV['APP_ENV'] = 'test';
            $_SERVER['APP_ENV'] = 'test';
            putenv('HATFIELD_TEST_DATABASE_PATH='.$appPath);
            $_ENV['HATFIELD_TEST_DATABASE_PATH'] = $appPath;
            $_SERVER['HATFIELD_TEST_DATABASE_PATH'] = $appPath;
            putenv('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH='.$transportPath);
            $_ENV['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] = $transportPath;
            $_SERVER['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] = $transportPath;

            $envDump = $this->tmpDir.'/env-explicit.json';
            $client = $this->createClientWithEnvDump($envDump);
            $client->start(new StartRunRequest(prompt: 'hello', runId: 'session-env'));

            $env = $this->waitForEnvDump($envDump);
            $this->assertSame($appPath, $env['HATFIELD_TEST_DATABASE_PATH']);
            $this->assertSame($transportPath, $env['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH']);
        } finally {
            $this->restoreTestDatabaseEnv($saved);
        }
    }

    /**
     * @return array{app_env: ?string, app_debug: ?string, app_path: ?string, transport_path: ?string}
     */
    private function snapshotTestDatabaseEnv(): array
    {
        $transport = getenv('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH');

        return [
            'app_env' => getenv('APP_ENV') ?: null,
            'app_debug' => getenv('APP_DEBUG') ?: null,
            'app_path' => getenv('HATFIELD_TEST_DATABASE_PATH') ?: null,
            'transport_path' => false === $transport ? null : ($transport ?: null),
        ];
    }

    /**
     * @param array{app_env: ?string, app_debug: ?string, app_path: ?string, transport_path: ?string} $saved
     */
    private function restoreTestDatabaseEnv(array $saved): void
    {
        $this->restoreEnvVar('APP_ENV', $saved['app_env']);
        $this->restoreEnvVar('APP_DEBUG', $saved['app_debug']);
        $this->restoreEnvVar('HATFIELD_TEST_DATABASE_PATH', $saved['app_path']);
        $this->restoreEnvVar('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH', $saved['transport_path']);
    }

    private function restoreEnvVar(string $name, ?string $value): void
    {
        if (null === $value) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function createClientWithEnvDump(string $envDumpPath): JsonlProcessAgentSessionClient
    {
        $dumpFlag = '--env-dump='.$envDumpPath;
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

        return new JsonlProcessAgentSessionClient(
            runtimeConfig: $runtimeConfig,
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            logger: new TestLogger(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function waitForEnvDump(string $envDump): array
    {
        $timeout = time() + 5;
        while (!is_file($envDump) || 0 === filesize($envDump)) {
            if (time() > $timeout) {
                $this->fail('Timeout waiting for env dump at '.$envDump);
            }
            usleep(50_000);
        }

        $raw = file_get_contents($envDump);
        $this->assertIsString($raw);
        /** @var array<string, string> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
