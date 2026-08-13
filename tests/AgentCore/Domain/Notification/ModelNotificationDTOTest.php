<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Notification;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: model notification wire shape (omit null optionals, snake_case tool fields)
 * round-trips through the shared DTO without changing historical payloads.
 */
final class ModelNotificationDTOTest extends TestCase
{
    public function testFullNotificationRoundTripsExactWireShape(): void
    {
        $dto = new ModelNotificationDTO(
            id: 'nid-1',
            source: 'output_cap',
            kind: 'output_capped',
            severity: 'warning',
            delivery: 'tool_result_replace',
            text: 'capped text',
            toolCallId: 'call-1',
            toolName: 'read',
            orderIndex: 2,
            metadata: ['cap' => 100, 'saved_path' => '/tmp/x'],
        );

        $wire = $dto->toArray();
        $this->assertSame([
            'id' => 'nid-1',
            'source' => 'output_cap',
            'kind' => 'output_capped',
            'severity' => 'warning',
            'delivery' => 'tool_result_replace',
            'text' => 'capped text',
            'metadata' => ['cap' => 100, 'saved_path' => '/tmp/x'],
            'tool_call_id' => 'call-1',
            'tool_name' => 'read',
            'order_index' => 2,
        ], $wire);

        $restored = ModelNotificationDTO::fromArray($wire);
        $this->assertSame($wire, $restored->toArray());
        $this->assertTrue($restored->isToolResultReplace());
        $this->assertTrue($restored->hasNonEmptyId());
        $this->assertTrue($restored->hasNonEmptyText());
    }

    public function testMinimalHistoricalPayloadOmitsOptionalToolFields(): void
    {
        $historical = [
            'id' => 'nid-min',
            'source' => 'system',
            'kind' => 'info',
            'severity' => 'info',
            'delivery' => 'context_message',
            'text' => 'hello',
            'metadata' => [],
        ];

        $dto = ModelNotificationDTO::fromArray($historical);
        $this->assertNull($dto->toolCallId);
        $this->assertNull($dto->toolName);
        $this->assertNull($dto->orderIndex);
        $this->assertSame($historical, $dto->toArray());
    }

    public function testProjectionDefaultsMatchHistoricalCasts(): void
    {
        $dto = ModelNotificationDTO::fromArray([]);
        $this->assertSame('', $dto->id);
        $this->assertSame('', $dto->source);
        $this->assertSame('', $dto->kind);
        $this->assertSame('info', $dto->severity);
        $this->assertSame('', $dto->delivery);
        $this->assertSame('', $dto->text);
        $this->assertSame([], $dto->metadata);
        $this->assertNull($dto->toolCallId);
    }

    public function testListFromMixedSkipsNonArrayRows(): void
    {
        $list = ModelNotificationDTO::listFromMixed([
            ['id' => 'a', 'source' => 's', 'kind' => 'k', 'severity' => 'info', 'delivery' => 'x', 'text' => 't', 'metadata' => []],
            'not-an-array',
            42,
            null,
            ['id' => 'b', 'source' => 's', 'kind' => 'k', 'severity' => 'info', 'delivery' => 'tool_result_replace', 'text' => 'u', 'metadata' => []],
        ]);

        $this->assertCount(2, $list);
        $this->assertSame('a', $list[0]->id);
        $this->assertSame('b', $list[1]->id);
        $this->assertTrue($list[1]->isToolResultReplace());
        $this->assertSame([
            [
                'id' => 'a',
                'source' => 's',
                'kind' => 'k',
                'severity' => 'info',
                'delivery' => 'x',
                'text' => 't',
                'metadata' => [],
            ],
            [
                'id' => 'b',
                'source' => 's',
                'kind' => 'k',
                'severity' => 'info',
                'delivery' => 'tool_result_replace',
                'text' => 'u',
                'metadata' => [],
            ],
        ], ModelNotificationDTO::listToArrays($list));
    }
}
