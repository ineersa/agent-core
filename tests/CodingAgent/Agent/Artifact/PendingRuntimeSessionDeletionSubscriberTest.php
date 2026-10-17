<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Application\Pipeline\ReceiveToolCompletionHandler;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Agent\Artifact\PendingRuntimeSessionDeletionSubscriber;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class PendingRuntimeSessionDeletionSubscriberTest extends IsolatedKernelTestCase
{
    public function testPermanentDeletionRemovesRecipientAndMailboxButKeepsForeignReceipt(): void
    {
        $container = self::getContainer();
        [$runId, $reference] = $this->acceptReceipt();
        [$other, $foreign] = $this->acceptReceipt();
        $recipients = $container->get(ToolCompletionRecipientRepositoryInterface::class);
        $container->get(PendingRuntimeSessionDeletionSubscriber::class)->onShutdown(new ControllerSessionShutdownEvent($runId));
        $this->assertNotNull($recipients->findDelivery($runId, $reference->messageId), 'Ordinary shutdown must retain dedupe evidence.');
        $container->get(HatfieldSessionStore::class)->deleteSession($runId);
        $this->assertNull($recipients->findDelivery($runId, $reference->messageId));
        $this->assertFalse($container->get(CommandStoreInterface::class)->has($runId, $reference->messageId));
        $this->assertNotNull($recipients->findDelivery($other, $foreign->messageId));
        $this->assertTrue($container->get(CommandStoreInterface::class)->has($other, $foreign->messageId));
    }

    /** @return array{string, ToolCompletionDeliveryReferenceDTO} */
    private function acceptReceipt(): array
    {
        $container = self::getContainer();
        [$runId, $event, $reference] = ToolCompletionReceiptFixture::create($container);
        $state = new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $event->seq);
        $container->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $prepared = $container->get(ReceiveToolCompletionHandler::class)->handle(new ReceiveToolCompletion($reference), $state);
        $container->get(RunCommit::class)->commit($state, $prepared->nextState, $prepared->events, postCommitActions: $prepared->postCommitActions);

        return [$runId, $reference];
    }
}
