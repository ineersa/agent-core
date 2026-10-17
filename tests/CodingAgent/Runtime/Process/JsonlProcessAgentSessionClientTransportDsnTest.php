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

/**
 * Controller subprocess env must publish session-owned sqlite-queue broker paths and DSNs.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient
 */
final class JsonlProcessAgentSessionClientTransportDsnTest extends \Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase
{
    private string $tmpDir;

    private string $fakeScript;

    protected function setUp(): void
    {
        parent::setUp();
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
    'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN',
    'HATFIELD_SQLITE_QUEUE_DATABASE',
    'HATFIELD_SQLITE_QUEUE_ENDPOINT',
    'HATFIELD_SESSION_ID',
    'HATFIELD_TEST_DATABASE_PATH',
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
$command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
file_put_contents($dumpFile.'.command', json_encode($command));
fwrite(STDOUT, json_encode(['type' => 'run.started', 'runId' => $command['runId'], 'seq' => 1, 'payload' => ['status' => 'running']]) . "\n");
exit(0);
PHP);
        chmod($this->fakeScript, 0o755);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testSpawnedControllerUsesSessionSqliteQueueTransportDsns(): void
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
            toolFilterConfig: new ToolFilterRuntimeConfig(),
            logger: new TestLogger(),
            sessionStore: self::getContainer()->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class),
        );

        $client->start(new StartRunRequest(
            prompt: 'hello',
            runId: 'session-42',
        ));

        // Fake controller writes the env dump before emitting runtime.ready;
        // start() already waited for that event, so the dump must exist now.
        $this->assertFileExists($envDump, 'env dump must exist after runtime.ready');
        $this->assertGreaterThan(0, filesize($envDump), 'env dump must be non-empty after runtime.ready');

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
            'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN',
        ] as $dsnKey) {
            $this->assertStringStartsWith('sqlite-queue://', $env[$dsnKey], $dsnKey);
            $this->assertStringContainsString('endpoint=', $env[$dsnKey], $dsnKey);
        }

        $this->assertStringStartsWith('sqlite-queue://run_control?', $env['HATFIELD_RUN_CONTROL_TRANSPORT_DSN']);
        $this->assertStringStartsWith('sqlite-queue://llm?', $env['HATFIELD_LLM_TRANSPORT_DSN']);
        $this->assertStringStartsWith('sqlite-queue://tool?', $env['HATFIELD_TOOL_TRANSPORT_DSN']);
        $this->assertStringStartsWith('sqlite-queue://agent?', $env['HATFIELD_AGENT_TRANSPORT_DSN']);
        $this->assertStringStartsWith('sqlite-queue://mcp?', $env['HATFIELD_MCP_TRANSPORT_DSN']);
        $this->assertStringStartsWith('sqlite-queue://extension_agent?', $env['HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN']);
        $this->assertNotSame('', $env['HATFIELD_SQLITE_QUEUE_DATABASE'] ?? '');
        $this->assertNotSame('', $env['HATFIELD_SQLITE_QUEUE_ENDPOINT'] ?? '');
        $this->assertStringEndsWith('/session-42/messenger-queue.sqlite', $env['HATFIELD_SQLITE_QUEUE_DATABASE']);
        $this->assertMatchesRegularExpression('#^/tmp/hq-[0-9a-f]{16}/q\\.sock$#', $env['HATFIELD_SQLITE_QUEUE_ENDPOINT']);
        $this->assertStringNotContainsString('doctrine://', implode(' ', $env));
    }

    // DAMA rolls back at the next in-process TestStarted event. Keep this DB
    // writer isolated so a following separate-process TUI test cannot inherit
    // the parent's still-open write transaction and block on the shared QA DB.
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testAnonymousStartsAllocateDistinctCanonicalQueuesAndRecoverTheSameIdentity(): void
    {
        $store = self::getContainer()->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class);
        $firstDump = $this->tmpDir.'/anonymous-first.json';
        $secondDump = $this->tmpDir.'/anonymous-second.json';
        $first = $this->createClientWithEnvDump($firstDump);
        $second = $this->createClientWithEnvDump($secondDump);
        try {
            $one = $first->start(new StartRunRequest(prompt: 'first anonymous prompt'));
            $two = $second->start(new StartRunRequest(prompt: 'second anonymous prompt'));
            $this->assertMatchesRegularExpression('/^[0-9]+$/', $one->runId);
            $this->assertNotSame($one->runId, $two->runId);
            $this->assertSame('first anonymous prompt', $store->findSession($one->runId)?->prompt);
            $this->assertSame('second anonymous prompt', $store->findSession($two->runId)?->prompt);
            $env = $this->waitForEnvDump($firstDump);
            $otherEnv = $this->waitForEnvDump($secondDump);
            $expected = $store->resolveSessionsBasePath().'/'.$one->runId.'/messenger-queue.sqlite';
            $this->assertSame($expected, $env['HATFIELD_SQLITE_QUEUE_DATABASE']);
            $this->assertNotSame($expected, $otherEnv['HATFIELD_SQLITE_QUEUE_DATABASE']);
            $this->assertSame($one->runId, $env['HATFIELD_SESSION_ID']);
            $this->assertFileExists($store->resolveSessionsBasePath().'/'.$one->runId.'/events.jsonl');
            $command = json_decode((string) file_get_contents($firstDump.'.command'), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame($one->runId, $command['runId']);

            // The fixture exits after acknowledging start. Wait for actual death,
            // then exercise transparent recovery through the public event poll.
            $running = new \ReflectionMethod($first, 'isProcessRunning');
            $deadline = microtime(true) + 2.0;
            while (true === $running->invoke($first) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFalse($running->invoke($first));
            unlink($firstDump.'.command');
            iterator_to_array($first->events($one->runId));
            while (!is_file($firstDump.'.command') && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($firstDump.'.command');
            $resume = json_decode((string) file_get_contents($firstDump.'.command'), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame('resume', $resume['type']);
            $this->assertSame($one->runId, $resume['runId']);
            $recoveredEnv = $this->waitForEnvDump($firstDump);
            $this->assertSame($expected, $recoveredEnv['HATFIELD_SQLITE_QUEUE_DATABASE']);
            $this->assertSame($one->runId, $recoveredEnv['HATFIELD_SESSION_ID']);
        } finally {
            $first->shutdown();
            $second->shutdown();
        }
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
            putenv('APP_ENV=test');
            $_ENV['APP_ENV'] = 'test';
            $_SERVER['APP_ENV'] = 'test';
            putenv('HATFIELD_TEST_DATABASE_PATH='.$appPath);
            $_ENV['HATFIELD_TEST_DATABASE_PATH'] = $appPath;
            $_SERVER['HATFIELD_TEST_DATABASE_PATH'] = $appPath;

            $envDump = $this->tmpDir.'/env-explicit.json';
            $client = $this->createClientWithEnvDump($envDump);
            $client->start(new StartRunRequest(prompt: 'hello', runId: 'session-env'));

            $env = $this->waitForEnvDump($envDump);
            $this->assertSame($appPath, $env['HATFIELD_TEST_DATABASE_PATH']);
        } finally {
            $this->restoreTestDatabaseEnv($saved);
        }
    }

    /**
     * @return array{app_env: ?string, app_debug: ?string, app_path: ?string}
     */
    private function snapshotTestDatabaseEnv(): array
    {
        return [
            'app_env' => getenv('APP_ENV') ?: null,
            'app_debug' => getenv('APP_DEBUG') ?: null,
            'app_path' => getenv('HATFIELD_TEST_DATABASE_PATH') ?: null,
        ];
    }

    /**
     * @param array{app_env: ?string, app_debug: ?string, app_path: ?string} $saved
     */
    private function restoreTestDatabaseEnv(array $saved): void
    {
        $this->restoreEnvVar('APP_ENV', $saved['app_env']);
        $this->restoreEnvVar('APP_DEBUG', $saved['app_debug']);
        $this->restoreEnvVar('HATFIELD_TEST_DATABASE_PATH', $saved['app_path']);
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
            toolFilterConfig: new ToolFilterRuntimeConfig(),
            logger: new TestLogger(),
            sessionStore: self::getContainer()->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class),
        );
    }

    /**
     * @return array<string, string>
     */
    private function waitForEnvDump(string $envDump): array
    {
        // Fake controller writes the env dump before emitting runtime.ready;
        // start() already waited for that event, so the dump must exist now.
        $this->assertFileExists($envDump, 'env dump must exist after runtime.ready');
        $this->assertGreaterThan(0, filesize($envDump), 'env dump must be non-empty after runtime.ready');

        $raw = file_get_contents($envDump);
        $this->assertIsString($raw);
        /** @var array<string, string> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
