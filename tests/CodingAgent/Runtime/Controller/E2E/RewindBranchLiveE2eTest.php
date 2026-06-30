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
 * Live LLM E2E test proving the /tree rewind feature is broken in the real transport.
 *
 * Drives the REAL controller subprocess through JsonlProcessAgentSessionClient::send()
 * which reproduces the user's exact exception:
 *
 *     InvalidArgumentException: Unknown command type: "rewind_to_turn"
 *
 * thrown at JsonlProcessAgentSessionClient.php:273 because the match statement
 * in send() has no arm for 'rewind_to_turn'.
 *
 * Every existing rewind test uses either InProcessAgentSessionClient (which DOES
 * have the arm) or writes JSONL directly with writeCommand() — neither catches this.
 * This is the issue #183 anti-pattern from AGENTS.md: replay/mocked tests green while
 * the real transport is dead.
 *
 *     "Trust the live reproduction over the fixture. When a user-reported bug survives
 *     replay tests, the replay is exercising the wrong path. Reach for a live LLM
 *     controller E2E (#[Group('llm-real')], real controller subprocess via
 *     JsonlProcessAgentSessionClient) that reproduces the exact user scenario."
 *
 * This test WILL FAIL until the missing 'rewind_to_turn' arm is added to
 * JsonlProcessAgentSessionClient::send(). Once the arm is added, the test
 * proceeds to verify the full semantic scenario and proves rewind changes
 * the model's context.
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
        $this->marker = '[llm-real:rewind-branch-'.bin2hex(random_bytes(6)).']';
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
     * The test drives the REAL controller subprocess through the REAL
     * JsonlProcessAgentSessionClient::send() — the exact code path the TUI
     * uses in production.
     *
     * It WILL FAIL at the first send('rewind_to_turn') with:
     *     InvalidArgumentException: Unknown command type: "rewind_to_turn"
     *
     * The failure IS the proof: the real transport is broken even though every
     * replay-based test is green.
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

            // ── Turn 1: start_run ────────────────────────────────────────
            $this->client->start(new StartRunRequest(
                prompt: $this->marker.' I will share secret words, remember them.',
                runId: $runId,
            ));

            $events1 = $this->collectEventsFromClient($runId, 30.0);
            $byType1 = $this->indexClientEvents($events1);
            $this->assertArrayHasKey('run.completed', $byType1,
                'Turn 1: expected run.completed. '
                .$this->diagnosticClientInfo()
            );

            // ── Turn 2: follow_up "The secret word is pineapple." ────────
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' The secret word is pineapple.',
            ));

            $events2 = $this->collectEventsFromClient($runId, 30.0);
            $byType2 = $this->indexClientEvents($events2);
            $this->assertArrayHasKey('run.completed', $byType2,
                'Turn 2: expected run.completed. '
                .$this->diagnosticClientInfo()
            );

            // ── REWIND to turn 1 — THIS IS THE USER'S EXACT BUG ──────────
            // JsonlProcessAgentSessionClient::send() has no 'rewind_to_turn' arm
            // in its match statement (line ~273). This throws:
            //
            //   InvalidArgumentException: Unknown command type: "rewind_to_turn"
            //
            // The test MUST fail here with this specific exception.
            $this->client->send($runId, new UserCommand(
                type: 'rewind_to_turn',
                text: null,
                payload: ['turn_no' => 1],
            ));

            // ── If we reach here, the bug is fixed — verify semantics ────
            $events3 = $this->collectEventsFromClient($runId, 30.0);
            $byType3 = $this->indexClientEvents($events3);

            $this->assertArrayHasKey('run.leaf_changed', $byType3,
                'rewind_to_turn must emit run.leaf_changed. '
                .$this->diagnosticClientInfo()
            );

            $leafChanged = $byType3['run.leaf_changed'][0];
            $this->assertSame(1, (int) ($leafChanged->payload['turn_no'] ?? 0),
                'RunLeafChanged must reference target turn (1). '
                .$this->diagnosticClientInfo()
            );

            // ── Turn 3: follow_up "apple" (branched from turn 1) ─────────
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' The secret word is apple.',
            ));

            $events4 = $this->collectEventsFromClient($runId, 30.0);
            $byType4 = $this->indexClientEvents($events4);
            $this->assertArrayHasKey('run.completed', $byType4,
                'Turn 3: expected run.completed. '
                .$this->diagnosticClientInfo()
            );

            // ── Turn 4: ask what the secret word is ──────────────────────
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' What is the secret word? Reply with exactly one word.',
            ));

            $events5 = $this->collectEventsFromClient($runId, 30.0);
            $byType5 = $this->indexClientEvents($events5);

            $this->assertAssistantResponseContains('apple', $byType5,
                'After rewind to turn 1 and learning "apple" as turn 3, '
                .'the model should answer "apple" (turn 2 "pineapple" is abandoned). '
                .'If the answer is "pineapple", rewind is broken — abandoned-branch '
                .'context is leaking.',
                'pineapple'
            );

            // ── Rewind to turn 2 (the pineapple turn) ────────────────────
            $this->client->send($runId, new UserCommand(
                type: 'rewind_to_turn',
                text: null,
                payload: ['turn_no' => 2],
            ));

            $events6 = $this->collectEventsFromClient($runId, 30.0);
            $byType6 = $this->indexClientEvents($events6);
            $this->assertArrayHasKey('run.leaf_changed', $byType6,
                'Second rewind must emit run.leaf_changed. '
                .$this->diagnosticClientInfo()
            );

            // ── Turn 5: ask what the secret word is (pineapple branch) ───
            $this->client->send($runId, new UserCommand(
                type: 'follow_up',
                text: $this->marker.' What is the secret word? Reply with exactly one word.',
            ));

            $events7 = $this->collectEventsFromClient($runId, 30.0);
            $byType7 = $this->indexClientEvents($events7);

            $this->assertAssistantResponseContains('pineapple', $byType7,
                'After rewind to turn 2, the model should answer "pineapple" '
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
