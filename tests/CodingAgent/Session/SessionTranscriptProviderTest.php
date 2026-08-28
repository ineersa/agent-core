<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use Ineersa\CodingAgent\Session\SessionTranscriptProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(SessionTranscriptProvider::class)]
final class SessionTranscriptProviderTest extends TestCase
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
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('allFor')->willReturn($events);

        $projector = new HistoryProjector();
        $replayFilter = new HistoryReplayFilter($projector);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $translator = new RuntimeEventTranslator($eventDispatcher, new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $eventMapper = new RuntimeEventMapper($translator);

        $dispatcher = new EventDispatcher();
        $projectionState = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $transcriptProjector = new TranscriptProjector($dispatcher, $projectionState);

        return new SessionTranscriptProvider($store, $replayFilter, $eventMapper, $transcriptProjector);
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
