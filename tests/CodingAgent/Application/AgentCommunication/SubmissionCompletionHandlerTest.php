<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\AgentCommunication;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionCompletionHandler;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SubmissionCompletionHandlerTest extends IsolatedKernelTestCase
{
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
