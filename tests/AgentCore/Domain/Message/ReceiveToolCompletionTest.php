<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Message;

use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class ReceiveToolCompletionTest extends IsolatedKernelTestCase
{
    public function testConfiguredSerializerPreservesInternalReferenceAndOwnerIdentity(): void
    {
        $reference = new ToolCompletionDeliveryReferenceDTO('run', 'message', new RetainedToolCompletionSourceEventReference('run', 106, 2), str_repeat('a', 64));
        $message = new ReceiveToolCompletion($reference);
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $decoded = $serializer->deserialize($serializer->serialize($message, 'json'), ReceiveToolCompletion::class, 'json');
        $this->assertEquals($message, $decoded);
        $this->assertSame('run', $decoded->runId());
        $this->assertSame('message', $decoded->idempotencyKey());
        $this->assertSame(2, $decoded->turnNo());
    }
}
