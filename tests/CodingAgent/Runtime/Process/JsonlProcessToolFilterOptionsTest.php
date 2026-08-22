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
use PHPUnit\Framework\TestCase;

/**
 * Spawn-seam proof: process transport forwards canonical --tools / --tools-excluded
 * argv and HATFIELD_TOOLS* env on every spawnProcess() invocation.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient
 * @covers \Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig
 */
final class JsonlProcessToolFilterOptionsTest extends TestCase
{
    private string $tmpDir;

    private string $fakeScript;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('tool-filter-jsonl');
        $this->fakeScript = $this->tmpDir.'/controller.php';

        // Fake controller dumps argv + env, emits runtime.ready, accepts start_run.
        file_put_contents($this->fakeScript, <<<'PHP'
<?php
$dumpFile = null;
$realArgs = [];
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--argv-dump=')) {
        $dumpFile = substr($arg, strlen('--argv-dump='));
        continue;
    }
    $realArgs[] = $arg;
}
if (null === $dumpFile) {
    fwrite(STDERR, "Fake controller: --argv-dump= not found\n");
    exit(1);
}
file_put_contents($dumpFile, json_encode([
    'argv' => $realArgs,
    'env' => [
        'HATFIELD_TOOLS' => getenv('HATFIELD_TOOLS') === false ? null : getenv('HATFIELD_TOOLS'),
        'HATFIELD_TOOLS_EXCLUDED' => getenv('HATFIELD_TOOLS_EXCLUDED') === false ? null : getenv('HATFIELD_TOOLS_EXCLUDED'),
    ],
]));
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);
$line = fgets(STDIN);
if (false === $line) { exit(0); }
fwrite(STDOUT, json_encode(['type' => 'run.started', 'runId' => 'test-run', 'seq' => 1, 'payload' => ['status' => 'running']]) . "\n");
fflush(STDOUT);
exit(0);
PHP);
        chmod($this->fakeScript, 0o755);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testEmptyFiltersOmitArgvAndEnv(): void
    {
        $dump = $this->startAndCapture(tools: '', toolsExcluded: '');

        $this->assertContains('agent', $dump['argv']);
        $this->assertContains('--controller', $dump['argv']);
        foreach ($dump['argv'] as $arg) {
            $this->assertStringStartsNotWith('--tools=', $arg);
            $this->assertStringStartsNotWith('--tools-excluded=', $arg);
        }
        $this->assertNull($dump['env']['HATFIELD_TOOLS']);
        $this->assertNull($dump['env']['HATFIELD_TOOLS_EXCLUDED']);
    }

    public function testConfiguredFiltersAppearInArgvAndEnvOnEverySpawn(): void
    {
        $toolFilter = new ToolFilterRuntimeConfig();
        $toolFilter->tools = 'read,bash';
        $toolFilter->toolsExcluded = 'bash';

        $firstDumpFile = $this->tmpDir.'/dump-1.json';
        $client = $this->createClient($toolFilter, $firstDumpFile);
        $client->start(new StartRunRequest(prompt: 'hello', runId: 'run-a'));
        $first = $this->waitForDump($firstDumpFile);

        $this->assertContains('--tools=read,bash', $first['argv']);
        $this->assertContains('--tools-excluded=bash', $first['argv']);
        $this->assertSame('read,bash', $first['env']['HATFIELD_TOOLS']);
        $this->assertSame('bash', $first['env']['HATFIELD_TOOLS_EXCLUDED']);

        // Second client shares the same ToolFilterRuntimeConfig and proves
        // immutable config reuse at the common spawnProcess() seam. This does
        // not force ensureProcessRunning() crash/session restart.
        $secondDumpFile = $this->tmpDir.'/dump-2.json';
        $client2 = $this->createClient($toolFilter, $secondDumpFile);
        $client2->start(new StartRunRequest(prompt: 'hello', runId: 'run-b'));
        $second = $this->waitForDump($secondDumpFile);

        $this->assertSame($first['argv'], $second['argv']);
        $this->assertSame($first['env'], $second['env']);
    }

    /**
     * @return array{argv: list<string>, env: array{HATFIELD_TOOLS: ?string, HATFIELD_TOOLS_EXCLUDED: ?string}}
     */
    private function startAndCapture(string $tools, string $toolsExcluded): array
    {
        $dumpFile = $this->tmpDir.'/dump-'.bin2hex(random_bytes(4)).'.json';
        $toolFilter = new ToolFilterRuntimeConfig();
        $toolFilter->tools = $tools;
        $toolFilter->toolsExcluded = $toolsExcluded;

        $client = $this->createClient($toolFilter, $dumpFile);
        $client->start(new StartRunRequest(
            prompt: 'hello',
            runId: 'test-run-'.bin2hex(random_bytes(4)),
        ));

        return $this->waitForDump($dumpFile);
    }

    private function createClient(ToolFilterRuntimeConfig $toolFilter, string $dumpFile): JsonlProcessAgentSessionClient
    {
        $dumpFlag = '--argv-dump='.$dumpFile;
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

        return new JsonlProcessAgentSessionClient(
            runtimeConfig: $runtimeConfig,
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            toolFilterConfig: $toolFilter,
            logger: new TestLogger(),
        );
    }

    /**
     * @return array{argv: list<string>, env: array{HATFIELD_TOOLS: ?string, HATFIELD_TOOLS_EXCLUDED: ?string}}
     */
    private function waitForDump(string $dumpFile): array
    {
        // Fake controller writes the dump before emitting runtime.ready; start()
        // already waited for that event, so the dump must exist now.
        $this->assertFileExists($dumpFile, 'dump must exist after runtime.ready');
        $this->assertGreaterThan(0, filesize($dumpFile), 'dump must be non-empty after runtime.ready');

        $json = file_get_contents($dumpFile);
        $this->assertNotFalse($json);
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('argv', $data);
        $this->assertArrayHasKey('env', $data);
        $this->assertIsArray($data['argv']);
        $this->assertIsArray($data['env']);

        return $data;
    }
}
