<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Notification;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Thesis: ModelNotificationDTO hydrates via Symfony Serializer with snake_case
 * optional tool fields and omits null optionals under SKIP_NULL_VALUES.
 */
final class ModelNotificationDTOTest extends TestCase
{
    public function testSerializerRoundTripCanonicalSnakeCaseAndSkipNull(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::denormalizer();

        $dto = new ModelNotificationDTO(
            id: 'nid-1',
            source: 'output_cap',
            kind: 'output_capped',
            severity: 'warning',
            delivery: 'tool_result_replace',
            text: 'capped text',
            metadata: ['cap' => 100],
            toolCallId: 'call-1',
            toolName: 'read',
            orderIndex: 2,
        );

        /** @var array<string, mixed> $wire */
        $wire = $serializer->normalize($dto, null, [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        $this->assertSame([
            'id' => 'nid-1',
            'source' => 'output_cap',
            'kind' => 'output_capped',
            'severity' => 'warning',
            'delivery' => 'tool_result_replace',
            'text' => 'capped text',
            'metadata' => ['cap' => 100],
            'tool_call_id' => 'call-1',
            'tool_name' => 'read',
            'order_index' => 2,
        ], $wire);

        $restored = $serializer->denormalize($wire, ModelNotificationDTO::class);
        $this->assertInstanceOf(ModelNotificationDTO::class, $restored);
        $this->assertSame('nid-1', $restored->id);
        $this->assertSame('call-1', $restored->toolCallId);
        $this->assertSame(2, $restored->orderIndex);

        $minimal = new ModelNotificationDTO(
            id: 'nid-min',
            source: 'system',
            kind: 'info',
            severity: 'info',
            delivery: 'context_message',
            text: 'hello',
        );
        /** @var array<string, mixed> $minimalWire */
        $minimalWire = $serializer->normalize($minimal, null, [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);
        $this->assertSame([
            'id' => 'nid-min',
            'source' => 'system',
            'kind' => 'info',
            'severity' => 'info',
            'delivery' => 'context_message',
            'text' => 'hello',
            'metadata' => [],
        ], $minimalWire);
        $this->assertArrayNotHasKey('tool_call_id', $minimalWire);
    }

    public function testMalformedRequiredFieldTypeThrows(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::denormalizer();

        $this->expectException(SerializerExceptionInterface::class);
        $serializer->denormalize([
            'id' => 42,
            'source' => 's',
            'kind' => 'k',
            'severity' => 'info',
            'delivery' => 'tool_result_replace',
            'text' => 'x',
        ], ModelNotificationDTO::class);
    }
}
