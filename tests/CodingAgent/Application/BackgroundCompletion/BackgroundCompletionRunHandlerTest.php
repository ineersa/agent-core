<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundCompletion;

use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionRunHandler;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\TestWith;

final class BackgroundCompletionRunHandlerTest extends IsolatedKernelTestCase
{
    #[TestWith(['bash'])]
    #[TestWith(['subagent'])]
    public function testFrozenOriginSurvivesForwardRetryCanonicalReplayAndConsumption(string $kind): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $f->append($f->child, 4, 1, 'agent_end', ['reason' => 'completed']);
        $child = $f->childState->with(['lastSeq' => 4, 'status' => RunStatus::Completed]);
        $origin = ['kind' => $kind, 'tool_call_id' => 'original-call'];
        $origin += 'bash' === $kind
            ? ['process_id' => 71, 'pid' => 1234]
            : ['lifecycle_id' => $f->lifecycle, 'children' => [['run_id' => 'grandchild', 'artifact_id' => 'original-artifact']]];
        $delivery = new DeliverBackgroundCompletionDTO($f->child, 'opaque-identity', 'Exact producer body', origin: $origin);
        $handler = $c->get(BackgroundCompletionRunHandler::class);
        $source = $handler->handle($delivery, $child);
        $this->commit($child, $source);
        $receipts = $c->get(BackgroundCompletionReceiptRepository::class);
        $this->assertSame($origin, $receipts->receipt($delivery->identity)->origin);
        $forward = $source->postCommitEffects[0];
        $this->assertInstanceOf(DeliverBackgroundCompletionDTO::class, $forward);
        $this->assertSame($f->parent, $forward->runId());
        $this->assertSame($f->child, $forward->sourceRunId);
        $this->assertSame($origin, $forward->origin);
        $this->assertStringEndsWith($delivery->text, $forward->text);

        // A resumed producer or transport retry cannot replace frozen attribution.
        $retry = $handler->handle(new DeliverBackgroundCompletionDTO($f->child, $delivery->identity, 'Different body', origin: ['kind' => 'other']), $child->with(['status' => RunStatus::Running]));
        $this->assertEquals($forward, $retry->postCommitEffects[0]);
        $accepted = $handler->handle($forward, $f->parentState);
        $this->commit($f->parentState, $accepted);
        $queued = $accepted->events[0];
        $this->assertSame('agent_command_queued', $queued->type);
        $translator = $c->get(RuntimeEventTranslator::class);
        $this->assertNull($translator->translate($queued), 'Presentation suppression must not prevent mailbox admission.');
        $this->assertCount(1, $c->get(CommandStoreInterface::class)->pending($f->parent));
        $resume = new SessionResumeMetadataProjection();
        $resume->observe($queued, $translator->translate($queued), true);
        $this->assertSame([], $resume->toArray()['queued_messages']);
        $advance = $c->get(AdvanceRunHandler::class)->handle(new AdvanceRun($f->parent, 1, 'boundary', 1, 'boundary'), $accepted->nextState->with(['pendingToolCalls' => [], 'activeStepId' => null]));
        $this->commit($accepted->nextState, $advance);
        $this->assertSame([], $c->get(CommandStoreInterface::class)->pending($f->parent));
        $receipt = $receipts->receipt($delivery->identity);
        $this->assertSame(BackgroundCompletionDispositionEnum::Consumed, $receipt->disposition);
        $this->assertSame($origin, $receipt->origin);
        $this->assertSame([], $handler->handle($delivery, $child)->events);

        $events = $c->get(SessionRunEventStore::class)->allFor($f->parent);
        $applied = array_find($events, static fn (RunEvent $event): bool => 'agent_command_applied' === $event->type && 'opaque-identity' === ($event->payload['idempotency_key'] ?? null));
        $this->assertInstanceOf(RunEvent::class, $applied);
        $this->assertSame('user', $applied->payload['message']['role']);
        $this->assertSame($forward->text, $applied->payload['text']);
        $runtime = $translator->translate($applied);
        $this->assertSame(RuntimeEventTypeEnum::ModelNotification->value, $runtime->type);
        $provenance = $runtime->payload['metadata']['background_completion'];
        $this->assertSame($f->child, $provenance['run_id']);
        $this->assertSame('opaque-identity', $provenance['identity']);
        foreach ($origin as $key => $value) {
            $this->assertSame($value, $provenance[$key]);
        }
        $projector = new TranscriptProjector($c->get('event_dispatcher'), new TranscriptProjectionState());
        $projector->accept($runtime);
        $block = $projector->blocks()[0];
        $this->assertSame(TranscriptBlockKindEnum::System, $block->kind);
        $this->assertSame($forward->text, $block->text);
        $this->assertSame($provenance, $block->meta['producer_metadata']['background_completion']);
        $replayed = $c->get(RunStateReducer::class)->replay(new RunState($f->parent, RunStatus::Running), $events);
        $message = $replayed->messages[array_key_last($replayed->messages)];
        $this->assertSame('user', $message->role);
        $this->assertSame($forward->text, $message->content[0]['text']);
        $this->assertSame($provenance, $message->metadata['background_completion']);
    }

    private function commit(RunState $state, HandlerResult $result): void
    {
        $c = self::getContainer();
        $c->get(PreparedTransitionEventStoreInterface::class)->appendTransition($result->events, ['run_id' => $state->runId, 'predecessor_seq' => $state->lastSeq, 'actions' => $result->postCommitActions]);
        $c->get(PendingTransitionRecovery::class)->recover($state->runId);
    }
}
