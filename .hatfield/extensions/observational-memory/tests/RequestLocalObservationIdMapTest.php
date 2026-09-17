<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Compaction\DropObservationsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\RecordReflectionsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\RequestLocalObservationIdMap;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: request-local observation ids are assigned in timestamp/id order and mapped
 * back to canonical ids before reflection hashing and drop candidate accumulation.
 */
final class RequestLocalObservationIdMapTest extends TestCase
{
    public function testAssignsSequentialIdsInDeterministicOrder(): void
    {
        $map = RequestLocalObservationIdMap::forObservations([
            ['observation_id' => 'obs-b', 'timestamp' => '2026-01-02 00:00'],
            ['observation_id' => 'obs-a', 'timestamp' => '2026-01-01 00:00'],
        ]);

        $this->assertSame('1', $map->localId('obs-a'));
        $this->assertSame('2', $map->localId('obs-b'));
        $this->assertSame('obs-a', $map->canonicalId('1'));
        $this->assertSame('obs-b', $map->canonicalId('2'));
        $this->assertNull($map->canonicalId('999'));
    }

    public function testRecordReflectionsMapsLocalIdsBeforeHashing(): void
    {
        $map = RequestLocalObservationIdMap::forObservations([
            ['observation_id' => 'obs-a', 'timestamp' => '2026-01-01 00:00'],
            ['observation_id' => 'obs-b', 'timestamp' => '2026-01-02 00:00'],
        ]);
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            existingReflectionIds: [],
            observationIdMap: $map,
        );

        $result = $handler([
            'reflections' => [[
                'content' => 'Durable decision',
                'supporting_observation_ids' => ['2', '1'],
            ]],
        ]);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame(1, $result['added']);

        $expectedId = OmIdentity::reflectionId('run-1', 'v1', 'Durable decision', ['obs-a', 'obs-b']);
        $new = $handler->newReflections();
        $this->assertCount(1, $new);
        $this->assertSame($expectedId, $new[0]['reflection_id']);
        $this->assertSame(['obs-a', 'obs-b'], $new[0]['supporting_observation_ids']);
    }

    public function testDropObservationsMapsLocalIdsBeforeAccumulation(): void
    {
        $map = RequestLocalObservationIdMap::forObservations([
            ['observation_id' => 'obs-a', 'timestamp' => '2026-01-01 00:00'],
            ['observation_id' => 'obs-b', 'timestamp' => '2026-01-02 00:00'],
        ]);
        $handler = new DropObservationsToolHandler(
            observationIdMap: $map,
            maxDropsAllowed: 2,
        );

        $result = $handler(['ids' => ['2', 'missing', '1']]);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame(2, $result['added']);
        $this->assertSame(1, $result['missing']);
        $this->assertSame(['obs-b', 'obs-a'], $handler->proposedIds());
    }
}
