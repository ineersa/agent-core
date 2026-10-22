<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\AgentCommunication;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\CompleteSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionCompletionHandler;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SubmissionCompletionHandlerTest extends IsolatedKernelTestCase
{
    public function testReceivedSettledReplyReleasesARecoveredTerminalChildGraph(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare(notice: true);
        $f->register($receipt);
        $active = $c->get(ActiveRunContextInterface::class);
        $active->loadRecovered($f->childState);
        $transport = $c->get('messenger.transport.run_control');
        $transport->reset();
        $bus = $c->get('agent.command.bus');
        $bus->dispatch(new AcceptSubmissionDTO($f->child, $receipt->identity), [new ReceivedStamp('run_control')]);
        $reply = array_find($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof CompleteSubmissionDTO);
        Assert::assertNotNull($reply);
        $bus->dispatch($reply->with(new ReceivedStamp('run_control')));
        Assert::assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($receipt->identity));
        $f->append($f->child, 5, 1, 'agent_end', ['reason' => 'completed']);
        $f->status($f->child, RunStatus::Completed);
        $active->release($f->child);
        $transport->reset();
        // Exercise owner initialization and completion through the received bus,
        // not a direct handler call that skips reconstruction of the child.
        $bus->dispatch($reply->with(new ReceivedStamp('run_control')));
        Assert::assertSame([], $transport->getSent());
        $this->expectException(RunContextNotLoadedException::class);
        $active->requireLoaded($f->child);
    }

    public function testReplyReusesNativeCompletionAndDuplicateDoesNotSendAgain(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare();
        $f->register($receipt);
        $c->get(ActiveRunContextInterface::class)->loadRecovered($f->parentState);
        $bus = $c->get('agent.command.bus');
        $bus->dispatch(new AcceptSubmissionDTO($f->parent, $receipt->identity), [new ReceivedStamp('run_control')]);
        $transport = $c->get('messenger.transport.run_control');
        $reply = $transport->getSent()[0];
        $transport->reset();
        $bus->dispatch($reply->with(new ReceivedStamp('run_control')));
        Assert::assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($receipt->identity));
        $sent = $c->get('messenger.transport.run_control')->getSent();
        Assert::assertCount(1, $sent);
        Assert::assertInstanceOf(ToolPayloadMessage::class, $sent[0]->getMessage());
        $c->get(SubmissionCompletionHandler::class)($reply->getMessage());
        Assert::assertCount(1, $c->get('messenger.transport.run_control')->getSent());
    }
}
