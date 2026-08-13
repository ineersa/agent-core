<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Notification;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: model notification wire shape (omit null optionals, snake_case tool fields)
 * round-trips through the shared DTO without changing historical payloads.
 * Decode→re-emit preserves exact original array rows; raw-sensitive predicates
 * match pre-typed type checks.
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

    public function testFromArrayToArrayPreservesExactWeirdHistoricalPayload(): void
    {
        $weird = [
            'text' => 'keep me',
            'id' => 42,
            'source' => 'legacy',
            'kind' => null,
            'delivery' => 'tool_result_replace',
            'unknown_top' => ['nested' => true],
            'tool_call_id' => 99,
            'tool_name' => false,
            'order_index' => 'not-int',
            'severity' => 'warning',
        ];

        $dto = ModelNotificationDTO::fromArray($weird);
        $this->assertSame($weird, $dto->toArray());
        $this->assertSame(array_keys($weird), array_keys($dto->toArray()));

        // Soft projection fields still cast/default.
        $this->assertSame('42', $dto->id);
        $this->assertSame('', $dto->kind);
        $this->assertSame('warning', $dto->severity);
        $this->assertNull($dto->toolCallId);
        $this->assertNull($dto->toolName);
        $this->assertNull($dto->orderIndex);
        $this->assertSame([], $dto->metadata);

        // Raw-sensitive predicates use original wire types.
        $this->assertFalse($dto->hasNonEmptyId()); // id is int, not string
        $this->assertTrue($dto->isToolResultReplace());
        $this->assertTrue($dto->hasNonEmptyText());

        // Caller input is not mutated (copy-on-write / no write-back).
        $this->assertSame(42, $weird['id']);
        $this->assertArrayNotHasKey('metadata', $weird);
    }

    public function testListFromMixedSkipsNonArrayRowsAndPreservesEachArrayUnchanged(): void
    {
        $rowA = [
            'id' => 'a',
            'source' => 's',
            'kind' => 'k',
            'severity' => 'info',
            'delivery' => 'x',
            'text' => 't',
            'extra' => 1,
        ];
        $rowB = [
            'delivery' => 'tool_result_replace',
            'text' => 'u',
            'id' => 'b',
            'source' => 's',
            'kind' => 'k',
            'severity' => 'info',
        ];

        $list = ModelNotificationDTO::listFromMixed([
            $rowA,
            'not-an-array',
            42,
            null,
            $rowB,
        ]);

        $this->assertCount(2, $list);
        $this->assertSame('a', $list[0]->id);
        $this->assertSame('b', $list[1]->id);
        $this->assertTrue($list[1]->isToolResultReplace());
        $this->assertSame([$rowA, $rowB], [$list[0]->toArray(), $list[1]->toArray()]);
    }

    public function testRawNonStringOrEmptyIdDoesNotQualify(): void
    {
        $this->assertFalse(ModelNotificationDTO::fromArray(['id' => 7, 'text' => 'x'])->hasNonEmptyId());
        $this->assertFalse(ModelNotificationDTO::fromArray(['id' => '', 'text' => 'x'])->hasNonEmptyId());
        $this->assertFalse(ModelNotificationDTO::fromArray(['id' => null, 'text' => 'x'])->hasNonEmptyId());
        $this->assertFalse(ModelNotificationDTO::fromArray(['text' => 'x'])->hasNonEmptyId());
        $this->assertTrue(ModelNotificationDTO::fromArray(['id' => 'ok', 'text' => 'x'])->hasNonEmptyId());

        // Direct producer construction uses typed fields.
        $direct = new ModelNotificationDTO(
            id: 'direct',
            source: 's',
            kind: 'k',
            severity: 'info',
            delivery: 'context_message',
            text: 't',
        );
        $this->assertTrue($direct->hasNonEmptyId());
        $this->assertSame([
            'id' => 'direct',
            'source' => 's',
            'kind' => 'k',
            'severity' => 'info',
            'delivery' => 'context_message',
            'text' => 't',
            'metadata' => [],
        ], $direct->toArray());
    }

    public function testRawSensitiveDeliveryAndTextPredicates(): void
    {
        $this->assertFalse(ModelNotificationDTO::fromArray([
            'delivery' => true,
            'text' => 'tool_result_replace',
        ])->isToolResultReplace());

        $this->assertFalse(ModelNotificationDTO::fromArray([
            'delivery' => 'tool_result_replace',
            'text' => '',
        ])->hasNonEmptyText());

        $this->assertFalse(ModelNotificationDTO::fromArray([
            'delivery' => 'tool_result_replace',
            'text' => 0,
        ])->hasNonEmptyText());

        $this->assertTrue(ModelNotificationDTO::fromArray([
            'delivery' => 'tool_result_replace',
            'text' => 'ok',
        ])->hasNonEmptyText());
    }
}
