<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\SessionSqliteQueueTestEnv;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;

#[Group('controller-replay')]
final class ControllerReplayHumanWaitCompletionTest extends ControllerReplayE2eTestCase
{
    private string $databasePath;

    /** @var array<string, string>|null */
    private ?array $queueEnv = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->projectDir.'/var/test/'.$this->databasePath.$suffix);
        }
    }

    #[TestWith(['A real answer'])]
    #[TestWith(['Cancelled by user'])]
    public function testBackgroundDeliveryPreservesAnswerableQuestion(string $answer): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->writeCommand(['v' => 1, 'id' => 'start', 'type' => 'start_run', 'payload' => ['prompt' => 'Ask a question.']]);
        $before = $this->collectEventsUntil('human_input.requested', 5);
        $this->assertStartRunAcked($before, 'start');
        $events = $this->indexByType($before);
        $this->runId = $events['run.started'][0]['runId'];
        $question = $events['human_input.requested'][0]['payload']['question_id'];

        // Queue delivery and an explicit consumption boundary before sending the
        // answer. All three use the same FIFO run_control owner transport.
        $delivery = new Process([\PHP_BINARY, __DIR__.'/Support/DeliverHumanWaitCompletion.php', $this->projectDir, $this->runId], $this->tempDir, array_merge($this->controllerSubprocessEnv(), $this->replayExtraEnv(), [
            'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir, 'HATFIELD_SESSION_ID' => false,
        ]));
        $delivery->setTimeout(3);
        $delivery->mustRun();

        $this->writeCommand(['v' => 1, 'id' => 'answer', 'type' => 'answer_human', 'runId' => $this->runId, 'payload' => ['question_id' => $question, 'answer' => $answer]]);
        $after = $this->collectEventsUntil('run.completed', 5);
        $this->assertTrue($this->foundAck($after, 'answer'), $this->collectDiagnostics($after));
        $types = $this->indexByType($after);
        $this->assertArrayNotHasKey('human_input.rejected', $types, $this->collectDiagnostics($after));
        $this->assertArrayHasKey('human_input.answered', $types, $this->collectDiagnostics($after));
        $this->assertSame($question, $types['human_input.answered'][0]['payload']['question_id']);
        $this->assertArrayHasKey('model.notification', $types, $this->collectDiagnostics($after));
        $canonical = array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        $answered = array_find_key($canonical, static fn (array $e): bool => 'agent_command_applied' === $e['type'] && 'human_response' === ($e['payload']['kind'] ?? null));
        $applied = array_find_key($canonical, static fn (array $e): bool => 'agent_command_applied' === $e['type'] && 'human-wait-completion' === ($e['payload']['idempotency_key'] ?? null));
        $this->assertNotNull($answered);
        $this->assertNotNull($applied);
        $this->assertLessThan($applied, $answered, 'The question is answered before the passive result is consumed.');
    }

    protected function tempDirPrefix(): string
    {
        return 'controller-human-wait-';
    }

    protected function replayFixtures(): array
    {
        $this->databasePath = 'app_test-replay-'.$this->sessionId.'.sqlite';
        $args = ['question' => 'Keep waiting?'];

        return [
            ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => 'tool_call', 'deltas' => [
                ['type' => 'tool_call_start', 'id' => 'ask', 'name' => 'ask_human'],
                ['type' => 'tool_input_delta', 'id' => 'ask', 'name' => 'ask_human', 'partial_json' => json_encode($args, \JSON_THROW_ON_ERROR)],
                ['type' => 'tool_call_complete', 'tool_calls' => [['id' => 'ask', 'name' => 'ask_human', 'arguments' => $args]]],
            ]],
            ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Answered.']]],
        ];
    }

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_TEST_DATABASE_PATH' => $this->databasePath];
    }

    protected function controllerSubprocessEnv(): array
    {
        // forSession allocates a fresh endpoint each time; the producer must use
        // the controller's exact launch endpoint, not merely its database path.
        return array_merge(parent::controllerSubprocessEnv(), $this->queueEnv ??= SessionSqliteQueueTestEnv::forSession($this->tempDir.'/.hatfield/sessions', $this->sessionId));
    }
}
