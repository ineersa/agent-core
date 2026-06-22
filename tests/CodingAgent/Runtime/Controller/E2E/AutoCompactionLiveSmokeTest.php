<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live LLM auto-compaction smoke test — reproduces the post-compaction
 * continuation bug discovered during COMP-06 live smoke.
 *
 * Thesis:
 *   After the first user prompt completes and after-turn auto compaction
 *   finishes (context_compacted via the after-turn hook), the run MUST
 *   stay terminal / idle and MUST NOT dispatch another LLM step before
 *   a new user follow_up command.  Auto compaction is maintenance work,
 *   not a request to continue the conversation.
 *
 * Current bug (HEAD da028146d):
 *   context_compacted trigger=auto is immediately followed by
 *   turn_advanced → leaf_set reason=continue → llm_step_failed
 *   error.type=empty_response.  This test asserts the absence of any
 *   post-compaction turn_advanced / llm_step_failed / llm_step_completed
 *   in the core event log.
 *
 * The test reads the canonical events.jsonl (not just the runtime event
 * stream, which stops at run.completed) to prove the structural
 * invariant.
 */
#[Group('llm-real')]
final class AutoCompactionLiveSmokeTest extends ControllerE2eTestCase
{
    protected function tempDirPrefix(): string
    {
        return 'test-auto-compact';
    }

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
    auto_enabled: true
    compact_after_tokens: 10
    keep_recent_tokens: 10
YAML;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Single test method
    // ─────────────────────────────────────────────────────────────────

    public function testAutoCompactionDoesNotCausePostCompactionLlmStep(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        // ── Phase 1: start_run, collect events past run.completed ──

        $startCmdId = 'cmd_start_' . uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Write a short paragraph about the benefits of automated testing in software development.',
            ],
        ]);

        $allEvents = $this->collectEventsPastRunCompleted(25.0);
        $byType = $this->indexByType($allEvents);

        $this->assertStartRunAcked($allEvents, $startCmdId);

        // ── Basic runtime event sanity ──

        self::assertArrayHasKey('run.started', $byType, 'Expected run.started');
        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId, 'run.started must have a runId');

        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Expected run.completed or run.failed — run did not reach terminal state.'
            . "\n" . $this->collectDiagnostics($allEvents),
        );

        if (isset($byType['run.failed'])) {
            $err = $byType['run.failed'][0]['payload']['error'] ?? '?';
            self::fail(
                'Run failed before auto-compaction could fire: ' . $err . "\n"
                . $this->collectDiagnostics($allEvents),
            );
        }

        // ── Phase 2: structural proof from events.jsonl ──

        $sessionDir = $this->tempDir . '/.hatfield/sessions/' . $this->sessionId;
        $eventsPath = $sessionDir . '/events.jsonl';

        self::assertFileExists($eventsPath, 'Session events.jsonl must exist');

        $coreEvents = $this->loadCoreEvents($eventsPath);
        self::assertNotEmpty($coreEvents, 'events.jsonl must have events');

        // Build a compact timeline for diagnostics.
        $timeline = $this->buildTimeline($coreEvents);

        // Find the last auto compaction lifecycle event.
        $lastAutoCompactionSeq = null;
        foreach ($coreEvents as $evt) {
            $type = $evt['type'] ?? '';
            $payload = $evt['payload'] ?? [];
            $trigger = $payload['trigger'] ?? '';
            if (\in_array($type, ['context_compaction_started', 'context_compacted', 'context_compaction_failed'], true)
                && 'auto' === $trigger
            ) {
                $lastAutoCompactionSeq = (int) ($evt['seq'] ?? 0);
            }
        }

        self::assertNotNull(
            $lastAutoCompactionSeq,
            "events.jsonl must contain at least one auto compaction lifecycle event.\n"
            . "Timeline:\n" . $timeline,
        );

        // ── THE RED ASSERTION ──
        //
        // After the auto compaction lifecycle completes, there must be NO
        // turn_advanced, llm_step_completed, or llm_step_failed events.
        // These indicate the system treated auto-compaction as a
        // continuation trigger rather than maintenance work.

        $postCompactionForbidden = [];
        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            if ($seq <= $lastAutoCompactionSeq) {
                continue;
            }
            $type = $evt['type'] ?? '';
            if (\in_array($type, ['turn_advanced', 'llm_step_completed', 'llm_step_failed', 'leaf_set'], true)) {
                $err = $evt['payload']['error'] ?? [];
                $postCompactionForbidden[] = sprintf(
                    '  seq=%d type=%s trigger=%s error_type=%s message=%s',
                    $seq,
                    $type,
                    $evt['payload']['trigger'] ?? '',
                    $err['type'] ?? '',
                    $err['message'] ?? '',
                );
            }
        }

        self::assertEmpty(
            $postCompactionForbidden,
            "Auto compaction must not cause extra LLM turns.\n"
            . "Found post-compaction forbidden events (after auto lifecycle seq {$lastAutoCompactionSeq}):\n"
            . implode("\n", $postCompactionForbidden) . "\n"
            . "\nFull timeline:\n" . $timeline . "\n"
            . "\nRuntime events:\n" . $this->collectDiagnostics($allEvents),
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Local helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Collect runtime events, continuing past run.completed until a
     * compaction lifecycle terminal appears or the timeout expires.
     *
     * This is deliberately NOT a stop-at-run.completed collector because
     * auto compaction fires in the after-turn hook and its events arrive
     * AFTER run.completed.
     *
     * @return list<array<string, mixed>>
     */
    private function collectEventsPastRunCompleted(float $timeoutSeconds): array
    {
        $events = [];
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $type = $event['type'] ?? '';

                // Resolve once compaction lifecycle terminal is seen.
                if (\in_array($type, ['compaction.completed', 'compaction.failed'], true)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                // Process may have exited but messenger may still deliver.
                usleep(250_000);
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(50_000);
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCoreEvents(string $eventsPath): array
    {
        $core = [];
        foreach (\file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
            $evt = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($evt)) {
                $core[] = $evt;
            }
        }
        return $core;
    }

    /**
     * Build a compact timeline from core events for diagnostics.
     *
     * @param list<array<string, mixed>> $coreEvents
     */
    private function buildTimeline(array $coreEvents): string
    {
        $lines = [];
        foreach ($coreEvents as $evt) {
            $type = $evt['type'] ?? '?';
            $seq = $evt['seq'] ?? '?';
            $trigger = $evt['payload']['trigger'] ?? '';
            $error = $evt['payload']['error'] ?? [];
            $usage = $evt['payload']['usage'] ?? [];
            $before = $evt['payload']['estimated_tokens_before'] ?? '';
            $after = $evt['payload']['estimated_tokens_after'] ?? '';
            $messagesCompacted = $evt['payload']['messages_compacted'] ?? '';

            $extra = '';
            if ('auto' === $trigger) {
                $extra .= ' trigger=auto';
            }
            if ([] !== $error) {
                $extra .= sprintf(' error=%s/%s', $error['type'] ?? '?', $error['message'] ?? '?');
            }
            if ([] !== $usage) {
                $extra .= sprintf(' input_tokens=%s', $usage['input_tokens'] ?? '?');
            }
            if ('' !== (string) $before || '' !== (string) $after) {
                $extra .= sprintf(' tokens=%s→%s', $before, $after);
            }
            if ('' !== (string) $messagesCompacted) {
                $extra .= sprintf(' compacted=%s', $messagesCompacted);
            }

            $lines[] = sprintf('  [%s] %s%s', $seq, $type, $extra);
        }
        return implode("\n", $lines);
    }
}
