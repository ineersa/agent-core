<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\AgentCommunication;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\CompleteSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\DeliverSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionReceiptRepository;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SubmissionHandlerTest extends IsolatedKernelTestCase
{
    public function testConfiguredOwnerEntryCommitsReceiptBeforeQueuingNativeCompletion(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare();
        $f->register($receipt);
        $active = $c->get(ActiveRunContextInterface::class);
        $active->loadRecovered($f->parentState);
        $c->get('agent.command.bus')->dispatch(new AcceptSubmissionDTO($f->parent, $receipt->identity), [new ReceivedStamp('run_control')]);
        Assert::assertSame(4, $c->get(EventStoreInterface::class)->latestSequenceFor($f->parent));
        Assert::assertEquals($receipt, $c->get(SubmissionReceiptRepository::class)->receipt($f->parent, $receipt->identity));
        Assert::assertSame($f->parentState->pendingToolCalls, $active->requireLoaded($f->parent)->pendingToolCalls);
        $sent = $c->get('messenger.transport.run_control')->getSent();
        Assert::assertCount(2, $sent);
        Assert::assertInstanceOf(CompleteSubmissionDTO::class, $sent[0]->getMessage());
        $delivery = $sent[1]->getMessage();
        Assert::assertInstanceOf(DeliverSubmissionDTO::class, $delivery);
        $active->loadRecovered($f->childState);
        $c->get('agent.command.bus')->dispatch($delivery, [new ReceivedStamp('run_control')]);
        Assert::assertSame(4, $c->get(EventStoreInterface::class)->latestSequenceFor($f->child));
        Assert::assertSame($f->childState->pendingToolCalls, $active->requireLoaded($f->child)->pendingToolCalls);
    }
}
