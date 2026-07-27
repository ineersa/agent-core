<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Compaction\RecordReflectionsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: complete-generation record_reflections last-valid-wins, invalid calls
 * do not mutate candidate, retain/new ids are allowlisted, no replacement_text.
 */
final class RecordReflectionsToolHandlerTest extends TestCase
{
    public function testLastValidCallWinsAndInvalidDoesNotMutate(): void
    {
        $priorId = OmIdentity::reflectionId('run-1', 'v1', 'Prior durable fact', ['obs-a']);
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            allowedReflectionIds: [$priorId => true],
            allowedObservationIds: ['obs-a' => true, 'obs-b' => true],
            activeReflectionsById: [
                $priorId => [
                    'reflection_id' => $priorId,
                    'content' => 'Prior durable fact',
                    'supporting_observation_ids' => ['obs-a'],
                    'supporting_observation_ids_json' => '["obs-a"]',
                    'token_count' => 4,
                ],
            ],
            requireNonEmptyOutput: true,
        );

        $first = $handler([
            'reflections' => [
                ['retain_id' => $priorId],
                [
                    'content' => 'New durable decision',
                    'supporting_observation_ids' => ['obs-b', 'obs-a'],
                ],
            ],
            'retained_observation_ids' => ['obs-b'],
        ]);
        $this->assertSame('accepted', $first['status']);
        $this->assertTrue($handler->hasCandidate());
        $this->assertCount(2, $handler->reflections());
        $this->assertSame(['obs-b'], $handler->retainedObservationIds());

        $invalid = $handler([
            'reflections' => [
                ['content' => "line1\nline2", 'supporting_observation_ids' => ['obs-a']],
            ],
            'retained_observation_ids' => ['obs-a'],
        ]);
        $this->assertSame('rejected', $invalid['status']);
        $this->assertCount(2, $handler->reflections(), 'invalid call must not mutate candidate');
        $this->assertSame(['obs-b'], $handler->retainedObservationIds());

        $second = $handler([
            'reflections' => [
                [
                    'content' => 'Only new reflection remains',
                    'supporting_observation_ids' => ['obs-a'],
                ],
            ],
            'retained_observation_ids' => ['obs-a', 'obs-b'],
        ]);
        $this->assertSame('accepted', $second['status']);
        $this->assertCount(1, $handler->reflections());
        $this->assertSame(['obs-a', 'obs-b'], $handler->retainedObservationIds());
        $this->assertSame(
            OmIdentity::reflectionId('run-1', 'v1', 'Only new reflection remains', ['obs-a']),
            $handler->reflections()[0]['reflection_id'],
        );
    }

    public function testEmptyBothArraysRejectedWhenActiveMemoryRequired(): void
    {
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            allowedReflectionIds: [],
            allowedObservationIds: ['obs-a' => true],
            activeReflectionsById: [],
            requireNonEmptyOutput: true,
        );

        $rejected = $handler([
            'reflections' => [],
            'retained_observation_ids' => [],
        ]);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('empty_generation', $rejected['error']);
        $this->assertFalse($handler->hasCandidate());
    }

    public function testUnknownRetainAndSupportRejected(): void
    {
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            allowedReflectionIds: [],
            allowedObservationIds: ['obs-a' => true],
            activeReflectionsById: [],
            requireNonEmptyOutput: true,
        );

        $badRetain = $handler([
            'reflections' => [['retain_id' => 'missing-reflection']],
            'retained_observation_ids' => [],
        ]);
        $this->assertSame('rejected', $badRetain['status']);
        $this->assertFalse($handler->hasCandidate());

        $badSupport = $handler([
            'reflections' => [
                ['content' => 'Fact', 'supporting_observation_ids' => ['missing']],
            ],
            'retained_observation_ids' => [],
        ]);
        $this->assertSame('rejected', $badSupport['status']);
        $this->assertFalse($handler->hasCandidate());
    }

    public function testPrivacyAcceptsJwtFactAndRejectsCredentialAssignment(): void
    {
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            allowedReflectionIds: [],
            allowedObservationIds: ['obs-a' => true],
            activeReflectionsById: [],
            requireNonEmptyOutput: true,
        );

        $jwt = $handler([
            'reflections' => [[
                'content' => 'Service uses JWT tokens for API authentication',
                'supporting_observation_ids' => ['obs-a'],
            ]],
            'retained_observation_ids' => ['obs-a'],
        ]);
        $this->assertSame('accepted', $jwt['status'], 'technical JWT fact must not be privacy-rejected');
        $this->assertTrue($handler->hasCandidate());

        $handler2 = new RecordReflectionsToolHandler(
            runId: 'run-1',
            reflectorSchemaVersion: 'v1',
            allowedReflectionIds: [],
            allowedObservationIds: ['obs-a' => true],
            activeReflectionsById: [],
            requireNonEmptyOutput: true,
        );
        $secret = $handler2([
            'reflections' => [[
                'content' => 'api_key=sk-live-should-not-be-stored',
                'supporting_observation_ids' => ['obs-a'],
            ]],
            'retained_observation_ids' => ['obs-a'],
        ]);
        $this->assertSame('rejected', $secret['status']);
        $this->assertFalse($handler2->hasCandidate());
    }
}
