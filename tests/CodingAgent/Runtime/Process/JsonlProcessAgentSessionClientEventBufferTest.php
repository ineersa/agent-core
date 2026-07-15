<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient::events
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient::start
 */
final class JsonlProcessAgentSessionClientEventBufferTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('jsonl-event-buffer');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testStartBuffersLaterEventsFromSameStdoutReadChunk(): void
    {
        $runId = 'batch-run-'.bin2hex(random_bytes(4));
        $client = $this->createStartBatchFakeControllerClient();

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

    public function testRuntimeReadyBuffersLaterEventsFromSameStdoutReadChunk(): void
    {
        $runId = 'ready-batch-'.bin2hex(random_bytes(4));
        $client = $this->createRuntimeReadyBatchFakeControllerClient($runId);

        $handle = $client->start(new StartRunRequest(prompt: 'hello', runId: $runId));

        $this->assertSame($runId, $handle->runId);

        $drained = iterator_to_array($client->events($runId));
        $types = array_map(static fn ($e) => $e->type, $drained);

        $this->assertContains(RuntimeEventTypeEnum::TurnStarted->value, $types);
        $this->assertContains(RuntimeEventTypeEnum::RunCompleted->value, $types);
    }

    public function testParentPollBuffersChildEventFromControllerStreamForLaterChildDrain(): void
    {
        $parentRunId = 'parent-run';
        $childRunId = 'child-agent-run-a';
        $client = $this->createIdleClient();
        $this->injectStdoutJsonlLines($client, [
            $this->jsonlEvent(RuntimeEventTypeEnum::TurnStarted->value, $parentRunId, 1),
            $this->jsonlEvent(RuntimeEventTypeEnum::TurnStarted->value, $childRunId, 2),
        ]);

        $parentFirst = iterator_to_array($client->events($parentRunId));
        $this->assertCount(1, $parentFirst);
        $this->assertSame($parentRunId, $parentFirst[0]->runId);
        $this->assertSame(1, $parentFirst[0]->seq);

        $childDrain = iterator_to_array($client->events($childRunId));
        $this->assertCount(1, $childDrain);
        $this->assertSame($childRunId, $childDrain[0]->runId);
        $this->assertSame(2, $childDrain[0]->seq);
    }

    public function testMultipleChildRunIdsStayIsolatedWhenParentPollsSharedStreamFirst(): void
    {
        $parentRunId = 'parent-run';
        $childA = 'child-agent-a';
        $childB = 'child-agent-b';
        $client = $this->createIdleClient();
        $this->injectStdoutJsonlLines($client, [
            $this->jsonlEvent(RuntimeEventTypeEnum::TurnStarted->value, $childA, 10),
            $this->jsonlEvent(RuntimeEventTypeEnum::TurnStarted->value, $parentRunId, 11),
            $this->jsonlEvent(RuntimeEventTypeEnum::TurnStarted->value, $childB, 12),
        ]);

        $parent = iterator_to_array($client->events($parentRunId));
        $this->assertCount(1, $parent);
        $this->assertSame($parentRunId, $parent[0]->runId);

        $drainA = iterator_to_array($client->events($childA));
        $this->assertCount(1, $drainA);
        $this->assertSame($childA, $drainA[0]->runId);
        $this->assertSame(10, $drainA[0]->seq);

        $drainB = iterator_to_array($client->events($childB));
        $this->assertCount(1, $drainB);
        $this->assertSame($childB, $drainB[0]->runId);
        $this->assertNotContains($childB, array_map(static fn (RuntimeEvent $e): string => $e->runId, $drainA));
    }

    public function testEventsReBuffersNonMatchingRunIdsFromInternalBuffer(): void
    {
        $client = $this->createIdleClient();
        $ref = new \ReflectionClass($client);
        $compact = $ref->getProperty('compactEventBuffer')->getValue($client);
        $compact->ingest(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'child-run', 0, []));
        $compact->ingest(new RuntimeEvent(RuntimeEventTypeEnum::HumanInputRequested->value, 'parent-run', 0, ['question_id' => 'q']));

        $childDrain = iterator_to_array($client->events('child-run'));
        $this->assertCount(1, $childDrain);
        $this->assertSame('child-run', $childDrain[0]->runId);

        $parentDrain = iterator_to_array($client->events('parent-run'));
        $this->assertCount(1, $parentDrain);
        $this->assertSame('parent-run', $parentDrain[0]->runId);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $parentDrain[0]->type);
    }

    public function testBufferWatermarkLogsWhenThresholdExceeded(): void
    {
        $logger = new TestLogger();
        $client = $this->createIdleClient($logger);
        $ref = new \ReflectionClass($client);

        $warn = new \ReflectionClassConstant(JsonlProcessAgentSessionClient::class, 'EVENT_BUFFER_WARNING_THRESHOLD');
        $max = new \ReflectionClassConstant(JsonlProcessAgentSessionClient::class, 'EVENT_BUFFER_MAX');
        $this->assertSame(1000, $warn->getValue());
        $this->assertSame(10000, $max->getValue());

        $compact = $ref->getProperty('compactEventBuffer')->getValue($client);
        for ($i = 0; $i < 1001; ++$i) {
            $compact->ingest(new RuntimeEvent(RuntimeEventTypeEnum::HumanInputRequested->value, 'parent-run', 0, [
                'question_id' => 'q'.$i,
            ]));
        }
        $ref->getMethod('bufferEvent')->invoke($client, new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'parent-run', 0, []), 'test');

        $warnings = array_filter($logger->records, static fn (array $r): bool => 'jsonl_event_buffer.watermark' === ($r['context']['event_type'] ?? ''));
        $this->assertCount(1, $warnings);
    }

    public function testAlternatingEventsDrainEmptiesCompactTailWithoutWatermarkSpam(): void
    {
        $logger = new TestLogger();
        $client = $this->createIdleClient($logger);
        $ref = new \ReflectionClass($client);
        $compact = $ref->getProperty('compactEventBuffer')->getValue($client);
        for ($i = 0; $i < 1200; ++$i) {
            $compact->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'parent-run', 0, [
                'block_id' => 'p',
                'delta' => 'a',
            ]));
            $compact->ingest(new RuntimeEvent(RuntimeEventTypeEnum::AssistantTextDelta->value, 'child-run', 0, [
                'block_id' => 'c',
                'delta' => 'b',
            ]));
        }

        for ($cycle = 0; $cycle < 20; ++$cycle) {
            iterator_to_array($client->events('child-run'));
            iterator_to_array($client->events('parent-run'));
        }

        $this->assertSame(0, $compact->totalTailCount());

        $warnings = array_filter($logger->records, static fn (array $r): bool => 'jsonl_event_buffer.watermark' === ($r['context']['event_type'] ?? ''));
        $this->assertCount(0, $warnings);
    }

    public function testParentPollBuffersOnlyCompactTailForChildStream(): void
    {
        $parentRunId = 'parent-run';
        $childRunId = 'child-agent-run';
        $client = $this->createIdleClient();
        $blockId = 'assistant-block';
        $this->injectStdoutJsonlLines($client, [
            $this->jsonlEvent(RuntimeEventTypeEnum::TurnStarted->value, $parentRunId, 1),
            json_encode([
                'type' => RuntimeEventTypeEnum::AssistantTextDelta->value,
                'runId' => $childRunId,
                'seq' => 0,
                'payload' => ['block_id' => $blockId, 'delta' => 'partial'],
            ], \JSON_THROW_ON_ERROR),
            json_encode([
                'type' => RuntimeEventTypeEnum::AssistantTextCompleted->value,
                'runId' => $childRunId,
                'seq' => 42,
                'payload' => ['block_id' => $blockId, 'text' => 'partial'],
            ], \JSON_THROW_ON_ERROR),
            json_encode([
                'type' => RuntimeEventTypeEnum::RunCompleted->value,
                'runId' => $childRunId,
                'seq' => 43,
                'payload' => [],
            ], \JSON_THROW_ON_ERROR),
        ]);

        iterator_to_array($client->events($parentRunId));

        $childDrain = iterator_to_array($client->events($childRunId));
        $seqZero = array_filter($childDrain, static fn (RuntimeEvent $e): bool => 0 === $e->seq);
        $this->assertSame([], array_values($seqZero), 'Stream checkpoints must prune replay-covered seq=0 tail');
        $this->assertCount(1, $childDrain);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $childDrain[0]->type);
    }

    private function createStartBatchFakeControllerClient(): JsonlProcessAgentSessionClient
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

        return $this->createClientWithFakeScript($fakeScript);
    }

    private function createRuntimeReadyBatchFakeControllerClient(string $runId): JsonlProcessAgentSessionClient
    {
        $fakeScript = $this->tmpDir.'/runtime-ready-batch-controller.php';
        $runIdLiteral = json_encode($runId, \JSON_THROW_ON_ERROR);
        file_put_contents($fakeScript, <<<PHP
<?php
\$runId = {$runIdLiteral};
\$boot = [
    ['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => []],
    ['type' => 'command.ack', 'runId' => \$runId, 'seq' => 1, 'payload' => ['command_id' => 'boot']],
];
fwrite(STDOUT, implode("\n", array_map(static fn (array \$e): string => json_encode(\$e), \$boot)) . "\n");
fflush(STDOUT);
\$line = fgets(STDIN);
if (false === \$line) {
    exit(0);
}
\$cmd = json_decode(trim(\$line), true);
\$runId = is_array(\$cmd) ? (string) (\$cmd['runId'] ?? \$runId) : \$runId;
\$events = [
    ['type' => 'run.started', 'runId' => \$runId, 'seq' => 2, 'payload' => []],
    ['type' => 'turn.started', 'runId' => \$runId, 'seq' => 3, 'payload' => []],
    ['type' => 'run.completed', 'runId' => \$runId, 'seq' => 4, 'payload' => []],
];
fwrite(STDOUT, implode("\n", array_map(static fn (array \$e): string => json_encode(\$e), \$events)) . "\n");
fflush(STDOUT);
while (fgets(STDIN) !== false) {
}
exit(0);
PHP);
        chmod($fakeScript, 0o755);

        return $this->createClientWithFakeScript($fakeScript);
    }

    private function createClientWithFakeScript(string $fakeScript, ?TestLogger $logger = null): JsonlProcessAgentSessionClient
    {
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
            logger: $logger ?? new TestLogger(),
        );
    }

    private function createIdleClient(?TestLogger $logger = null): JsonlProcessAgentSessionClient
    {
        $fakeScript = $this->tmpDir.'/idle.php';
        file_put_contents($fakeScript, '<?php fwrite(STDOUT, json_encode(["type"=>"runtime.ready","runId"=>"","seq"=>0,"payload"=>[]])."\n"); fflush(STDOUT); while(fgets(STDIN)!==false){} exit(0);');
        chmod($fakeScript, 0o755);
        $client = $this->createClientWithFakeScript($fakeScript, $logger);
        $client->attach('parent-run');

        return $client;
    }

    /**
     * @param list<string> $lines complete JSONL lines (with trailing newline in buffer)
     */
    private function injectStdoutJsonlLines(JsonlProcessAgentSessionClient $client, array $lines): void
    {
        $payload = implode("\n", $lines)."\n";
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $payload);
        rewind($stream);

        $ref = new \ReflectionClass($client);
        $pipesProp = $ref->getProperty('pipes');
        $pipes = $pipesProp->getValue($client);
        $pipes[1] = $stream;
        $pipesProp->setValue($client, $pipes);
        $ref->getProperty('stdoutBuffer')->setValue($client, '');
    }

    private function jsonlEvent(string $type, string $runId, int $seq): string
    {
        return json_encode([
            'type' => $type,
            'runId' => $runId,
            'seq' => $seq,
            'payload' => [],
        ], \JSON_THROW_ON_ERROR);
    }
}
