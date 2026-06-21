<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live LLM compaction smoke test.
 *
 * Thesis: the compaction LLM request path (ExecuteCompactionStepWorker →
 * PlatformInterface → llama_cpp_test/test) produces a valid completions
 * response that flows through the full async pipeline — controller
 * subprocess, messenger consumer, LLM worker, result routing,
 * RuntimeEventTranslator — and results in compaction.completed (not
 * compaction.failed) with structural proof in persisted session artifacts
 * that messages were actually replaced.
 *
 * This test would have caught the Runpod HTTP/2 400 regression because
 * a malformed request that causes model_error produces compaction.failed
 * instead of compaction.completed, and the context_compacted event is
 * absent from events.jsonl.
 *
 * Token before/after comparison is deliberately NOT asserted — with
 * immutable prologue retention the summary can be larger than a tiny
 * session's compacted body prefix.  Structural proof is more robust.
 */
#[Group('llm-real')]
final class CompactionLiveSmokeTest extends ControllerE2eTestCase
{
    protected function tempDirPrefix(): string
    {
        return 'test-compaction';
    }

    /**
     * The test model does not support tool calling — compaction must
     * not rely on tools (toolsEnabled: false handles this, but we
     * also exclude bash to keep the run simple).
     */
    protected function modelConfig(): array
    {
        return [
            'input' => ['text'],
            'tool_calling' => false,
        ];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<YAML
compaction:
    keep_recent_tokens: 10
    auto_enabled: false
YAML;
    }

    public function testCompactionLiveSmoke(): void
    {
        $this->spawnController();

        $this->waitForEvent('runtime.ready', 5.0);

        // ── Phase 1: Start a run with enough text to be compactable ──
        //
        // keep_recent_tokens=10 → about 32 characters of message
        // content trigger compaction.  A multi-sentence prompt ensures
        // the token estimate comfortably exceeds the threshold.
        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Write a short paragraph about the benefits of automated testing in software development.',
            ],
        ]);

        // Collect events until the run reaches a terminal state.
        $runEvents = $this->collectEvents(30.0);
        $byType = $this->indexByType($runEvents);

        $this->assertStartRunAcked($runEvents, $startCmdId);

        self::assertArrayHasKey('run.started', $byType, 'Expected run.started — did the start_run command reach the agent?');

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId, 'run.started must have a runId');

        $hasAssistant = isset($byType['assistant.text_started'])
            || isset($byType['assistant.message_completed']);
        self::assertTrue($hasAssistant, 'Expected assistant response. Got types: '.implode(', ', array_keys($byType)));

        if (!isset($byType['run.completed']) && !isset($byType['run.failed'])) {
            // The run might still be running if the model is slow.
            // Collect more events.
            $moreEvents = $this->collectEvents(30.0);
            $runEvents = array_merge($runEvents, $moreEvents);
            $byType = $this->indexByType($runEvents);
        }

        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Expected run.completed or run.failed — run did not reach terminal state.'

