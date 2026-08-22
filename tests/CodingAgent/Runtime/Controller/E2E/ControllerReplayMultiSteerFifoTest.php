<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Replay-backed proof: multiple active-run steers drain FIFO at the next safe boundary.
 *
 * Test thesis: two steers durably queued (agent_command_queued) during a tool window
 * must both apply exactly once in FIFO order at the next turn boundary, with no
 * supersession. Old latest-wins mailbox policy would apply only the second.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayMultiSteerFifoTest extends ControllerReplayE2eTestCase
{
    private const string STEER_ONE = 'MULTI_STEER_FIFO_ONE';
    private const string STEER_TWO = 'MULTI_STEER_FIFO_TWO';
    private const string BOUNDARY_SENTINEL = 'MULTI_STEER_FIFO_BOUNDARY_OK';

    public function testTwoActiveRunSteersDrainFifoAtTurnBoundary(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Run bash sleep 1 once. Do not call any other tool.',
            ],
        ]);

        $phase1 = $this->collectEventsUntil('tool_execution.started', 4.0);
        $p1 = $this->indexByType($phase1);
        $this->assertStartRunAcked($phase1, $startCmdId);
        $this->assertArrayHasKey('tool_execution.started', $p1, $this->collectDiagnostics($phase1));
        $this->assertSame(
            'bash',
            $p1['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            $this->collectDiagnostics($phase1),
        );

        $runStarted = $p1['run.started'][0] ?? null;
        $this->assertIsArray($runStarted);
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $steer1CmdId = 'cmd_steer1_'.uniqid();
        $steer2CmdId = 'cmd_steer2_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $steer1CmdId,
            'type' => 'steer',
            'runId' => $this->runId,
            'payload' => ['text' => self::STEER_ONE],
        ]);
        $this->writeCommand([
            'v' => 1,
            'id' => $steer2CmdId,
            'type' => 'steer',
            'runId' => $this->runId,
            'payload' => ['text' => self::STEER_TWO],
        ]);

        // ACK alone is not the cutoff — wait until both steers are durably queued.
        $queuedPhase = $this->collectUntilQueuedSteerCount(2, 6.0);
        $this->assertTrue(
            $this->foundAck($queuedPhase, $steer1CmdId),
            'Expected command.ack for first steer. '.$this->collectDiagnostics($queuedPhase),
        );
        $this->assertTrue(
            $this->foundAck($queuedPhase, $steer2CmdId),
            'Expected command.ack for second steer. '.$this->collectDiagnostics($queuedPhase),
        );

        $completePhase = $this->collectEventsUntil('run.completed', 8.0);
        $allEvents = array_merge($queuedPhase, $completePhase);
        $byType = $this->indexByType($allEvents);

        $this->assertArrayHasKey(
            'run.completed',
            $byType,
            'Run must complete after draining both steers. '.$this->collectDiagnostics($allEvents),
        );

        $eventsPath = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl';
        $this->assertFileExists($eventsPath, 'Canonical events.jsonl must exist.');
        $coreEvents = $this->loadCoreEvents($eventsPath);
        $this->assertNotEmpty($coreEvents, 'events.jsonl must have events');

        $queuedSteers = [];
        $appliedSteers = [];
        $superseded = [];
        foreach ($coreEvents as $event) {
            $type = (string) ($event['type'] ?? '');
            $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $kind = (string) ($payload['kind'] ?? '');
            if ('steer' !== $kind) {
                continue;
            }

            $key = (string) ($payload['idempotency_key'] ?? '');
            $text = $this->steerTextFromPayload($payload);

            if ('agent_command_queued' === $type) {
                $queuedSteers[] = ['key' => $key, 'text' => $text];
            }
            if ('agent_command_applied' === $type) {
                $appliedSteers[] = ['key' => $key, 'text' => $text];
            }
            if ('agent_command_superseded' === $type) {
                $superseded[] = $payload;
            }
        }

        $this->assertCount(2, $queuedSteers, 'Both steers must be durably queued before/at boundary.');
        $this->assertSame(
            [self::STEER_ONE, self::STEER_TWO],
            array_column($queuedSteers, 'text'),
            'Queued steers must preserve FIFO acceptance order.',
        );
        $this->assertCount(2, $appliedSteers, 'Both steers must apply exactly once.');
        $this->assertSame(
            array_column($queuedSteers, 'key'),
            array_column($appliedSteers, 'key'),
            'Applied steer idempotency keys must match queued FIFO order.',
        );
        $this->assertSame(
            [self::STEER_ONE, self::STEER_TWO],
            array_column($appliedSteers, 'text'),
            'Applied steers must preserve FIFO text order.',
        );
        $this->assertSame([], $superseded, 'No steer may be superseded under FIFO batch drain.');

        $this->assertTrue(
            $this->hasAssistantResponseEvidence($byType),
            'Boundary continuation after dual-steer drain must produce assistant output. '
            .$this->collectDiagnostics($allEvents),
        );

        $textEvents = $byType['assistant.text_delta'] ?? $byType['assistant.message_completed'] ?? [];
        $joined = '';
        foreach ($textEvents as $ev) {
            $payload = $ev['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            $joined .= (string) ($payload['text'] ?? $payload['content'] ?? '');
        }
        $this->assertStringContainsString(
            self::BOUNDARY_SENTINEL,
            '' !== $joined ? $joined : json_encode($byType, \JSON_UNESCAPED_UNICODE),
            'Synthetic boundary fixture sentinel must appear after both steers applied.',
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-multi-steer-fifo';
    }

    /**
     * @return list<string>
     */
    protected function controllerExtraArgs(): array
    {
        return [];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'

tools:
    bash:
        background_prompt_threshold_seconds: 60
YAML;
    }

    protected function createIsolatedProjectDir(): void
    {
        parent::createIsolatedProjectDir();

        $path = $this->tempDir.'/.hatfield/settings.yaml';
        $settings = \Symfony\Component\Yaml\Yaml::parseFile($path);
        \PHPUnit\Framework\Assert::assertIsArray($settings);
        $settings['extensions']['settings']['safe_guard']['allow_command_patterns'] = ['^sleep\\b'];
        file_put_contents($path, \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $bashFixturePath = __DIR__.'/fixtures/controller-bash-blocker.json';
        $bashFixture = json_decode(
            (string) file_get_contents($bashFixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($bashFixture);

        // Second LLM call sees both steers; emit a stable sentinel (not prose-sensitive).
        $boundaryFixture = [
            '$schema' => 'Synthetic controller replay — dual steer FIFO boundary continuation',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => '2026-07-30T00:00:00+00:00',
            'recording_source' => 'manual',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => self::STEER_ONE],
                    ['role' => 'user', 'content' => self::STEER_TWO],
                ],
            ],
            'usage' => ['input_tokens' => 8, 'output_tokens' => 10, 'total_tokens' => 18],
            'stop_reason' => 'stop',
            'expected_text' => self::BOUNDARY_SENTINEL,
            'deltas' => [
                ['type' => 'text', 'content' => self::BOUNDARY_SENTINEL],
            ],
        ];

        return [$bashFixture, $boundaryFixture];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectUntilQueuedSteerCount(int $expectedCount, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $collected = [];
        $queued = 0;

        while (microtime(true) < $deadline) {
            $batch = $this->readEvents();
            foreach ($batch as $event) {
                $collected[] = $event;
                if ('user.message_queued' === (string) ($event['type'] ?? '')) {
                    ++$queued;
                }
            }

            if ($queued >= $expectedCount) {
                return $collected;
            }

            usleep(50_000);
        }

        $this->fail(
            \sprintf(
                'Timed out waiting for %d queued steers (saw %d). %s',
                $expectedCount,
                $queued,
                $this->collectDiagnostics($collected),
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCoreEvents(string $eventsPath): array
    {
        $lines = file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        $events = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function steerTextFromPayload(array $payload): string
    {
        if (\is_string($payload['text'] ?? null) && '' !== $payload['text']) {
            return (string) $payload['text'];
        }

        $message = $payload['message'] ?? null;
        if (!\is_array($message)) {
            return '';
        }
        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode('', $parts);
    }
}
