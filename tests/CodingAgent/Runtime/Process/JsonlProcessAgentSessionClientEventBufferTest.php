<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/** @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient::events */
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

    public function testEventsReBuffersNonMatchingRunIdsFromInternalBuffer(): void
    {
        $client = $this->createIdleClient();
        $ref = new \ReflectionClass($client);
        $buffer = $ref->getProperty('eventBuffer')->getValue($client);

        $buffer->enqueue(new RuntimeEvent(RuntimeEventTypeEnum::RunCompleted->value, 'parent-run', 10, []));
        $buffer->enqueue(new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'child-run', 5, []));

        $childDrain = iterator_to_array($client->events('child-run'));
        $this->assertCount(1, $childDrain);
        $this->assertSame('child-run', $childDrain[0]->runId);

        $parentDrain = iterator_to_array($client->events('parent-run'));
        $this->assertCount(1, $parentDrain);
        $this->assertSame('parent-run', $parentDrain[0]->runId);
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

        $bufferMethod = $ref->getMethod('bufferEvent');
        for ($i = 0; $i < 1001; ++$i) {
            $bufferMethod->invoke($client, new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, 'parent-run', $i, []), 'test');
        }

        $warnings = array_filter($logger->records, static fn (array $r): bool => 'jsonl_event_buffer.watermark' === ($r['context']['event_type'] ?? ''));
        $this->assertNotEmpty($warnings);
    }

    private function createIdleClient(?TestLogger $logger = null): JsonlProcessAgentSessionClient
    {
        $fakeScript = $this->tmpDir.'/idle.php';
        file_put_contents($fakeScript, '<?php fwrite(STDOUT, json_encode(["type"=>"runtime.ready","runId"=>"","seq"=>0,"payload"=>[]])."\n"); fflush(STDOUT); while(fgets(STDIN)!==false){} exit(0);');
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
        $client = new JsonlProcessAgentSessionClient(
            runtimeConfig: new RuntimeProcessConfig($locator, $this->tmpDir),
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            logger: $logger ?? new TestLogger(),
        );
        $client->attach('parent-run');

        return $client;
    }
}
