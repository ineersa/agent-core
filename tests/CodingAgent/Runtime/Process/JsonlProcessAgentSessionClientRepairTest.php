<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use PHPUnit\Framework\TestCase;

/**
 * /repair must round-trip through the controller JSONL seam and wait for
 * session.repair.completed before returning to the TUI.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient
 */
final class JsonlProcessAgentSessionClientRepairTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('jsonl-repair');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testRepairWaitsForCompletedEventAndReturnsScalars(): void
    {
        $script = $this->tmpDir.'/controller.php';
        file_put_contents($script, <<<'PHP'
<?php
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);
$line = fgets(STDIN);
$command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
fwrite(STDOUT, json_encode([
    'type' => 'command.ack',
    'runId' => $command['runId'] ?? '',
    'seq' => 0,
    'payload' => [
        'commandId' => $command['id'],
        'commandType' => $command['type'],
        'status' => 'accepted',
    ],
]) . "\n");
fwrite(STDOUT, json_encode([
    'type' => 'session.repair.completed',
    'runId' => $command['runId'] ?? '',
    'seq' => 0,
    'payload' => [
        'commandId' => $command['id'],
        'commandType' => $command['type'],
        'status' => 'completed',
        'repairable_stale_cancellation_detected' => false,
        'stale_cancellation_repaired' => false,
        'message' => 'Active operation redriven.',
        'refusal_reason' => null,
        'active_operations_redriven' => 1,
    ],
]) . "\n" . json_encode([
    'type' => 'run.completed',
    'runId' => $command['runId'],
    'seq' => 1,
    'payload' => [],
]) . "\n");
fflush(STDOUT);
// Stay alive until the owner shuts down, without a timing window.
while (false !== fgets(STDIN)) {}
PHP);
        chmod($script, 0o755);

        $client = $this->createClient($script);
        try {
            $result = $client->repair('session-repair-1', true);
            $this->assertSame(1, $result->activeOperationsRedriven);
            $this->assertSame('Active operation redriven.', $result->message);
            $this->assertNull($result->refusalReason);
            $events = iterator_to_array($client->events('session-repair-1'));
            $this->assertContains('run.completed', array_map(static fn ($event): string => $event->type, $events));
        } finally {
            $client->shutdown();
        }
    }

    public function testRepairFailedStatusThrowsWithoutLeakingMessage(): void
    {
        $script = $this->tmpDir.'/controller-fail.php';
        file_put_contents($script, <<<'PHP'
<?php
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);
$line = fgets(STDIN);
$command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
fwrite(STDOUT, json_encode([
    'type' => 'session.repair.completed',
    'runId' => $command['runId'] ?? '',
    'seq' => 0,
    'payload' => [
        'commandId' => $command['id'],
        'commandType' => $command['type'],
        'status' => 'failed',
        'exception_class' => 'Symfony\\Component\\DependencyInjection\\Exception\\EnvNotFoundException',
    ],
]) . "\n");
fflush(STDOUT);
exit(0);
PHP);
        chmod($script, 0o755);

        $client = $this->createClient($script);

        try {
            $client->repair('session-repair-fail', true);
            $this->fail('Expected RuntimeTransportException');
        } catch (RuntimeTransportException $exception) {
            $this->assertStringContainsString('Controller repair failed', $exception->getMessage());
            $this->assertStringNotContainsString('Environment variable not found', $exception->getMessage());
        }
    }

    public function testRepairRoundTripsRefusalReason(): void
    {
        $script = $this->tmpDir.'/controller-refuse.php';
        file_put_contents($script, <<<'PHP'
<?php
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => ['version' => '1.0']]) . "\n");
fflush(STDOUT);
$line = fgets(STDIN);
$command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
fwrite(STDOUT, json_encode([
    'type' => 'session.repair.completed',
    'runId' => $command['runId'] ?? '',
    'seq' => 0,
    'payload' => [
        'commandId' => $command['id'],
        'commandType' => $command['type'],
        'status' => 'completed',
        'repairable_stale_cancellation_detected' => true,
        'stale_cancellation_repaired' => false,
        'message' => 'internal',
        'refusal_reason' => 'active_streaming',
        'active_operations_redriven' => 0,
    ],
]) . "\n");
fflush(STDOUT);
exit(0);
PHP);
        chmod($script, 0o755);

        $client = $this->createClient($script);
        $result = $client->repair('session-repair-refuse', true);

        $this->assertSame(SessionRepairRefusalReasonEnum::ActiveStreaming, $result->refusalReason);
        $this->assertTrue($result->repairableStaleCancellationDetected);
    }

    private function createClient(string $script): JsonlProcessAgentSessionClient
    {
        $runtimeConfig = new RuntimeProcessConfig(
            executableLocator: new class($script) implements AppExecutableLocator {
                public function __construct(private readonly string $script)
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
}
