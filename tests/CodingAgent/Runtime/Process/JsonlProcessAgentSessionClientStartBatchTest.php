<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient::start
 */
final class JsonlProcessAgentSessionClientStartBatchTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('jsonl-start-batch');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testStartBuffersLaterEventsFromSameStdoutReadChunk(): void
    {
        $runId = 'batch-run-'.bin2hex(random_bytes(4));
        $client = $this->createClientWithFakeController();

        $handle = $client->start(new StartRunRequest(prompt: 'hello', runId: $runId));

        $this->assertSame($runId, $handle->runId);
        $this->assertSame('running', $handle->status);

        $drained = iterator_to_array($client->events($runId));
        $types = array_map(static fn ($e) => $e->type, $drained);

        $this->assertSame(
            [
                RuntimeEventTypeEnum::TurnStarted->value,
                RuntimeEventTypeEnum::RunCompleted->value,
            ],
            $types,
            'Events after run.started in the same stdout chunk must be buffered for events()',
        );

        $secondDrain = iterator_to_array($client->events($runId));
        $this->assertSame([], $secondDrain, 'Buffered post-start events must be delivered exactly once');
    }

    private function createClientWithFakeController(): JsonlProcessAgentSessionClient
    {
        $fakeScript = $this->tmpDir.'/start-batch-controller.php';
        file_put_contents($fakeScript, <<<'PHP'
<?php
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => []]) . "\n");
fflush(STDOUT);
$line = fgets(STDIN);
if (false === $line) {
    exit(0);
}
$cmd = json_decode(trim($line), true);
$runId = is_array($cmd) ? (string) ($cmd['runId'] ?? 'batch-run') : 'batch-run';
$events = [
    ['type' => 'run.started', 'runId' => $runId, 'seq' => 1, 'payload' => []],
    ['type' => 'turn.started', 'runId' => $runId, 'seq' => 2, 'payload' => []],
    ['type' => 'run.completed', 'runId' => $runId, 'seq' => 3, 'payload' => []],
];
fwrite(STDOUT, implode("\n", array_map(static fn (array $e): string => json_encode($e), $events)) . "\n");
fflush(STDOUT);
while (fgets(STDIN) !== false) {
}
exit(0);
PHP);
        chmod($fakeScript, 0o755);

        $locator = new class($fakeScript) implements AppExecutableLocator {
            public function __construct(private string $script)
            {
            }

            public function command(): array
            {
                return [\PHP_BINARY, $this->script];
            }

            public function path(): string
            {
                return $this->script;
            }
        };

        return new JsonlProcessAgentSessionClient(
            runtimeConfig: new RuntimeProcessConfig($locator, $this->tmpDir),
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            logger: new TestLogger(),
        );
    }
}
