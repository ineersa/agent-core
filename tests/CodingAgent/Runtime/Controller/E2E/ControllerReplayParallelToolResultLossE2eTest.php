<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Reproduces session 6 parallel read tool batch loss under real controller +
 * Messenger + SQLite topology (replay-backed LLM only).
 *
 * Thesis: two fast parallel read tools must both commit results, clear
 * pendingToolCalls, and reach run.completed without stale_result_ignored.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayParallelToolResultLossE2eTest extends ControllerReplayE2eTestCase
{
    private const string PROMPT_SENTINEL = '[replay:parallel-two-read]';

    protected function setUp(): void
    {
        parent::setUp();
        file_put_contents($this->tempDir.'/alpha.txt', "alpha\n");
        file_put_contents($this->tempDir.'/beta.txt', "beta\n");
    }

    public function testParallelReadToolsBothCommitWithoutStaleIgnored(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => self::PROMPT_SENTINEL.' Call read twice in parallel on ./alpha.txt and ./beta.txt. Do not call any other tool.',
            ],
        ]);

        $events = $this->collectUntilParallelBatchSettles(12.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->sessionId;
        $eventsPath = $sessionDir.'/events.jsonl';
        $statePath = $sessionDir.'/state.json';
        $this->assertFileExists($eventsPath, $this->collectDiagnostics($events));
        $this->assertFileExists($statePath, $this->collectDiagnostics($events));

        $canonical = $this->parseCanonicalEvents($eventsPath);
        $starts = $this->filterCanonicalByType($canonical, 'tool_execution_start');
        $ends = $this->filterCanonicalByType($canonical, 'tool_execution_end');
        $stale = $this->filterCanonicalByType($canonical, 'stale_result_ignored');

        $this->assertCount(2, $starts, 'Expected two tool_execution_start events. '.$this->dumpCanonicalTail($canonical));
        $this->assertGreaterThanOrEqual(2, \count($ends), 'Expected at least two tool_execution_end events before batch settles. '.$this->dumpCanonicalTail($canonical));
        $this->assertLessThanOrEqual(2, \count($ends), 'Unexpected extra tool_execution_end events. '.$this->dumpCanonicalTail($canonical));
        $this->assertSame([], $stale, 'Must not emit stale_result_ignored for parallel read batch. '.$this->dumpCanonicalTail($canonical));

        $startIds = array_map(static fn (array $e): string => (string) ($e['payload']['tool_call_id'] ?? ''), $starts);
        $endIds = array_map(static fn (array $e): string => (string) ($e['payload']['tool_call_id'] ?? ''), $ends);
        $this->assertCount(2, array_unique($startIds));
        $this->assertEqualsCanonicalizing($startIds, $endIds);

        $state = json_decode((string) file_get_contents($statePath), true, 512, \JSON_THROW_ON_ERROR);
        \PHPUnit\Framework\Assert::assertIsArray($state);
        $pending = $state['pendingToolCalls'] ?? [];
        \PHPUnit\Framework\Assert::assertIsArray($pending);
        $unresolved = array_filter($pending, static fn (bool $resolved): bool => false === $resolved);
        $this->assertSame([], $unresolved, 'pendingToolCalls must have no unresolved false entries after parallel batch settles. State: '.json_encode($state, \JSON_UNESCAPED_UNICODE));

        if ([] !== $stale) {
            $this->fail('Reproduced session-6 stale_result_ignored on parallel read batch. '.$this->dumpCanonicalTail($canonical));
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-parallel-tool-loss';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $dir = __DIR__.'/fixtures';
        $parallel = json_decode((string) file_get_contents($dir.'/controller-parallel-two-read-replay.json'), true, 512, \JSON_THROW_ON_ERROR);
        $done = json_decode((string) file_get_contents($dir.'/controller-parallel-two-read-done-replay.json'), true, 512, \JSON_THROW_ON_ERROR);
        \PHPUnit\Framework\Assert::assertIsArray($parallel);
        \PHPUnit\Framework\Assert::assertIsArray($done);

        unset($done['replay_match']);

        return [$parallel, $done];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectUntilParallelBatchSettles(float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);
            }

            $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->sessionId;
            $eventsPath = $sessionDir.'/events.jsonl';
            if (is_file($eventsPath)) {
                $canonical = $this->parseCanonicalEvents($eventsPath);
                $starts = $this->filterCanonicalByType($canonical, 'tool_execution_start');
                $ends = $this->filterCanonicalByType($canonical, 'tool_execution_end');
                $stale = $this->filterCanonicalByType($canonical, 'stale_result_ignored');
                if (\count($starts) >= 2 && (\count($ends) >= 2 || [] !== $stale)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCanonicalEvents(string $path): array
    {
        $events = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function filterCanonicalByType(array $events, string $type): array
    {
        return array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['type'] ?? null) === $type,
        ));
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function dumpCanonicalTail(array $events): string
    {
        $tail = \array_slice($events, -12);
        $lines = [];
        foreach ($tail as $event) {
            $lines[] = \sprintf(
                'seq=%s type=%s tool=%s reason=%s',
                $event['seq'] ?? '?',
                $event['type'] ?? '?',
                $event['payload']['tool_call_id'] ?? '-',
                $event['payload']['reason'] ?? '-',
            );
        }

        return "\nCanonical tail:\n".implode("\n", $lines);
    }
}
