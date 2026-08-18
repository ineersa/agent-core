<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Replay-backed race proof: cancel after the first tool of a two-tool bash batch
 * has committed durable result/end but before batch projection completes.
 *
 * Asserts both tool messages land, tool_batch_committed.count=2, and a follow_up
 * completes without MalformedToolCallSequenceException.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayTwoToolCancelFollowUpTest extends ControllerReplayE2eTestCase
{
    private const string FOLLOW_UP_SENTINEL = 'FOLLOWUP_AFTER_TWO_TOOL_CANCEL_OK';

    private const string FAST_TOOL_ID = 'call_bash_fast_1';

    private const string SLOW_TOOL_ID = 'call_bash_slow_2';

    public function testTwoToolBatchCancelProjectsBothToolMessagesAndFollowUpSucceeds(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Run printf done and sleep 8 in one step.',
            ],
        ]);

        // Gate on the FAST tool's durable completion while the slow sibling is still in flight.
        $phase1 = $this->collectEventsUntilToolCallCompleted(self::FAST_TOOL_ID, 12.0);

        $p1 = $this->indexByType($phase1);
        $this->assertStartRunAcked($phase1, $startCmdId);
        $this->assertArrayHasKey('tool_execution.started', $p1, $this->collectDiagnostics($phase1));
        $this->assertTrue(
            $this->hasToolExecutionCompletedFor($phase1, self::FAST_TOOL_ID),
            'Fast tool must complete before cancel. '.$this->collectDiagnostics($phase1),
        );

        $runStarted = $p1['run.started'][0] ?? null;
        $this->assertIsArray($runStarted);
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $cancelCmdId = 'cmd_cancel_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $cancelCmdId,
            'type' => 'cancel',
            'runId' => $this->runId,
        ]);

        $cancelPhase = $this->collectEventsUntil('run.cancelled', 12.0);
        $cancelByType = $this->indexByType($cancelPhase);
        $this->assertTrue(
            $this->foundAck($cancelPhase, $cancelCmdId),
            'Expected command.ack for cancel. '.$this->collectDiagnostics($cancelPhase),
        );
        $this->assertArrayHasKey(
            'run.cancelled',
            $cancelByType,
            'Two-tool cancel must terminalize as run.cancelled. '.$this->collectDiagnostics($cancelPhase),
        );

        $canonical = $this->readCanonicalEvents();
        $toolMessageIds = [];
        $batchCommittedCounts = [];
        $agentEndReasons = [];
        foreach ($canonical as $row) {
            $type = $row['type'] ?? null;
            $payload = \is_array($row['payload'] ?? null) ? $row['payload'] : [];
            if (RunEventTypeEnum::MessageEnd->value === $type && 'tool' === ($payload['message_role'] ?? null)) {
                $toolCallId = $payload['tool_call_id'] ?? null;
                if (\is_string($toolCallId)) {
                    $toolMessageIds[] = $toolCallId;
                }
            }
            if (RunEventTypeEnum::ToolBatchCommitted->value === $type) {
                $batchCommittedCounts[] = $payload['count'] ?? null;
            }
            if (RunEventTypeEnum::AgentEnd->value === $type) {
                $agentEndReasons[] = $payload['reason'] ?? null;
            }
        }

        $this->assertContains(self::FAST_TOOL_ID, $toolMessageIds, 'Fast tool must have a durable tool message.');
        $this->assertContains(self::SLOW_TOOL_ID, $toolMessageIds, 'Slow/cancelled tool must have a durable tool message.');
        $this->assertSame(
            [self::FAST_TOOL_ID, self::SLOW_TOOL_ID],
            array_values(array_unique($toolMessageIds)),
            'Exactly one tool message per id.',
        );
        $this->assertSame([2], $batchCommittedCounts, 'tool_batch_committed.count must cover both tool messages.');
        $this->assertSame(['cancelled'], $agentEndReasons, 'Exactly one agent_end(cancelled).');

        $followUpCmdId = 'cmd_fu_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => ['text' => self::FOLLOW_UP_SENTINEL],
        ]);

        $followUpPhase = $this->collectEventsUntil('run.completed', 15.0);
        $fuByType = $this->indexByType($followUpPhase);

        $this->assertTrue(
            $this->foundAck($followUpPhase, $followUpCmdId),
            'follow_up after two-tool cancel must be accepted. '.$this->collectDiagnostics($followUpPhase),
        );
        $this->assertArrayNotHasKey(
            'command.rejected',
            $fuByType,
            'follow_up must not be rejected after two-tool cancellation. '.$this->collectDiagnostics($followUpPhase),
        );
        $this->assertArrayHasKey(
            'run.completed',
            $fuByType,
            'follow_up after two-tool cancel must complete. '.$this->collectDiagnostics($followUpPhase),
        );
        $this->assertTrue(
            $this->hasAssistantResponseEvidence($fuByType),
            'follow_up after two-tool cancel must produce assistant output. '.$this->collectDiagnostics($followUpPhase),
        );

        $textEvents = $fuByType['assistant.text_delta'] ?? $fuByType['assistant.message_completed'] ?? [];
        $joined = '';
        foreach ($textEvents as $ev) {
            $payload = $ev['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            $joined .= (string) ($payload['text'] ?? $payload['content'] ?? '');
        }
        $this->assertStringContainsString(
            self::FOLLOW_UP_SENTINEL,
            '' !== $joined ? $joined : json_encode($fuByType, \JSON_UNESCAPED_UNICODE),
            'Replay fixture sentinel must appear in assistant output after follow_up.',
        );

        // Canonical message sequence after repair-prevention must pass the provider validator.
        $messages = $this->replayCanonicalMessages();
        (new AgentMessageToolCallSequenceValidator())->validate($messages);
        (new AgentMessageToolCallSequenceValidator())->validate(array_merge(
            $messages,
            [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'extra']])],
        ));
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-two-tool-cancel-fu';
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
        $settings['extensions']['settings']['safe_guard']['allow_command_patterns'] = [
            '^printf\\b',
            '^sleep\\b',
        ];
        file_put_contents($path, \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $batchFixturePath = __DIR__.'/fixtures/controller-two-bash-cancel-race.json';
        $batchFixture = json_decode(
            (string) file_get_contents($batchFixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($batchFixture);

        $followUpFixture = [
            '$schema' => 'Synthetic controller replay — follow_up after two-tool cancel',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => '2026-08-18T00:00:00+00:00',
            'recording_source' => 'manual',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => self::FOLLOW_UP_SENTINEL],
                ],
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 8, 'total_tokens' => 13],
            'stop_reason' => 'stop',
            'expected_text' => self::FOLLOW_UP_SENTINEL,
            'deltas' => [
                ['type' => 'text', 'content' => self::FOLLOW_UP_SENTINEL],
            ],
        ];

        return [$batchFixture, $followUpFixture];
    }

    protected function replayExtraEnv(): array
    {
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectEventsUntilToolCallCompleted(string $toolCallId, float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                $type = $event['type'] ?? '';
                $payload = $event['payload'] ?? [];
                if (!\is_array($payload)) {
                    $payload = [];
                }

                if ('tool_execution.completed' === $type
                    && ($payload['tool_call_id'] ?? null) === $toolCallId) {
                    return $events;
                }

                if ($this->isParentRunTerminalEvent($event)) {
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
     * @param list<array<string, mixed>> $events
     */
    private function hasToolExecutionCompletedFor(array $events, string $toolCallId): bool
    {
        foreach ($events as $event) {
            if (($event['type'] ?? null) !== 'tool_execution.completed') {
                continue;
            }
            $payload = $event['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            if (($payload['tool_call_id'] ?? null) === $toolCallId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCanonicalEvents(): array
    {
        $path = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl';
        $this->assertFileExists($path, 'Canonical events.jsonl must exist after cancel.');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $rows = [];
        foreach (explode("\n", $contents) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }
            $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $rows[] = $decoded;
        }

        return $rows;
    }

    /**
     * @return list<AgentMessage>
     */
    private function replayCanonicalMessages(): array
    {
        $normalizer = new \Ineersa\AgentCore\Schema\EventPayloadNormalizer();
        $events = [];
        foreach ($this->readCanonicalEvents() as $row) {
            $event = $normalizer->denormalizeRunEvent($row);
            $this->assertNotNull($event, 'Failed to denormalize canonical event: '.json_encode($row));
            $events[] = $event;
        }

        $reducer = new \Ineersa\AgentCore\Application\Replay\RunStateReducer();
        $replayed = $reducer->replay(
            \Ineersa\AgentCore\Domain\Run\RunState::queued($this->runId),
            $events,
        );

        return $replayed->messages;
    }
}
