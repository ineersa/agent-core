<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Notification;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: ModelNotificationCodec is the single owner of notification
 * boundary translation — details-array decoding and DTO→RunEvent-spec
 * encoding both preserve order and the canonical snake_case payload shape
 * with null optionals omitted (SKIP_NULL_VALUES).
 */
final class ModelNotificationCodecTest extends TestCase
{
    public function testDenormalizeFromDetailsExtractsAndOrdersNotifications(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::create()[0];

        $notifications = ModelNotificationCodec::denormalizeFromDetails($serializer, [
            'model_notifications' => [
                [
                    'id' => 'n-1',
                    'source' => 'output_cap',
                    'kind' => 'output_capped',
                    'severity' => 'warning',
                    'delivery' => 'append',
                    'text' => 'first',
                ],
                [
                    'id' => 'n-2',
                    'source' => 'safe_guard',
                    'kind' => 'info',
                    'severity' => 'low',
                    'delivery' => 'tool_result_replace',
                    'text' => 'second',
                    'tool_call_id' => 'call-1',
                    'tool_name' => 'bash',
                    'order_index' => 3,
                ],
            ],
        ]);

        $this->assertCount(2, $notifications);
        $this->assertSame('n-1', $notifications[0]->id);
        $this->assertSame('first', $notifications[0]->text);
        $this->assertSame('n-2', $notifications[1]->id);
        $this->assertSame('call-1', $notifications[1]->toolCallId);
        $this->assertSame(3, $notifications[1]->orderIndex);
    }

    public function testDenormalizeFromDetailsReturnsEmptyForMissingOrEmptyInput(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::create()[0];

        $this->assertSame([], ModelNotificationCodec::denormalizeFromDetails($serializer, null));
        $this->assertSame([], ModelNotificationCodec::denormalizeFromDetails($serializer, 'not-an-array'));
        $this->assertSame([], ModelNotificationCodec::denormalizeFromDetails($serializer, []));
        $this->assertSame([], ModelNotificationCodec::denormalizeFromDetails($serializer, ['model_notifications' => []]));
    }

    public function testToEventSpecsPreservesOrderAndCanonicalPayloadShape(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::create()[0];

        $specs = ModelNotificationCodec::toEventSpecs($serializer, [
            new ModelNotificationDTO(
                id: 'n-1',
                source: 'output_cap',
                kind: 'output_capped',
                severity: 'warning',
                delivery: 'append',
                text: 'first',
            ),
            new ModelNotificationDTO(
                id: 'n-2',
                source: 'safe_guard',
                kind: 'info',
                severity: 'low',
                delivery: 'tool_result_replace',
                text: 'second',
                toolCallId: 'call-1',
                toolName: 'bash',
                orderIndex: 3,
            ),
        ]);

        $this->assertCount(2, $specs);
        $this->assertSame(RunEventTypeEnum::ModelNotification->value, $specs[0]['type']);
        $this->assertSame('n-1', $specs[0]['payload']['id']);
        $this->assertSame('first', $specs[0]['payload']['text']);
        // Null optionals are omitted (SKIP_NULL_VALUES) but the empty
        // metadata default is preserved, matching the pre-consolidation
        // handler emission shape.
        $this->assertArrayNotHasKey('tool_call_id', $specs[0]['payload']);
        $this->assertSame([], $specs[0]['payload']['metadata']);

        $this->assertSame(RunEventTypeEnum::ModelNotification->value, $specs[1]['type']);
        $this->assertSame('n-2', $specs[1]['payload']['id']);
        $this->assertSame('call-1', $specs[1]['payload']['tool_call_id']);
        $this->assertSame(3, $specs[1]['payload']['order_index']);
    }

    public function testToEventSpecsReturnsEmptyForEmptyInput(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::create()[0];

        $this->assertSame([], ModelNotificationCodec::toEventSpecs($serializer, []));
    }
}
