<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Serializer\SerializerInterface;

final class ToolCompletionRecipientRepositoryTest extends IsolatedKernelTestCase
{
    public function testUniqueRecipientProjectionRoundTripsAndRebuildsFromCanonicalReceiptFacts(): void
    {
        $container = self::getContainer();
        [$runId, , $reference] = ToolCompletionReceiptFixture::create($container);
        $repository = $container->get(ToolCompletionRecipientRepositoryInterface::class);
        $delivery = new ToolCompletionRecipientDTO($reference, 1, 77);
        $action = new RecordToolCompletionDeliveryDTO($delivery);
        $apply = $repository->prepareProjection($action);
        $this->assertNull($repository->findDelivery($runId, $reference->messageId));
        $apply();
        $apply();
        $this->assertEquals($delivery, $repository->findDelivery($runId, $reference->messageId));
        $serializer = $container->get(SerializerInterface::class);
        $canonicalPayload = $serializer->serialize($delivery, 'json');
        $decoded = $serializer->deserialize($canonicalPayload, ToolCompletionRecipientDTO::class, 'json');
        $this->assertEquals($delivery, $decoded);
        $other = new ToolCompletionRecipientDTO(new ToolCompletionDeliveryReferenceDTO('another-recipient', $reference->messageId, $reference->sourceEvent, $reference->payloadFingerprint), 2, 88);
        ($repository->prepareProjection(new RecordToolCompletionDeliveryDTO($other)))();
        $this->assertEquals($delivery, $repository->findDelivery($runId, $reference->messageId));
        $this->assertEquals($other, $repository->findDelivery('another-recipient', $reference->messageId));
    }

    #[DataProvider('dispositions')]
    public function testCapturedDispositionIsIdempotentAndCannotRegress(ToolCompletionDeliveryDispositionEnum $target): void
    {
        $container = self::getContainer();
        [$runId, , $reference] = ToolCompletionReceiptFixture::create($container);
        $repository = $container->get(ToolCompletionRecipientRepositoryInterface::class);
        $pending = new ToolCompletionRecipientDTO($reference, 1, 10);
        ($repository->prepareProjection(new RecordToolCompletionDeliveryDTO($pending)))();
        $next = new ToolCompletionRecipientDTO($reference, 1, 10, $target, 'captured_reason', ToolCompletionDeliveryDispositionEnum::Applied === $target ? 11 : null);
        $apply = $repository->prepareProjection(new RecordToolCompletionDeliveryDTO($next, ToolCompletionDeliveryDispositionEnum::Pending));
        $apply();
        $apply();
        $this->assertEquals($next, $repository->findDelivery($runId, $reference->messageId));
        $this->expectException(\RuntimeException::class);
        ($repository->prepareProjection(new RecordToolCompletionDeliveryDTO($pending)))();
    }

    public static function dispositions(): iterable
    {
        yield 'applied' => [ToolCompletionDeliveryDispositionEnum::Applied];
        yield 'parked' => [ToolCompletionDeliveryDispositionEnum::Parked];
        yield 'rejected' => [ToolCompletionDeliveryDispositionEnum::Rejected];
    }
}
