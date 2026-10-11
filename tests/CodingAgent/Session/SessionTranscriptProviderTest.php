<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Session\SessionTranscriptProvider;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SessionTranscriptProvider::class)]
final class SessionTranscriptProviderTest extends IsolatedKernelTestCase
{
    private string $runId = 'transcript-provider-run';

    public function testTranscriptBlocksAtPositionExcludesDiscardedContent(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvanced(2, 1),
            $this->historyPositionSetEvent(3, 1, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, $this->assistantPayload('Answer A')),
            $this->turnAdvanced(5, 2),
            $this->historyPositionSetEvent(6, 2, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, $this->assistantPayload('Answer B discarded')),
            $this->historyPositionSetEvent(8, 1, 2, 'history_select'),
            $this->runEvent(RunEventTypeEnum::HistoryTailDiscarded->value, 9, 1, ['after_turn_no' => 1]),
            $this->turnAdvanced(10, 3),
            $this->historyPositionSetEvent(11, 3, 1, 'continue'),
            $this->runEvent('llm_step_completed', 12, 3, $this->assistantPayload('Answer C active')),
        ];

        $provider = $this->createProvider($events);
        $snapshot = $provider->transcriptAtPosition($this->runId, 3);
        $blocks = $snapshot->transcriptBlocks;

        $texts = array_map(static fn (TranscriptBlock $b): string => $b->text, $blocks);

        $this->assertNotEmpty($blocks, 'Retained tip should project transcript blocks');
        $joined = implode("\n", $texts);
        $this->assertTrue(
            str_contains($joined, 'Answer A') || str_contains($joined, 'Answer C active'),
            'Active history projection should include retained assistant text',
        );
        $this->assertStringNotContainsString('Answer B discarded', $joined);
    }

    public function testDisplayDoesNotReplayExecutionOnlyHumanResponseValidation(): void
    {
        $provider = $this->createProvider([
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvanced(2, 1),
            $this->runEvent('llm_step_completed', 3, 1, $this->assistantPayload('Historical display')),
            $this->runEvent('agent_command_applied', 4, 1, ['kind' => 'human_response', 'question_id' => '', 'answer' => 'old']),
        ]);
        $this->assertStringContainsString('Historical display', json_encode($provider->transcriptAtPosition($this->runId, 1)->transcriptBlocks, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function assistantPayload(string $text): array
    {
        return [
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
        ];
    }

    /** @param list<RunEvent> $events */
    private function createProvider(array $events): SessionTranscriptProvider
    {
        $container = static::getContainer();
        $path = $container->get(SessionRunEventStore::class)->historySource($this->runId)->path;
        $normalizer = $container->get(EventPayloadNormalizer::class);
        $bytes = '';
        foreach ($events as $event) {
            $bytes .= json_encode($normalizer->normalizeRunEvent($event), \JSON_THROW_ON_ERROR)."\n";
        }
        (new Filesystem())->dumpFile($path, $bytes);

        return $container->get(SessionTranscriptProvider::class);
    }

    /** @param array<string, mixed> $payload */
    private function runEvent(string $type, int $seq, int $turnNo, array $payload = []): RunEvent
    {
        return new RunEvent(runId: $this->runId, seq: $seq, turnNo: $turnNo, type: $type, payload: $payload);
    }

    private function turnAdvanced(int $seq, int $turnNo, ?int $previousTurnNo = null): RunEvent
    {
        $payload = ['turn_no' => $turnNo, 'step_id' => 'step-'.$turnNo];

        return new RunEvent(runId: $this->runId, seq: $seq, turnNo: $turnNo, type: RunEventTypeEnum::TurnAdvanced->value, payload: $payload);
    }

    private function historyPositionSetEvent(int $seq, int $turnNo, ?int $previousTurnNo, string $reason): RunEvent
    {
        $payload = [
            'position_turn_no' => $turnNo,
            'previous_position_turn_no' => $previousTurnNo,
            'reason' => $reason,
        ];

        return new RunEvent(runId: $this->runId, seq: $seq, turnNo: $turnNo, type: RunEventTypeEnum::HistoryPositionSet->value, payload: $payload);
    }
}
