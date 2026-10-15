<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/** The JSONL controller must carry the selected role through the owner bus. */
#[Group('controller-replay')]
final class ControllerReplayHistorySelectionTest extends ControllerReplayE2eTestCase
{
    public function testAssistantSelectionReturnsAfterAnswerBoundaryAndFollowingPrompt(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->writeCommand(['v' => 1, 'id' => 'first', 'type' => 'start_run', 'payload' => ['prompt' => 'First user prompt']]);
        $first = $this->collectEventsUntil('run.completed', 5.0);
        $this->assertStartRunAcked($first, 'first');
        $firstTypes = $this->indexByType($first);
        $this->assertArrayHasKey('run.completed', $firstTypes, $this->collectDiagnostics($first));
        $run = $firstTypes['run.started'][0]['runId'];
        $firstTurn = $firstTypes['turn.started'][0]['payload']['turn_no'];
        $nextPrompt = str_repeat('Next user prompt. ', 20);
        $this->writeCommand(['v' => 1, 'id' => 'second', 'type' => 'follow_up', 'runId' => $run, 'payload' => ['text' => $nextPrompt]]);
        $second = $this->collectEventsUntil('run.completed', 5.0);
        $secondTypes = $this->indexByType($second);
        $this->assertArrayHasKey('run.completed', $secondTypes, $this->collectDiagnostics($second));
        $secondTurn = $secondTypes['turn.started'][0]['payload']['turn_no'];
        $this->writeCommand(['v' => 1, 'id' => 'select-answer', 'type' => 'select_history_turn', 'runId' => $run, 'payload' => ['turn_no' => $firstTurn, 'role' => 'assistant']]);
        $selected = $this->collectEventsUntil('run.history_position_changed', 5.0);
        $selectedTypes = $this->indexByType($selected);
        $this->assertArrayHasKey('run.history_position_changed', $selectedTypes, $this->collectDiagnostics($selected));
        $position = $selectedTypes['run.history_position_changed'][0];
        $this->assertSame($firstTurn, $position['payload']['position_turn_no']);
        $this->assertSame($secondTurn, $position['payload']['selected_prompt_turn_no']);
        $this->assertSame($nextPrompt, $position['payload']['editor_prompt_text']);
        $this->assertArrayNotHasKey('protocol.error', $selectedTypes);
        $this->assertArrayNotHasKey('run.failed', $selectedTypes);
        $archive = (string) file_get_contents($this->tempDir.'/.hatfield/sessions/'.$run.'/events.jsonl');
        $this->assertStringNotContainsString('history_tail_discarded', $archive, 'Selecting an answer must not discard forward history.');
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-history-selection';
    }

    protected function replayFixtures(): array
    {
        return array_map(static fn (string $answer): array => [
            'fixture_source' => 'synthetic',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'stop_reason' => 'stop',
            'deltas' => [['type' => 'text', 'content' => $answer]],
        ], ['First final answer', 'Second final answer']);
    }
}
