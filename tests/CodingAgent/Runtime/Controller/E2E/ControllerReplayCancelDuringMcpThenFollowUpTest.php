<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller-subprocess proof for cancel during an active MCP
 * tool call, then follow-up without restart.
 *
 * The STDIO fixture writes MCP_FIXTURE_MARKER=received when the slow tool
 * request arrives. Cancel is issued only after that marker and
 * tool_execution.started, so this is a barrier rather than a timing window.
 *
 * This proves the controller/Messenger cancel path through a real MCP tool
 * invocation. It does not prove Escape key routing in the TUI layer.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayCancelDuringMcpThenFollowUpTest extends ControllerReplayE2eTestCase
{
    private const string TOOL_CALL_ID = 'call_mcp_slow_1';
    private const string TOOL_NAME = 'cancel_fixture_slow';
    private const string FOLLOW_UP_MARKER = '[controller-replay:mcp-cancel-follow-up] ack';

    private string $markerPath = '';

    public function testCancelDuringActiveMcpThenFollowUpWithoutRestart(): void
    {
        $this->installCancellationFixture();
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call cancel_fixture_slow once. Do not call any other tool.',
            ],
        ]);

        $preCancel = $this->collectEventsUntil(
            null,
            12.0,
            static function (array $event): bool {
                if (($event['type'] ?? '') !== 'tool_execution.started') {
                    return false;
                }

                return ($event['payload']['tool_call_id'] ?? null) === self::TOOL_CALL_ID;
            },
        );
        $preByType = $this->indexByType($preCancel);

        $this->assertStartRunAcked($preCancel, $startCmdId);
        $this->assertArrayHasKey('run.started', $preByType, $this->collectDiagnostics($preCancel));
        $this->runId = (string) ($preByType['run.started'][0]['runId']
            ?? $preByType['run.started'][0]['payload']['runId']
            ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must include runId');

        // The worker can publish tool_execution.started before the server reads
        // the request. Wait for both sides of the barrier independently.
        $deadline = microtime(true) + 3.0;
        while (!$this->markerSaysReceived() && microtime(true) < $deadline) {
            $this->assertRunning('waiting for the MCP server to receive the request');
            usleep(10_000);
        }
        $this->assertTrue(
            $this->markerSaysReceived(),
            'STDIO fixture must receive the slow MCP request before cancel. marker='
            .(is_file($this->markerPath) ? (string) file_get_contents($this->markerPath) : 'missing'),
        );
        $this->assertArrayHasKey(
            'tool_execution.started',
            $preByType,
            'MCP tool must start before cancel. '.$this->collectDiagnostics($preCancel),
        );
        $this->assertSame(self::TOOL_NAME, $preByType['tool_execution.started'][0]['payload']['tool_name'] ?? null);
        $this->assertSame(self::TOOL_CALL_ID, $preByType['tool_execution.started'][0]['payload']['tool_call_id'] ?? null);

        $cancelCmdId = 'cmd_cancel_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $cancelCmdId,
            'type' => 'cancel',
            'runId' => $this->runId,
            'payload' => [],
        ]);

        $cancelEvents = $this->collectEventsUntil('run.cancelled', 8.0);
        $allCancel = array_merge($preCancel, $cancelEvents);
        $cancelByType = $this->indexByType($allCancel);

        $this->assertTrue(
            $this->foundAck($cancelEvents, $cancelCmdId),
            'cancel must be acked. '.$this->collectDiagnostics($cancelEvents),
        );
        $this->assertArrayHasKey(
            'run.cancelled',
            $cancelByType,
            'Parent must terminalize to run.cancelled. '.$this->collectDiagnostics($allCancel),
        );
        $this->assertArrayNotHasKey('run.failed', $cancelByType, $this->collectDiagnostics($allCancel));
        $this->assertMatchesRegularExpression(
            '/^(received|cancelled:\\d+)$/',
            (string) file_get_contents($this->markerPath),
        );

        $followUpCmdId = 'cmd_fu_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => self::FOLLOW_UP_MARKER,
            ],
        ]);

        $followUpEvents = $this->collectEventsUntil('run.completed', 8.0);
        $followUpByType = $this->indexByType($followUpEvents);

        $this->assertTrue(
            $this->foundAck($followUpEvents, $followUpCmdId),
            'Expected command.ack for follow_up. '.$this->collectDiagnostics($followUpEvents),
        );
        $this->assertArrayNotHasKey(
            'command.rejected',
            $followUpByType,
            'follow_up after cancel must not be rejected. '.$this->collectDiagnostics($followUpEvents),
        );
        $this->assertArrayNotHasKey('run.failed', $followUpByType, $this->collectDiagnostics($followUpEvents));
        $this->assertArrayHasKey(
            'run.completed',
            $followUpByType,
            'Follow-up after cancel must complete without restart. '.$this->collectDiagnostics($followUpEvents),
        );
        $this->assertMatchesRegularExpression(
            '/^cancelled:\d+$/',
            (string) file_get_contents($this->markerPath),
            'The MCP server must receive a cancellation notification, not merely observe local run cancellation.',
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertDirectoryExists($sessionDir);
        $eventsJsonl = $sessionDir.'/events.jsonl';
        $this->assertFileExists($eventsJsonl);
        $jsonl = (string) file_get_contents($eventsJsonl);
        $this->assertStringContainsString('agent_end', $jsonl);
        $this->assertStringContainsString('"reason":"cancelled"', $jsonl);
        $this->assertStringContainsString('agent_command_applied', $jsonl);
        $this->assertStringContainsString('"kind":"follow_up"', $jsonl);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-mcp-cancel-followup';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $fixturePath = __DIR__.'/fixtures/controller-mcp-slow-blocker.json';
        $mcpFixture = json_decode(
            (string) file_get_contents($fixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($mcpFixture);

        $followUpFixture = [
            '$schema' => 'Synthetic controller replay — follow_up after MCP cancel',
            'fixture_source' => 'synthetic',
            'synthetic_reason' => 'Absorb the post-cancel follow_up LLM turn.',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'stop_reason' => 'stop',
            'deltas' => [
                ['type' => 'text', 'content' => 'follow-up after mcp cancel ok'],
            ],
        ];

        return [$mcpFixture, $followUpFixture];
    }

    private function installCancellationFixture(): void
    {
        $this->markerPath = $this->tempDir.'/mcp-cancel-marker';
        file_put_contents($this->markerPath, '');
        file_put_contents($this->tempDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'cancel_fixture' => [
                    'command' => \PHP_BINARY,
                    'args' => [\dirname(__DIR__, 3).'/Mcp/Fixtures/stdio-cancellation-server.php'],
                    'env' => ['MCP_FIXTURE_MARKER' => $this->markerPath],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 5000,
                    'availability' => 'all',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));
    }

    private function markerSaysReceived(): bool
    {
        if (!is_file($this->markerPath)) {
            return false;
        }

        $value = (string) file_get_contents($this->markerPath);

        return 'received' === $value || 1 === preg_match('/^cancelled:\\d+$/', $value);
    }
}
