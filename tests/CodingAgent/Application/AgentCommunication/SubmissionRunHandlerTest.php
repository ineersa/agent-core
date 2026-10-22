<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionReceiptRepository;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionRunHandler;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SubmissionRunHandlerTest extends IsolatedKernelTestCase
{
    public function testCompletedRefusalRedeliveryDoesNotDisturbLaterSourceWork(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare();
        $f->register($receipt);
        $f->status($f->child, RunStatus::Cancelled);
        $active = $c->get(ActiveRunContextInterface::class);
        $active->loadRecovered($f->parentState);
        $bus = $c->get('agent.command.bus');
        $transport = $c->get('messenger.transport.run_control');
        $transport->reset();
        $message = new AcceptSubmissionDTO($f->parent, $receipt->identity);
        $bus->dispatch($message, [new ReceivedStamp('run_control')]);
        $reply = $transport->getSent()[0];
        Assert::assertTrue($reply->getMessage()->completion->isError);
        $bus->dispatch($reply->with(new ReceivedStamp('run_control')));
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        Assert::assertSame('completed', $deferred->status($receipt->identity));
        Assert::assertNull($deferred->findByDeferredId($receipt->identity));
        Assert::assertNull($c->get(SubmissionReceiptRepository::class)->receipt($f->parent, $receipt->identity));
        $f->append($f->parent, 4, 8, 'turn_advanced', ['turn_no' => 8, 'step_id' => 'later-step']);
        $f->append($f->parent, 5, 8, 'llm_step_completed', ['step_id' => 'later-step', 'assistant_message' => ['tool_calls' => [['id' => 'unrelated']]]]);
        $later = $f->parentState->with(['lastSeq' => 5, 'turnNo' => 8, 'activeStepId' => 'later-step', 'pendingToolCalls' => ['unrelated' => false]]);
        $active->loadRecovered($later);
        $seq = $c->get(EventStoreInterface::class)->latestSequenceFor($f->parent);
        $transport->reset();
        $bus->dispatch($message, [new ReceivedStamp('run_control')]);
        Assert::assertSame($later, $active->requireLoaded($f->parent));
        Assert::assertSame($seq, $c->get(EventStoreInterface::class)->latestSequenceFor($f->parent));
        Assert::assertSame([], $transport->getSent());
        $this->expectException(\RuntimeException::class);
        $c->get(SubmissionRunHandler::class)->handle(new AcceptSubmissionDTO($f->child, $receipt->identity), $f->childState);
    }

    public function testOwnerAcceptanceIsRecoverableAndDuplicateKeepsOriginalReceipt(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare();
        $f->register($receipt);
        $message = new AcceptSubmissionDTO($f->parent, $receipt->identity);
        $handler = $c->get(SubmissionRunHandler::class);
        $result = $handler->handle($message, $f->parentState);
        Assert::assertCount(1, $result->events);
        Assert::assertSame($receipt->lifecycleId, $result->events[0]->payload['submission_receipt']['lifecycle_id']);
        Assert::assertSame($f->parentState->pendingToolCalls, $result->nextState->pendingToolCalls);
        $repo = $c->get(SubmissionReceiptRepository::class);
        Assert::assertNull($repo->receipt($f->parent, $receipt->identity));
        $store = $c->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition($result->events, ['run_id' => $f->parent, 'predecessor_seq' => 3, 'actions' => $result->postCommitActions]);
        // Simulate a stopped owner after physical append but before metadata
        // finalization. Shared recovery, not a new submission journal, finishes it.
        Assert::assertNull($repo->receipt($f->parent, $receipt->identity));
        $c->get(PendingTransitionRecovery::class)->recover($f->parent);
        Assert::assertEquals($receipt, $repo->receipt($f->parent, $receipt->identity));
        $source = $c->get(RunHistorySourceProviderInterface::class)->historySource($f->parent);
        (new Filesystem())->remove(\dirname($source->path).'/history-index.sqlite');
        Assert::assertEquals($receipt, $repo->receipt($f->parent, $receipt->identity));
        Assert::assertSame(4, $store->latestSequenceFor($f->parent));
        $duplicate = $handler->handle($message, $f->parentState->with(['turnNo' => 8, 'pendingToolCalls' => []]));
        Assert::assertSame([], $duplicate->events);
        Assert::assertSame([], $duplicate->postCommitActions);
        $completion = $duplicate->postCommitEffects[0]->completion;
        Assert::assertInstanceOf(CompleteDeferredToolCall::class, $completion);
        Assert::assertSame('source_accepted', $completion->details['status']);
        Assert::assertStringContainsString('does not confirm receiver delivery or attention', $completion->content[0]['text']);
    }

    public function testStaleSourceIsRefusedWithoutAReceiptOrDestinationEffect(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare();
        $f->register($receipt);
        $result = $c->get(SubmissionRunHandler::class)->handle(new AcceptSubmissionDTO($f->parent, $receipt->identity), $f->parentState->with(['pendingToolCalls' => []]));
        Assert::assertSame([], $result->events);
        Assert::assertSame([], $result->postCommitActions);
        Assert::assertTrue($result->postCommitEffects[0]->completion->isError);
        Assert::assertNull($c->get(SubmissionReceiptRepository::class)->receipt($f->parent, $receipt->identity));
    }

    public function testDelayedSubmissionCannotBecomeGuidanceForALaterResume(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare();
        $f->register($receipt);
        $c->get(DeferredSubagentBatchRepository::class)->reserveBatch('later-resume', $f->parent, 1, 'resume-call', 0, new \DateTimeImmutable('+1 hour'), new DeferredSubagentBatchChildIntentDTO(1, $f->child, $f->artifact, 'scout', 'new task', 'model', 'medium'));
        $f->append($f->parent, 4, 1, 'llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'resume-call'], ['id' => 'steer']]]]);
        $result = $c->get(SubmissionRunHandler::class)->handle(new AcceptSubmissionDTO($f->parent, $receipt->identity), $f->parentState);
        Assert::assertSame([], $result->events);
        Assert::assertTrue($result->postCommitEffects[0]->completion->isError);
        Assert::assertStringContainsString('activity changed', $result->postCommitEffects[0]->completion->content[0]['text']);
    }
}
