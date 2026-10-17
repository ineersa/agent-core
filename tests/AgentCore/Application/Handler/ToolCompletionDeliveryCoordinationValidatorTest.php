<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Pipeline\ReceiveToolCompletionHandler;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ToolCompletionDeliveryCoordinationValidatorTest extends IsolatedKernelTestCase
{
    #[DataProvider('invalidPlans')]
    public function testInvalidCapturedRecipientFailsBeforeJournal(string $mutation): void
    {
        $container = self::getContainer();
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container);
        $state = new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $source->seq);
        $container->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $prepared = $container->get(ReceiveToolCompletionHandler::class)->handle(new ReceiveToolCompletion($reference), $state);
        $record = $prepared->postCommitActions[0];
        $this->assertInstanceOf(RecordToolCompletionDeliveryDTO::class, $record);
        if ('duplicate' === $mutation) {
            $actions = [$record, $record];
        } elseif ('source_conflict' === $mutation) {
            $wrong = new ToolCompletionDeliveryReferenceDTO($runId, $reference->messageId, $reference->sourceEvent, str_repeat('f', 64));
            $actions = [new RecordToolCompletionDeliveryDTO(new ToolCompletionRecipientDTO($wrong, 1), receiptEventOrdinal: 0)];
        } else {
            $actions = [new RecordToolCompletionDeliveryDTO(new ToolCompletionRecipientDTO($reference, 1, 999))];
        }
        try {
            $container->get(RunCommit::class)->commit($state, $prepared->nextState, $prepared->events, postCommitActions: $actions);
            $this->fail('Invalid capture must fail before append.');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $this->assertNull($store->verifiedPendingTransition($runId));
        $this->assertSame($source->seq, $store->latestSequenceFor($runId));
        $this->assertNull($container->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($runId, $reference->messageId));
    }

    public static function invalidPlans(): iterable
    {
        yield 'source fingerprint conflict' => ['source_conflict'];
        yield 'duplicate row capture' => ['duplicate'];
        yield 'invented receipt location' => ['invented_location'];
    }
}
