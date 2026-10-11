<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\Replay\SessionReplayCoordinator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SessionReplayCoordinatorTest extends IsolatedKernelTestCase
{
    private string $path;
    private string $runId;
    private SessionReplayCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runId = 'coordinated';
        $this->path = getcwd().'/.hatfield/sessions/coordinated/events.jsonl';
        (new Filesystem())->mkdir(\dirname($this->path));
        $this->coordinator = static::getContainer()->get(SessionReplayCoordinator::class);
    }

    public function testConfiguredOwnerColdEntryAdmitsOnlyCompletedReconstruction(): void
    {
        $container = static::getContainer();
        $this->runId = $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class)->createSession('owner replay');
        $this->path = $container->get(\Ineersa\CodingAgent\Session\SessionRunEventStore::class)->historySource($this->runId)->path;
        (new Filesystem())->mkdir(\dirname($this->path));
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('owner prompt')]]]);
        $this->write(7, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'owned-step']);
        $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner(
            $this->runId, new \Ineersa\CodingAgent\Application\Message\AttachRun($this->runId, [], 'owner-entry'),
        );
        $state = $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class)->requireLoaded($this->runId);
        $this->assertSame(7, $state->lastSeq);
        $this->assertSame('owned-step', $state->currentOperation?->stepId);
        $this->assertCount(1, $state->messages);
        $this->assertFileExists(\dirname($this->path).'/history-index.sqlite');
    }

    public function testReusedPendingKeyNeverRetainsHistoricalTextAndStateOnlyReplayCollectsNone(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('question')]]]);
        $this->write(3, 1, 'turn_advanced', ['turn_no' => 1]);
        $this->write(5, 1, 'agent_command_queued', ['kind' => 'steer', 'idempotency_key' => 'current', 'text' => str_repeat('x', 5 * 1024 * 1024)]);
        $this->write(7, 1, 'agent_command_rejected', ['kind' => 'steer', 'idempotency_key' => 'current', 'reason' => 'settled']);
        $this->write(9, 1, 'agent_command_queued', ['kind' => 'steer', 'idempotency_key' => 'current', 'text' => 'Still pending']);
        static::getContainer()->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class)->enqueue(
            new \Ineersa\AgentCore\Domain\Command\PendingCommand($this->runId, 'steer', 'current', ['text' => 'Still pending']),
        );
        $display = $this->coordinator->reconstruct(RunState::queued($this->runId), withTranscript: true);
        $this->assertSame(['current' => 'Still pending'], $display?->resume['queued_messages']);
        $this->assertSame([], $this->coordinator->reconstruct(RunState::queued($this->runId))?->resume);
    }

    public function testSharedTranscriptPreservesPendingToolEvidenceAcrossCompaction(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('question')]]]);
        $this->write(3, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step']);
        $this->write(7, 1, 'llm_step_completed', ['step_id' => 'step', 'assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [['id' => 'pending', 'name' => 'read', 'arguments' => [], 'order_index' => 0]]]]);
        $this->write(11, 1, 'tool_execution_start', ['tool_call_id' => 'pending', 'tool_name' => 'read', 'attempt' => 2]);
        $this->write(20, 1, 'context_compacted', ['messages' => [$this->message('summary')], 'trigger' => 'auto', 'continue_after_compaction' => true]);
        $result = $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
        $this->assertNotNull($result);
        $this->assertSame(['pending' => false], $result->state->pendingToolCalls);
        $this->assertSame('pending', $result->state->currentToolCalls[0]->toolCallId);
        $this->assertSame(2, $result->state->currentToolCalls[0]->attempt);
        $this->assertSame(20, $result->state->lastSeq);
        $this->assertCount(1, $result->state->messages);
        $this->assertNotEmpty($result->blocks);
        $this->assertSame(filesize($this->path), $result->endOffset);
        $this->assertSame([], static::getContainer()->get('owner.replay.transcript_projector')->blocks());
    }

    public function testDiscardedQueuedSeedAndReusedDisplayedTurnDoNotReturn(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('root')]]]);
        $this->write(3, 1, 'turn_advanced', ['turn_no' => 1]);
        $this->write(5, 1, 'agent_end', ['reason' => 'completed']);
        $this->write(7, 1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message('discarded')]);
        $this->write(9, 2, 'turn_advanced', ['turn_no' => 2]);
        $this->write(11, 2, 'agent_end', ['reason' => 'completed']);
        $this->write(13, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $this->write(15, 1, 'history_tail_discarded', ['after_turn_no' => 1]);
        $this->write(17, 1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message('replacement')]);
        $this->write(19, 2, 'turn_advanced', ['turn_no' => 2]);
        $this->write(23, 2, 'agent_end', ['reason' => 'completed']);
        $result = $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
        $this->assertNotNull($result);
        $this->assertCount(2, $result->state->messages);
        $this->assertStringNotContainsString('discarded', json_encode($result->state->messages, \JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('replacement', json_encode($result->state->messages, \JSON_THROW_ON_ERROR));
        $selected = $this->coordinator->reconstruct(RunState::queued('coordinated'), 1, true);
        $this->assertNotNull($selected);
        $this->assertCount(1, $selected->state->messages);
        $this->assertSame(23, $selected->state->lastSeq);
        $this->assertSame(1, $selected->state->turnNo);
        $this->assertNull($selected->state->currentOperation);
    }

    public function testDisplayEvictsCompleteGroupsWithoutTrimmingExecutionContext(): void
    {
        $text = str_repeat('x', 2 * 1024 * 1024);
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message($text)]]]);
        $this->write(3, 0, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message($text)]);
        $this->write(5, 0, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message($text)]);
        $result = $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
        $this->assertNotNull($result);
        $this->assertCount(3, $result->state->messages);
        $this->assertLessThanOrEqual(4 * 1024 * 1024, \strlen(json_encode($result->blocks, \JSON_THROW_ON_ERROR)));
    }

    public function testDisplayBlockCountStaysBounded(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => []]]);
        for ($seq = 2; $seq <= 2101; ++$seq) {
            $this->write($seq, 0, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message('bounded group')]);
        }
        $result = $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
        $this->assertNotNull($result);
        $this->assertCount(2100, $result->state->messages);
        $this->assertLessThanOrEqual(2000, \count($result->blocks));
        $this->assertGreaterThan(1900, \count($result->blocks));
    }

    public function testPartialIndexedReadFailureReleasesTemporaryProjection(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('root')]]]);
        $this->write(3, 1, 'turn_advanced', ['turn_no' => 1]);
        $this->write(5, 1, 'agent_end', ['reason' => 'completed']);
        $this->coordinator->reconstruct(RunState::queued('coordinated'));
        $bytes = file_get_contents($this->path);
        $this->assertIsString($bytes);
        file_put_contents($this->path, str_replace('"seq":3', '"seq":4', $bytes));
        try {
            $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
            $this->fail('Expected a canonical location mismatch.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }
        $this->assertSame([], static::getContainer()->get('owner.replay.transcript_projector')->blocks());
    }

    public function testHistoricalSelectionFencesArchivedOperationsOnPreviewAndColdRecovery(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('root')]]]);
        $this->write(3, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'old-step']);
        $this->write(7, 1, 'llm_step_completed', ['step_id' => 'old-step', 'assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [['id' => 'old-tool', 'name' => 'read', 'arguments' => [], 'order_index' => 0]]]]);
        $this->write(11, 1, 'tool_execution_start', ['tool_call_id' => 'old-tool', 'tool_name' => 'read']);
        $preview = $this->coordinator->reconstruct(RunState::queued('coordinated'), 1);
        $this->assertNotNull($preview);
        $this->assertSame([], $preview->state->pendingToolCalls);
        $this->assertSame([], $preview->state->currentToolCalls);
        $this->assertNull($preview->state->currentOperation);
        $this->write(19, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $recovered = $this->coordinator->reconstruct(RunState::queued('coordinated'));
        $this->assertNotNull($recovered);
        $this->assertSame([], $recovered->state->pendingToolCalls);
        $this->assertSame([], $recovered->state->currentToolCalls);
        $this->assertNull($recovered->state->currentOperation);
        $this->assertSame(19, $recovered->state->lastSeq);
        $late = new \Ineersa\AgentCore\Domain\Message\ToolCallResult(
            runId: 'coordinated', turnNo: 1, stepId: 'old-step', attempt: 1, idempotencyKey: 'old-result',
            toolCallId: 'old-tool', orderIndex: 0, result: ['content' => [['type' => 'text', 'text' => 'late archived result']]],
        );
        $ignored = static::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler::class)->handle($late, $recovered->state);
        $this->assertSame([], $ignored->events);
        $this->assertNull($ignored->nextState);
    }

    public function testDisplayEvictsCompletedToolAndAnsweredQuestionGroupsWithoutOrphans(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('root')]]]);
        $this->write(3, 0, 'waiting_human', ['question_id' => 'answered-question', 'prompt' => 'Proceed?']);
        $this->write(5, 0, 'agent_command_applied', ['kind' => 'human_response', 'question_id' => 'answered-question', 'answer' => 'yes']);
        $codec = static::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec::class);
        for ($i = 0; $i < 3; ++$i) {
            $call = 'complete-'.$i;
            $this->write(7 + $i * 6, 0, 'tool_execution_start', ['tool_call_id' => $call, 'tool_name' => 'read', 'arguments' => ['path' => 'example.txt']]);
            $result = new \Ineersa\AgentCore\Domain\Message\ToolCallResult(
                runId: 'coordinated', turnNo: 0, stepId: 'step', attempt: 1, idempotencyKey: $call,
                toolCallId: $call, orderIndex: 0,
                result: ['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => str_repeat('x', 1024 * 1024)]]],
            );
            $this->write(9 + $i * 6, 0, 'tool_execution_end', $codec->toEventPayload($result));
            $this->write(11 + $i * 6, 0, 'tool_batch_committed', []);
        }
        $view = $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
        $this->assertNotNull($view);
        $this->assertLessThanOrEqual(4 * 1024 * 1024, \strlen(json_encode($view->blocks, \JSON_THROW_ON_ERROR)));
        $ids = array_column($view->blocks, 'id');
        $this->assertNotContains('tool_call_complete-0', $ids);
        $this->assertContains('tool_call_complete-2', $ids);
        $this->assertContains('tool_result_complete-2', $ids);
        $this->assertCount(4, $view->state->messages);
        $this->assertSame([], $view->state->pendingToolCalls);
        $this->assertSame([], static::getContainer()->get('owner.replay.transcript_projector')->blocks());
    }

    public function testUnsupportedSchemaDoesNotReplacePromptOrAdmitAnEmptyRecoveredState(): void
    {
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('supported')]]]);
        $unsupported = static::getContainer()->get(EventPayloadNormalizer::class)->normalize('coordinated', 19, 0, 'run_started', ['payload' => ['messages' => [$this->message('unsupported')]]]);
        $unsupported['schema_version'] = '999.0';
        $line = json_encode($unsupported, \JSON_THROW_ON_ERROR)."\n";
        file_put_contents($this->path, $line, \FILE_APPEND);
        $result = $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
        $this->assertNotNull($result);
        $this->assertSame(19, $result->state->lastSeq);
        $this->assertCount(1, $result->state->messages);
        $this->assertStringNotContainsString('unsupported', json_encode($result->state->messages, \JSON_THROW_ON_ERROR));
        file_put_contents($this->path, $line);
        try {
            $this->coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
            $this->fail('Known incompatible history cannot become an empty recovered session.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no compatible replay records', $exception->getMessage());
        }
        $this->assertSame([], static::getContainer()->get('owner.replay.transcript_projector')->blocks());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('questionCancellation')]
    public function testCancelledQuestionCanBeEvictedButActiveQuestionPinsItsGroup(bool $cancel): void
    {
        $container = static::getContainer();
        $this->runId = $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class)->createSession('question eviction');
        $this->path = $container->get(\Ineersa\CodingAgent\Session\SessionRunEventStore::class)->historySource($this->runId)->path;
        (new Filesystem())->mkdir(\dirname($this->path));
        $this->write(1, 0, 'run_started', ['payload' => ['messages' => [$this->message('root')]]]);
        $this->write(3, 0, 'waiting_human', ['question_id' => 'historic-question', 'prompt' => 'Proceed?']);
        // An aborted model operation alone does not abandon the human wait.
        $this->write(4, 0, 'llm_step_aborted', ['step_id' => 'unrelated-step']);
        if ($cancel) {
            $this->write(5, 0, 'agent_command_applied', ['kind' => 'cancel']);
        }
        $codec = $container->get(\Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec::class);
        for ($i = 0; $i < 3; ++$i) {
            $call = 'complete-'.$i;
            $this->write(7 + $i * 6, 0, 'tool_execution_start', ['tool_call_id' => $call, 'tool_name' => 'read', 'arguments' => ['path' => 'example.txt']]);
            $result = new \Ineersa\AgentCore\Domain\Message\ToolCallResult(
                runId: $this->runId, turnNo: 0, stepId: 'step', attempt: 1, idempotencyKey: $call,
                toolCallId: $call, orderIndex: 0,
                result: ['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => str_repeat('x', 1024 * 1024)]]],
            );
            $this->write(9 + $i * 6, 0, 'tool_execution_end', $codec->toEventPayload($result));
            $this->write(11 + $i * 6, 0, 'tool_batch_committed', []);
        }
        $stateOnly = $this->coordinator->reconstruct(RunState::queued($this->runId));
        $this->assertNotNull($stateOnly);
        $this->assertCount($cancel ? 0 : 1, $stateOnly->state->pendingHumanInputRequests);
        if (!$cancel) {
            $this->expectException(\LengthException::class);
            $this->expectExceptionMessage('Transcript display group exceeds the bootstrap view budget');
        }
        $view = $this->coordinator->reconstruct(RunState::queued($this->runId), withTranscript: true);
        $this->assertNotNull($view);
        $this->assertLessThanOrEqual(2000, \count($view->blocks));
        $this->assertLessThanOrEqual(4 * 1024 * 1024, \strlen(json_encode($view->blocks, \JSON_THROW_ON_ERROR)));
        $this->assertNotContains('hitl_historic-question', array_column($view->blocks, 'id'));
        $this->assertContains('tool_call_complete-2', array_column($view->blocks, 'id'));
        $this->assertContains('tool_result_complete-2', array_column($view->blocks, 'id'));
        $spools = $container->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore::class);
        try {
            $descriptor = $spools->seal($this->runId, $view->state->lastSeq, $view->endOffset, $view->anchor, $view->blocks,
                ['status' => $view->state->status->value, 'model' => $view->state->model, 'turn_no' => $view->state->turnNo] + $view->resume);
            $this->assertTrue($spools->isActive($this->runId));
            $this->assertGreaterThan(0, $descriptor->bytes);
        } finally {
            $spools->cancel($this->runId);
        }
    }

    public static function questionCancellation(): iterable
    {
        yield 'accepted cancellation closes the question' => [true];
        yield 'operation abortion leaves the question protected' => [false];
    }

    public function testColdAndWarmMemoryInIndependent128MProcesses(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('coordinated-replay-memory');
        try {
            $measurements = [];
            foreach ([256, 4096] as $count) {
                $process = new Process([\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Fixtures/coordinated-replay-memory.php', $directory, (string) $count], \dirname(__DIR__, 4), ['HATFIELD_SESSION_ID' => false], timeout: 8);
                $process->mustRun();
                $measurements[] = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            }
            [$small, $large] = $measurements;
            fwrite(\STDERR, 'coordinated replay memory: '.json_encode($measurements, \JSON_THROW_ON_ERROR)."\n");
            $this->assertGreaterThan($small['archive_bytes'] * 15, $large['archive_bytes']);
            $this->assertLessThanOrEqual($small['peak_bytes'] + 4 * 1024 * 1024, $large['peak_bytes']);
            foreach ($measurements as $measurement) {
                $this->assertLessThan(128 * 1024 * 1024, $measurement['peak_bytes']);
                $this->assertSame($measurement['archive_bytes'], $measurement['cold']['index_cold_rebuild']);
                $this->assertSame($measurement['archive_bytes'], $measurement['cold']['selected_history']);
                $this->assertSame(0, $measurement['warm']['index_cold_rebuild'] ?? 0);
                $this->assertSame($measurement['archive_bytes'], $measurement['warm']['selected_history']);
                $this->assertSame(0, $measurement['selected']['index_cold_rebuild'] ?? 0);
                $this->assertLessThan($measurement['archive_bytes'] / 100, $measurement['selected']['selected_history']);
                $this->assertSame($measurement['count'] * 9 + 1, $measurement['selected_sequence']);
                $this->assertSame(1, $measurement['messages']);
                $this->assertLessThanOrEqual(2000, $measurement['blocks']);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    /** @return array<string, mixed> */
    private function message(string $text): array
    {
        return ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @param array<string, mixed> $payload */
    private function write(int $seq, int $turn, string $type, array $payload): void
    {
        $record = static::getContainer()->get(EventPayloadNormalizer::class)->normalize($this->runId, $seq, $turn, $type, $payload);
        file_put_contents($this->path, json_encode($record, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
    }
}