."\n".$this->collectDiagnostics($runEvents),
        );
        self::assertArrayHasKey('run.completed', $byType, 'Run must complete successfully before compaction can be tested.');


        // ── Phase 2: Send compact command ──

        $compactCmdId = 'cmd_compact_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $compactCmdId,
            'type' => 'compact',
            'runId' => $this->runId,
            'payload' => [],
        ]);

        // Collect compaction events.  The async ExecuteCompactionStep
        // worker picks up the message, calls the test LLM, and dispatches
        // CompactionStepResult back through the pipeline.
        //
        // We wait for compaction.completed (success) or compaction.failed
        // (error).  A 30s timeout accommodates model invocation + transport
        // round-trip.
        $compactEvents = $this->collectEventsUntilTarget(
            targets: ['compaction.completed', 'compaction.failed'],
            timeout: 30.0,
        );
        $compactByType = $this->indexByType($compactEvents);

        // ── Phase 3: Assert compaction succeeded ──

        self::assertArrayHasKey(
            'compaction.started',
            $compactByType,
            'Expected compaction.started event — was the compact command dispatched?'
            ."\n".$this->collectDiagnostics($compactEvents),
        );

        if (isset($compactByType['compaction.failed'])) {
            $failedEvent = $compactByType['compaction.failed'][0];
            $errorMsg = $failedEvent['payload']['error'] ?? ($failedEvent['payload']['reason'] ?? 'unknown');
            self::fail(
                'Compaction failed instead of succeeding: '.$errorMsg."\n"
                .$this->collectDiagnostics($compactEvents),
            );
        }

        self::assertArrayHasKey(
            'compaction.completed',
            $compactByType,
            'Expected compaction.completed event — the async LLM call must succeed.'
            ."\n".$this->collectDiagnostics($compactEvents),
        );

        $completedEvent = $compactByType['compaction.completed'][0];
        $payload = $completedEvent['payload'] ?? [];

        // The completed event carries token estimates from the compactor.
        $estimatedBefore = $payload['estimated_tokens_before'] ?? null;
        $estimatedAfter = $payload['estimated_tokens_after'] ?? null;

        self::assertNotNull($estimatedBefore, 'compaction.completed must report estimated_tokens_before');
        self::assertNotNull($estimatedAfter, 'compaction.completed must report estimated_tokens_after');
        self::assertGreaterThan(0, $estimatedBefore, 'estimated_tokens_before must be positive');
        self::assertGreaterThan(0, $estimatedAfter, 'estimated_tokens_after must be positive');

        // ── Phase 4: Structural proof from persisted session artifacts ──
        //
        // The runtime compaction.completed event proves the external
        // contract (no model_error).  Session artifacts prove the
        // internal pipeline: context_compacted was emitted, the
        // compacted message list was persisted, and a compact_summary
        // marker exists in the replaced messages.

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->sessionId;
        $this->assertSessionArtifactsExist($sessionDir, $compactEvents);

        $eventsPath = $sessionDir.'/events.jsonl';
        self::assertFileExists($eventsPath, 'Session events.jsonl must exist');

        $coreEvents = [];
        foreach (\file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
            $evt = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($evt)) {
                $coreEvents[] = $evt;
            }
        }

        $compactedCoreEvents = \array_filter(
            $coreEvents,
            static fn (array $e): bool => ($e['type'] ?? '') === 'context_compacted',
        );

        self::assertNotEmpty(
            $compactedCoreEvents,
            'events.jsonl must contain a context_compacted event — the compaction pipeline must produce a core event'
            ."\n".$this->collectDiagnostics($compactEvents),
        );

        $contextCompacted = \reset($compactedCoreEvents);
        $cp = $contextCompacted['payload'] ?? [];

        // Messages were actually replaced.
        self::assertGreaterThan(
            0,
            $cp['messages_compacted'] ?? 0,
            'context_compacted must report messages_compacted > 0 — messages must be replaced',
        );
        self::assertNotEmpty(
            $cp['summary_text'] ?? '',
            'context_compacted must carry non-empty summary_text from the model',
        );

        // The compacted messages array carries the compact_summary marker.
        $compactedMessages = $cp['messages'] ?? [];
        self::assertNotEmpty(
            $compactedMessages,
            'context_compacted must carry the compacted messages array',
        );

        $foundSummaryMarker = false;
        foreach ($compactedMessages as $msg) {
            if (!\is_array($msg)) {
                continue;
            }
            $metadata = $msg['metadata'] ?? [];
            if (true === ($metadata['compact_summary'] ?? null)) {
                $foundSummaryMarker = true;
                break;
            }
        }

        self::assertTrue(
            $foundSummaryMarker,
            'Compacted messages must contain a compact_summary marker — the summary was injected',
        );
    }

    // ── Custom collection helper ─────────────────────────────────────

    /**
     * Collect events until any of the target types appears, or until a
     * terminal run state appears.
     *
     * @param list<string> $targets
     *
     * @return list<array<string, mixed>>
     */
    private function collectEventsUntilTarget(array $targets, float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;

                $type = $event['type'] ?? '';

                if (\in_array($type, $targets, true)) {
                    return $events;
                }

                // Terminal run states also stop collection.
                if (\in_array($type, ['run.completed', 'run.failed', 'run.cancelled'], true)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $evt) {
                    $events[] = $evt;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }
}
