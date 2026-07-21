<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\Replay\TurnTreeReplayFilter;
use Ineersa\CodingAgent\Session\SessionTranscriptProvider;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(SessionTranscriptProvider::class)]
final class SessionTranscriptProviderTest extends TestCase
{
    private string $runId = 'transcript-provider-run';

    public function testTranscriptBlocksForLeafExcludesAbandonedBranchContent(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvanced(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Answer A']),
            $this->turnAdvanced(5, 2, 1),
            $this->leafSetEvent(6, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, ['text' => 'Answer B abandoned']),
            $this->leafSetEvent(8, 1, 2, null, 'rewind'),
            $this->turnAdvanced(9, 3, 1),
            $this->leafSetEvent(10, 3, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 11, 3, ['text' => 'Answer C active']),
        ];

        $provider = $this->createProvider($events);
        $snapshot = $provider->transcriptForLeaf($this->runId, 3);
        $blocks = $snapshot->transcriptBlocks;

        $texts = array_map(static fn (TranscriptBlock $b): string => $b->text, $blocks);

        $this->assertNotEmpty($blocks, 'Active leaf should project transcript blocks');
        $joined = implode("\n", $texts);
        $this->assertTrue(
            str_contains($joined, 'Answer A') || str_contains($joined, 'Answer C active'),
            'Active leaf projection should include active-path assistant text',
        );
        $this->assertStringNotContainsString('Answer B abandoned', $joined);
    }

    /** @param list<RunEvent> $events */
    private function createProvider(array $events): SessionTranscriptProvider
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('allFor')->willReturn($events);

        $projector = new TurnTreeProjector();
        $replayFilter = new TurnTreeReplayFilter($projector);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $translator = new RuntimeEventTranslator($eventDispatcher);
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

    private function turnAdvanced(int $seq, int $turnNo, ?int $parentTurnNo): RunEvent
    {
        $payload = ['turn_no' => $turnNo, 'step_id' => 'step-'.$turnNo];
        if (null !== $parentTurnNo) {
            $payload['parent_turn_no'] = $parentTurnNo;
        }

        return new RunEvent(runId: $this->runId, seq: $seq, turnNo: $turnNo, type: RunEventTypeEnum::TurnAdvanced->value, payload: $payload);
    }

    private function leafSetEvent(int $seq, int $turnNo, ?int $previousTurnNo, ?int $parentTurnNo, string $reason): RunEvent
    {
        $payload = ['turn_no' => $turnNo, 'reason' => $reason];
        if (null !== $previousTurnNo) {
            $payload['previous_turn_no'] = $previousTurnNo;
        }
        if (null !== $parentTurnNo) {
            $payload['parent_turn_no'] = $parentTurnNo;
        }

        return new RunEvent(runId: $this->runId, seq: $seq, turnNo: $turnNo, type: RunEventTypeEnum::LeafSet->value, payload: $payload);
    }
}
