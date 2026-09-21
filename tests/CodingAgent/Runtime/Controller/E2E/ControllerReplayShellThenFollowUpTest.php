<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Proves completed direct shells do not leave canonical state that blocks the
 * next shell or a normal follow-up on the same controller and session.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayShellThenFollowUpTest extends ControllerReplayE2eTestCase
{
    private const string FIRST_COMMAND_ID = 'cmd_shell_one';
    private const string FIRST_MARKER = 'shell-one-complete';
    private const string SECOND_COMMAND_ID = 'cmd_shell_two';
    private const string SECOND_MARKER = 'shell-two-complete';
    private const string FOLLOW_UP_COMMAND_ID = 'cmd_follow_up';
    private const string FOLLOW_UP_TEXT = '[controller-replay:shell-follow-up] continue';
    private const string FOLLOW_UP_RESULT = 'follow-up after shells completed';

    public function testTwoCompletedShellCommandsAllowNormalFollowUpOnSameController(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->runId = $this->sessionId;

        $firstEvents = $this->executeShell(self::FIRST_COMMAND_ID, self::FIRST_MARKER);
        $secondEvents = $this->executeShell(self::SECOND_COMMAND_ID, self::SECOND_MARKER);

        $this->writeCommand([
            'v' => 1,
            'id' => self::FOLLOW_UP_COMMAND_ID,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => ['text' => self::FOLLOW_UP_TEXT],
        ]);

        $followUpEvents = $this->collectEventsUntil('run.completed', 8.0);
        $followUpByType = $this->indexByType($followUpEvents);
        $this->assertTrue(
            $this->foundAck($followUpEvents, self::FOLLOW_UP_COMMAND_ID),
            'Expected command.ack for normal follow_up. '.$this->collectDiagnostics($followUpEvents),
        );
        $this->assertArrayHasKey('run.completed', $followUpByType, $this->collectDiagnostics($followUpEvents));
        $this->assertArrayNotHasKey('command.rejected', $followUpByType, $this->collectDiagnostics($followUpEvents));
        $this->assertArrayNotHasKey('run.failed', $followUpByType, $this->collectDiagnostics($followUpEvents));

        $allProtocolEvents = array_merge($firstEvents, $secondEvents, $followUpEvents);
        $this->assertCanonicalShellOrdering($allProtocolEvents);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-shell-followup';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/controller-shell-followup.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($fixture);

        return [$fixture];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function executeShell(string $commandId, string $marker): array
    {
        $this->writeCommand([
            'v' => 1,
            'id' => $commandId,
            'type' => 'shell_command',
            'runId' => $this->runId,
            'payload' => ['text' => '!printf '.escapeshellarg($marker)],
        ]);

        $events = $this->collectEventsUntil('run.completed', 8.0);
        $byType = $this->indexByType($events);

        $this->assertTrue(
            $this->foundAck($events, $commandId),
            'Expected command.ack for shell command. '.$this->collectDiagnostics($events),
        );
        $this->assertArrayHasKey('tool_execution.started', $byType, $this->collectDiagnostics($events));
        $this->assertArrayHasKey('tool_execution.completed', $byType, $this->collectDiagnostics($events));
        $this->assertArrayHasKey('run.completed', $byType, $this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('tool_execution.failed', $byType, $this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('run.failed', $byType, $this->collectDiagnostics($events));

        $started = $byType['tool_execution.started'][0];
        $completed = $byType['tool_execution.completed'][0];
        $this->assertSame('bash', $started['payload']['tool_name'] ?? null);
        $this->assertSame(
            $started['payload']['tool_call_id'] ?? null,
            $completed['payload']['tool_call_id'] ?? null,
            'Completed shell must match its started tool call.',
        );
        $this->assertStringContainsString($marker, (string) ($completed['payload']['result'] ?? ''));

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $protocolEvents
     */
    private function assertCanonicalShellOrdering(array $protocolEvents): void
    {
        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $protocolEvents);

        $lines = file($sessionDir.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $events = array_map(
            static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            $lines,
        );

        $cursor = -1;
        foreach ([
            self::FIRST_COMMAND_ID => self::FIRST_MARKER,
            self::SECOND_COMMAND_ID => self::SECOND_MARKER,
        ] as $commandId => $marker) {
            $applied = $this->nextCanonicalEventIndex(
                $events,
                $cursor,
                static fn (array $event): bool => 'agent_command_applied' === ($event['type'] ?? null)
                    && str_contains((string) ($event['payload']['text'] ?? ''), $marker)
                    && $commandId === ($event['payload']['current_operation']['step_id'] ?? null),
            );
            $started = $this->nextCanonicalEventIndex($events, $applied, static fn (array $event): bool => 'tool_execution_start' === ($event['type'] ?? null));
            $ended = $this->nextCanonicalEventIndex(
                $events,
                $started,
                static fn (array $event): bool => 'tool_execution_end' === ($event['type'] ?? null)
                    && str_contains(self::canonicalToolResultText($event), $marker),
            );
            $cursor = $this->nextCanonicalEventIndex($events, $ended, static fn (array $event): bool => 'agent_end' === ($event['type'] ?? null));
        }

        $followUp = $this->nextCanonicalEventIndex(
            $events,
            $cursor,
            static fn (array $event): bool => 'agent_command_applied' === ($event['type'] ?? null)
                && 'follow_up' === ($event['payload']['kind'] ?? null),
        );
        $this->assertGreaterThan($cursor, $followUp);
        $llmStep = $this->nextCanonicalEventIndex(
            $events,
            $followUp,
            static fn (array $event): bool => 'llm_step_completed' === ($event['type'] ?? null)
                && self::FOLLOW_UP_RESULT === ($event['payload']['assistant_message']['content'][0]['text'] ?? null),
        );
        $this->nextCanonicalEventIndex($events, $llmStep, static fn (array $event): bool => 'agent_end' === ($event['type'] ?? null));
        $this->assertCount(1, array_filter(
            $events,
            static fn (array $event): bool => 'llm_step_completed' === ($event['type'] ?? null),
        ), 'The single explicit follow-up fixture must service exactly one LLM turn.');
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function canonicalToolResultText(array $event): string
    {
        $content = $event['payload']['tool_result']['result']['content'] ?? [];
        if (!\is_array($content)) {
            return '';
        }

        return implode('', array_map(
            static fn (mixed $part): string => \is_array($part) && \is_string($part['text'] ?? null)
                ? $part['text']
                : '',
            $content,
        ));
    }

    /**
     * @param list<array<string, mixed>>           $events
     * @param callable(array<string, mixed>): bool $matches
     */
    private function nextCanonicalEventIndex(array $events, int $after, callable $matches): int
    {
        for ($index = $after + 1, $count = \count($events); $index < $count; ++$index) {
            if ($matches($events[$index])) {
                return $index;
            }
        }

        $this->fail('Expected canonical event after index '.$after.'.');
    }
}
