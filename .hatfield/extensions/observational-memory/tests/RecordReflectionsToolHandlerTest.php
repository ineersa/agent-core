<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Compaction\RecordReflectionsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: delta Reflector accumulates/dedupes new reflections; cannot prune or retain_id.
 */
final class RecordReflectionsToolHandlerTest extends TestCase
{
    public function testAccumulateDedupeAndSkipExistingIds(): void
    {
        $priorId = OmIdentity::reflectionId('run-1', 'v1', 'Prior durable fact', ['obs-a']);
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            existingReflectionIds: [$priorId => true],
            allowedObservationIds: ['obs-a' => true, 'obs-b' => true],
        );

        $first = $handler([
            'reflections' => [
                [
                    'content' => 'New durable decision',
                    'supporting_observation_ids' => ['obs-b', 'obs-a'],
                ],
            ],
        ]);
        $this->assertSame('accepted', $first['status']);
        $this->assertSame(1, $first['added']);
        $this->assertCount(1, $handler->newReflections());

        $second = $handler([
            'reflections' => [
                [
                    'content' => 'New durable decision',
                    'supporting_observation_ids' => ['obs-a', 'obs-b'],
                ],
                [
                    'content' => 'Second durable fact',
                    'supporting_observation_ids' => ['obs-a'],
                ],
            ],
        ]);
        $this->assertSame('accepted', $second['status']);
        $this->assertSame(1, $second['duplicates'], 'same content+support must dedupe');
        $this->assertSame(1, $second['added']);
        $this->assertCount(2, $handler->newReflections());

        $priorDup = $handler([
            'reflections' => [
                [
                    'content' => 'Prior durable fact',
                    'supporting_observation_ids' => ['obs-a'],
                ],
            ],
        ]);
        $this->assertSame(1, $priorDup['duplicates']);
        $this->assertSame(0, $priorDup['added']);
        $this->assertCount(2, $handler->newReflections());
    }

    public function testZeroCallLeavesEmptyAndRejectsRetainIdOrRetainedObs(): void
    {
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            existingReflectionIds: [],
            allowedObservationIds: ['obs-a' => true],
        );

        $this->assertSame([], $handler->newReflections());

        $retain = $handler([
            'reflections' => [
                ['retain_id' => 'anything'],
            ],
        ]);
        $this->assertSame('accepted', $retain['status']);
        $this->assertSame(1, $retain['rejected']);
        $this->assertSame([], $handler->newReflections());

        $withRetained = $handler([
            'reflections' => [
                [
                    'content' => 'Fact',
                    'supporting_observation_ids' => ['obs-a'],
                    'retained_observation_ids' => ['obs-a'],
                ],
            ],
        ]);
        $this->assertSame(1, $withRetained['rejected']);
        $this->assertSame([], $handler->newReflections());
    }

    public function testUnknownSupportRejectedAndPrivacyRules(): void
    {
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            existingReflectionIds: [],
            allowedObservationIds: ['obs-a' => true],
        );

        $bad = $handler([
            'reflections' => [
                ['content' => 'Fact', 'supporting_observation_ids' => ['missing']],
            ],
        ]);
        $this->assertSame(1, $bad['rejected']);
        $this->assertSame([], $handler->newReflections());

        $jwt = $handler([
            'reflections' => [[
                'content' => 'Service uses JWT tokens for API authentication',
                'supporting_observation_ids' => ['obs-a'],
            ]],
        ]);
        $this->assertSame(1, $jwt['added']);

        $handler2 = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            existingReflectionIds: [],
            allowedObservationIds: ['obs-a' => true],
        );
        $secret = $handler2([
            'reflections' => [[
                'content' => 'api_key=sk-live-should-not-be-stored',
                'supporting_observation_ids' => ['obs-a'],
            ]],
        ]);
        $this->assertSame(1, $secret['rejected']);
        $this->assertSame([], $handler2->newReflections());
    }
}
