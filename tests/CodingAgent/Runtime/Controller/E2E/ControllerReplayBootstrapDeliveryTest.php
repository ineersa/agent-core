<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Process\SourceTreeExecutableLocator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/** Real owner/controller pipes must continue processing results while a large view is unread. */
#[Group('controller-replay')]
final class ControllerReplayBootstrapDeliveryTest extends ControllerReplayE2eTestCase
{
    public function testSlowScreenDoesNotHoldOwnerAndExactAcknowledgementCatchesUpSuffix(): void
    {
        $seed = new Process([\PHP_BINARY, __DIR__.'/Support/BootstrapSessionSeed.php', $this->projectDir], $this->tempDir,
            ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir,
                'HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'.sqlite',
                'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => 'messenger_transport_test-replay-'.$this->sessionId.'.sqlite']);
        $seed->setTimeout(5);
        $seed->mustRun();
        $seeded = json_decode(trim($seed->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
        $this->runId = $seeded['run_id'];
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5);
        $this->assertUntaggedOwnedTree();
        $this->writeCommand(['v' => 1, 'id' => 'bootstrap-resume', 'type' => 'resume', 'runId' => $this->runId]);
        $prefix = $this->collectAvailabilityBatch();
        $available = $this->indexByType($prefix)['bootstrap.available'][0];
        $cut = $available['payload'];
        $this->assertSame('bootstrap-resume', $cut['command_id']);
        $this->assertGreaterThan(100, $cut['canonical_seq']);
        $this->assertGreaterThan(1048576, $cut['bytes']);

        // Stop reading stdout. More than Linux's maximum pipe capacity remains
        // unread. Shell completion is a durable owner barrier, not an elapsed sleep.
        $marker = $this->tempDir.'/shell-completed';
        $this->writeCommand(['v' => 1, 'id' => 'bootstrap-shell', 'type' => 'shell_command', 'runId' => $this->runId,
            'payload' => ['text' => '!printf done > '.escapeshellarg($marker)]]);
        $end = microtime(true) + 5;
        $completed = false;
        do {
            $stream = fopen($seeded['path'], 'rb');
            fseek($stream, $cut['end_offset']);
            while (false !== ($line = fgets($stream))) {
                $event = json_decode($line, true);
                if (\is_array($event) && 'tool_execution_end' === ($event['type'] ?? null)) {
                    $completed = true;
                }
            }
            fclose($stream);
            if ($completed && is_file($marker) && !is_file($seeded['path'].'.append.pending.json')) {
                break;
            }
            $this->assertTrue($this->isRunning(), $this->collectDiagnostics([]));
            usleep(10000);
        } while (microtime(true) < $end);
        $this->assertTrue($completed, 'Owner must consume the shell result while controller stdout is stalled.');
        $this->assertFileExists($marker);

        $events = array_merge($prefix, $this->collectEventsUntil('bootstrap.end', 5));
        $byType = $this->indexByType($events);
        $this->assertArrayHasKey('bootstrap.end', $byType, $this->collectDiagnostics($events));
        $body = '';
        foreach ($byType['bootstrap.frame'] ?? [] as $index => $frame) {
            $this->assertSame($index, $frame['payload']['index']);
            $this->assertSame($cut['bootstrap_id'], $frame['payload']['bootstrap_id']);
            $this->assertSame($cut['view_epoch'], $frame['payload']['view_epoch']);
            $this->assertLessThanOrEqual(65536, \strlen(json_encode($frame, \JSON_THROW_ON_ERROR)."\n"));
            $chunk = base64_decode($frame['payload']['data'], true);
            $this->assertNotFalse($chunk);
            $this->assertLessThanOrEqual(32768, \strlen($chunk));
            $body .= $chunk;
        }
        $this->assertSame($cut['bytes'], \strlen($body));
        $this->assertSame($cut['checksum'], hash('sha256', $body));
        $this->assertArrayNotHasKey('session.ready', $byType);
        $spool = \dirname($seeded['path']).'/runtime/bootstrap/'.$cut['bootstrap_id'].'.jsonl';
        $this->assertFileExists($spool);
        $wrong = $cut;
        ++$wrong['view_epoch'];
        $this->writeCommand(['v' => 1, 'id' => 'stale-bootstrap', 'type' => 'bootstrap.applied', 'runId' => $this->runId, 'payload' => $wrong]);
        $this->waitForEvent('command.rejected', 3);
        $this->assertFileExists($spool);
        // Borrow the real controller pipes after the test's incremental mount.
        // The harness remains their only lifecycle owner; no second process starts.
        $client = new JsonlProcessAgentSessionClient(new RuntimeProcessConfig(new SourceTreeExecutableLocator($this->projectDir), $this->tempDir),
            new PromptTemplatesRuntimeConfig(), new ToolFilterRuntimeConfig(), new TestLogger());
        $reflection = new \ReflectionClass($client);
        foreach (['process' => $this->process, 'pipes' => $this->pipes, 'activeRunId' => $this->runId,
            'sessionId' => $this->runId, 'processSessionId' => $this->runId, 'runtimeReadyReceived' => true,
            'bootstrapDescriptor' => SessionBootstrapDescriptorDTO::fromArray($cut), 'bootstrapEnded' => true,
            'suffixCursor' => $cut['canonical_seq']] as $name => $value) {
            $reflection->getProperty($name)->setValue($client, $value);
        }
        try {
            $client->acknowledgeBootstrap($cut);
            $suffixTypes = [];
            $deadline = microtime(true) + 5;
            do {
                foreach ($client->events($this->runId) as $event) {
                    $suffixTypes[$event->type][] = $event;
                }
                if (isset($suffixTypes['session.ready'])) {
                    break;
                }
                $this->assertTrue($this->isRunning(), $this->collectDiagnostics([]));
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertArrayHasKey('session.ready', $suffixTypes, $this->collectDiagnostics([]));
            $this->assertFileDoesNotExist($spool);
            $ready = $suffixTypes['session.ready'][0];
            $this->assertGreaterThan($cut['canonical_seq'], $ready->payload['canonical_seq']);
            $this->assertSame($cut['bootstrap_id'], $ready->payload['bootstrap_id']);
            $this->assertSame($cut['view_epoch'], $ready->payload['view_epoch']);
            $this->assertArrayHasKey(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $suffixTypes);
            $this->assertArrayNotHasKey('bootstrap.suffix', $suffixTypes, 'The process client yields decoded canonical records, not transport chunks.');
        } finally {
            $reflection->getProperty('process')->setValue($client, null);
            $reflection->getProperty('pipes')->setValue($client, []);
        }
        $this->assertStringNotContainsString('llm_step_started', file_get_contents($seeded['path']));
    }

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_REPLAY_SESSION_ID' => $this->runId];
    }

    protected function replayFixtures(): array
    {
        return [];
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-bootstrap';
    }

    /** Keep frames sharing the availability read; waitForEvent intentionally discards that tail.
     * @return list<array<string, mixed>> */
    private function collectAvailabilityBatch(): array
    {
        $events = [];
        $deadline = microtime(true) + 5;
        do {
            $events = array_merge($events, $this->readEvents());
            if (isset($this->indexByType($events)['bootstrap.available'])) {
                return $events;
            }
            $this->assertTrue($this->isRunning(), $this->collectDiagnostics($events));
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Bootstrap availability was not received. '.$this->collectDiagnostics($events));
    }

    private function assertUntaggedOwnedTree(): void
    {
        $this->refreshTrackedControllerPids();
        foreach ($this->trackedControllerPids as $pid) {
            $env = @file_get_contents('/proc/'.$pid.'/environ');
            if (false !== $env) {
                $this->assertStringNotContainsString('HATFIELD_SESSION_ID=', $env);
            }
        }
    }
}
