<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\AgentCommunication;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Tests\Support\SubmissionFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SubmissionRegisteredSubscriberTest extends IsolatedKernelTestCase
{
    public function testDurableNativeRegistrationRequestsSourceOwnerAcceptanceOnly(): void
    {
        $c = self::getContainer();
        $f = new SubmissionFixture($c);
        $receipt = $f->prepare();
        $f->register($receipt);
        $correlation = $c->get(DeferredToolCompletionRepositoryInterface::class)->findByDeferredId($receipt->identity);
        $dispatcher = $c->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new DeferredToolCompletionRegisteredEvent($correlation));
        $dispatcher->dispatch(new DeferredToolCompletionRegisteredEvent($correlation));
        $sent = $c->get('messenger.transport.run_control')->getSent();
        Assert::assertCount(2, $sent);
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            Assert::assertInstanceOf(AcceptSubmissionDTO::class, $message);
            Assert::assertSame($receipt->identity, $message->identity);
            Assert::assertSame($f->parent, $message->runId());
        }
    }
}
