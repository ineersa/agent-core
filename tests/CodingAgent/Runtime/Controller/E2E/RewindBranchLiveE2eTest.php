<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Process\SourceTreeExecutableLocator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live LLM E2E: /tree rewind must restore branch-specific model context.
 *
 * Drives the REAL controller subprocess through JsonlProcessAgentSessionClient::send()
 * — the production TUI transport path. Originally written to catch the issue #183
 * anti-pattern (replay green while live transport lacked rewind_to_turn); that arm
 * now exists, so this test protects the semantic branch-context contract.
 *
 * Canonical turn IDs are opaque and may be sparse under max(lastSeq, turnNo)+1
 * allocation. Rewind targets are taken from turn.started payloads for the
 * conversational turns under test — never assumed ordinal 1/2.
 *
 * @see JsonlProcessAgentSessionClient::send()
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class RewindBranchLiveE2eTest extends ControllerE2eTestCase
{
    private ?JsonlProcessAgentSessionClient $client = null;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = '[llm-real:rewind-branch-v2]';
    }

    protected function tearDown(): void
    {
        // Disconnect client first so __destruct stops the controller process
        // before parent::tearDown() cleans the temp dir.
        $this->client = null;
        parent::tearDown();
    }

    /**
     * Full semantic scenario: rewind must restore per-branch message context.
     *
     * Flow: start conversational turn → teach "pineapple" on a child turn →
     * rewind to the first conversational turn identity → teach "apple" →
     * prove apple context (no pineapple) → rewind to pineapple turn identity →
     * prove pineapple context.
     */
    public function testRewindChangesContextWithRealLlm(): void
    {
        // ── Set env vars the controller subprocess needs ─────────────────
        // The client's spawnProcess() inherits the current process env via
        // getenv() and overrides specific keys. We need APP_ENV=test so the
        // controller loads test services/config (5s HttpClient timeout). We
        // also need HATFIELD_TEST_DATABASE_PATH for messenger DB isolation.
        $_SERVER['APP_ENV'] = 'test';
        putenv('APP_ENV=test');
        putenv('HATFIELD_TEST_DATABASE_PATH=app_test-live-'.$this->sessionId.'.sqlite');

        try {
            // ── Construct the REAL client ────────────────────────────────
            $projectDir = ProjectDir::get();
            $runtimeConfig = new RuntimeProcessConfig(
                executableLocator: new SourceTreeExecutableLocator($projectDir),
                runtimeCwd: $this->tempDir,
            );

            $this->client = new JsonlProcessAgentSessionClient(
                runtimeConfig: $runtimeConfig,
                promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
                logger: new TestLogger(),
            );

            $runId = $this->sessionId;

            // ── First conversational turn: start_run ─────────────────────
            $this->client->start(new StartRunRequest(
                prompt: $this->marker.' I will share secret words, remember them.',
                runId: $runId,
            ));

            $events1 = $this->collectEventsFromClient($runId, 30.0);
            $byType1 = $this->indexClientEvents($events1);
            $this->assertArrayHasKey('run.completed', $byType1,
                'First conversational turn: expected run.completed. '
                .$this->diagnosticClientInfo()
            );
            $firstTurnNo = $this->requireLatestTurnStartedNo(
                $byType1,
                'first conversational turn after start_run',
            );

            // ── Follow-up: teach "pineapple" on a child turn ─────────────
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' The secret word is pineapple.',
            ));

            $events2 = $this->collectEventsFromClient($runId, 30.0);
            $byType2 = $this->indexClientEvents($events2);
            $this->assertArrayHasKey('run.completed', $byType2,
                'Pineapple turn: expected run.completed. '
                .$this->diagnosticClientInfo()
            );
            $pineappleTurnNo = $this->requireLatestTurnStartedNo(
                $byType2,
                'pineapple follow-up turn',
            );
            $this->assertNotSame(
                $firstTurnNo,
                $pineappleTurnNo,
                'Pineapple child turn must receive a distinct opaque identity from the first conversational turn. '
                .$this->diagnosticClientInfo()
            );

            // ── REWIND to first conversational turn (opaque identity) ────
            $this->client->send($runId, new UserCommand(
                type: 'rewind_to_turn',
                text: null,
                payload: ['turn_no' => $firstTurnNo],
            ));

            $events3 = $this->collectEventsFromClient($runId, 30.0);
            $byType3 = $this->indexClientEvents($events3);

            $this->assertArrayHasKey('run.leaf_changed', $byType3,
                'rewind_to_turn must emit run.leaf_changed. '
                .$this->diagnosticClientInfo()
            );

            $leafChanged = $byType3['run.leaf_changed'][0];
            $this->assertSame($firstTurnNo, (int) ($leafChanged->payload['turn_no'] ?? 0),
                'RunLeafChanged must reference the first conversational turn identity ('.$firstTurnNo.'). '
                .$this->diagnosticClientInfo()
            );

            // ── Follow-up: teach "apple" branched from first turn ────────
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' The secret word is apple.',
            ));

            $events4 = $this->collectEventsFromClient($runId, 30.0);
            $byType4 = $this->indexClientEvents($events4);
            $this->assertArrayHasKey('run.completed', $byType4,
                'Apple turn: expected run.completed. '
                .$this->diagnosticClientInfo()
            );

            // ── Ask what the secret word is (apple branch) ───────────────
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' What is the secret word? Reply with exactly one word.',
            ));

            $events5 = $this->collectEventsFromClient($runId, 30.0);
            $byType5 = $this->indexClientEvents($events5);

            $this->assertAssistantResponseContains('apple', $byType5,
                'After rewind to first conversational turn '.$firstTurnNo
                .' and learning "apple" on the new branch, '
                .'the model should answer "apple" (pineapple turn '.$pineappleTurnNo.' is abandoned). '
                .'If the answer is "pineapple", rewind is broken — abandoned-branch '
                .'context is leaking.',
                'pineapple'
            );

            // ── Rewind to pineapple turn (opaque identity) ───────────────
            $this->client->send($runId, new UserCommand(
                type: 'rewind_to_turn',
                text: null,
                payload: ['turn_no' => $pineappleTurnNo],
            ));

            $events6 = $this->collectEventsFromClient($runId, 30.0);
            $byType6 = $this->indexClientEvents($events6);
            $this->assertArrayHasKey('run.leaf_changed', $byType6,
                'Second rewind must emit run.leaf_changed. '
                .$this->diagnosticClientInfo()
            );
            $leafChangedPineapple = $byType6['run.leaf_changed'][0];
            $this->assertSame($pineappleTurnNo, (int) ($leafChangedPineapple->payload['turn_no'] ?? 0),
                'Second RunLeafChanged must reference the pineapple turn identity ('.$pineappleTurnNo.'). '
                .$this->diagnosticClientInfo()
            );

            // ── Ask what the secret word is (pineapple branch) ───────────
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' What is the secret word? Reply with exactly one word.',
            ));

            $events7 = $this->collectEventsFromClient($runId, 30.0);
            $byType7 = $this->indexClientEvents($events7);

            $this->assertAssistantResponseContains('pineapple', $byType7,
                'After rewind to pineapple turn '.$pineappleTurnNo.', the model should answer "pineapple" '
                .'(the apple branch is now the abandoned one).'
            );
        } finally {
            putenv('HATFIELD_TEST_DATABASE_PATH');
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-rewind-branch';
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Extract the opaque turn identity from the latest turn.started runtime event.
     *
     * Sparse allocation (max(lastSeq, turnNo)+1) means these are not ordinal 1/2.
     *
     * @param array<string, list<RuntimeEvent>> $byType
     */
    private function requireLatestTurnStartedNo(array $byType, string $context): int
    {
        $started = $byType['turn.started'] ?? [];
        $this->assertNotEmpty(
            $started,
            'Expected turn.started for '.$context.'. Available: '.implode(', ', array_keys($byType)).'. '
            .$this->diagnosticClientInfo()
        );

        $last = end($started);
        $turnNo = (int) ($last->payload['turn_no'] ?? 0);
        $this->assertGreaterThan(
            0,
            $turnNo,
            'turn.started for '.$context.' must carry a positive turn_no payload. '
            .$this->diagnosticClientInfo()
        );

        return $turnNo;
    }

    /**
     * Poll $client->events($runId) until a terminal event or timeout.
     *
     * @return list<RuntimeEvent>
     */
    private function collectEventsFromClient(string $runId, float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $batch = [];
            foreach ($this->client->events($runId) as $event) {
                $batch[] = $event;
            }

            if ([] !== $batch) {
                $events = array_merge($events, $batch);
                $last = end($batch);

                // Terminal events — turn/operation is complete.
                if (\in_array($last->type, ['run.completed', 'run.failed', 'run.leaf_changed'], true)) {
                    return $events;
                }
            }

            // Also check for terminal events from the full accumulated set
            // in case an earlier event in the batch is terminal.
            foreach ($events as $event) {
                if (\in_array($event->type, ['run.completed', 'run.failed', 'run.leaf_changed'], true)) {
                    return $events;
                }
            }

            usleep(50_000); // 50ms poll interval
        }

        return $events;
    }

    /**
     * @param list<RuntimeEvent> $events
     *
     * @return array<string, list<RuntimeEvent>>
     */
    private function indexClientEvents(array $events): array
    {
        $byType = [];

        foreach ($events as $event) {
            $byType[$event->type][] = $event;
        }

        return $byType;
    }

    /**
     * @param array<string, list<RuntimeEvent>> $byType
     */
    private function assertAssistantResponseContains(string $expected, array $byType, string $message, ?string $mustNotContain = null): void
    {
        $completed = $byType['assistant.message_completed'] ?? [];
        $this->assertNotEmpty($completed, $message."\nNo assistant.message_completed found. "
            .'Available event types: '.implode(', ', array_keys($byType)));

        $last = end($completed);
        $text = strtolower((string) ($last->payload['text'] ?? ''));

        // Word-boundary match so 'apple' does not falsely match inside 'pineapple'.
        $this->assertMatchesRegularExpression(
            '/\b'.preg_quote($expected, '/').'\b/',
            $text,
            $message."\nAssistant text: ".substr($text, 0, 500),
        );

        // Negative discrimination: when provided, the response must NOT contain
        // the abandoned branch's secret word (e.g. 'pineapple' must be absent on
        // the apple branch — catches abandoned-branch context leaking).
        if (null !== $mustNotContain) {
            $this->assertStringNotContainsString(
                $mustNotContain,
                $text,
                $message."\nAssistant text must not contain '$mustNotContain': ".substr($text, 0, 500),
            );
        }
    }

    private function diagnosticClientInfo(): string
    {
        $info = 'Temp dir: '.($this->tempDir ?? 'N/A');

        if (isset($this->tempDir) && '' !== $this->tempDir) {
            $sessionDirs = glob($this->tempDir.'/.hatfield/sessions/*', \GLOB_ONLYDIR);
            if (\is_array($sessionDirs) && [] !== $sessionDirs) {
                $info .= "\nSession dirs: ".implode(', ', $sessionDirs);
                $firstDir = reset($sessionDirs);

                if (\is_string($firstDir)) {
                    $eventsFile = $firstDir.'/events.jsonl';
                    if (is_file($eventsFile)) {
                        $info .= "\nEvents file size: ".filesize($eventsFile);
                    }
                }
            }
        }

        return $info;
    }
}
