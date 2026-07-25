<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Compaction\RecordReflectionsToolHandler;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: record_reflections validates support ids/budgets and is first-valid-wins.
 */
final class RecordReflectionsToolHandlerTest extends TestCase
{
    public function testAcceptsValidPayloadAndRejectsSecondCallAndBadSupport(): void
    {
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            requestId: 'req-1',
            reflectorSchemaVersion: 'v1',
            compressionLevel: 0,
            allowedObservationIds: ['obs-a' => true, 'obs-b' => true],
            maxReflections: 2,
            reflectionContentMaxChars: 100,
            replacementMaxChars: 200,
            reflectionsMaxTokens: 10_000,
        );

        $ok = $handler([
            'replacement_text' => 'Summary text',
            'reflections' => [
                [
                    'content' => 'Decision A',
                    'supporting_observation_ids' => ['obs-a'],
                    'compression_level' => 0,
                ],
                [
                    'content' => 'Decision B',
                    'supporting_observation_ids' => ['obs-b', 'obs-a'],
                    'compression_level' => 0,
                ],
            ],
        ]);
        $this->assertIsArray($ok);
        $this->assertSame('accepted', $ok['status']);
        $this->assertTrue($handler->hasRecorded());
        $this->assertCount(2, $handler->reflections());

        $second = $handler([
            'replacement_text' => 'Other',
            'reflections' => [],
        ]);
        $this->assertSame('already_recorded', $second['error']);

        $bad = new RecordReflectionsToolHandler(
            runId: 'run-1',
            requestId: 'req-2',
            reflectorSchemaVersion: 'v1',
            compressionLevel: 0,
            allowedObservationIds: ['obs-a' => true],
            maxReflections: 2,
            reflectionContentMaxChars: 100,
            replacementMaxChars: 200,
            reflectionsMaxTokens: 10_000,
        );
        $rejected = $bad([
            'replacement_text' => 'Summary',
            'reflections' => [
                [
                    'content' => 'Bad support',
                    'supporting_observation_ids' => ['missing'],
                    'compression_level' => 0,
                ],
            ],
        ]);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertFalse($bad->hasRecorded());

        $levelMismatch = new RecordReflectionsToolHandler(
            runId: 'run-1',
            requestId: 'req-3',
            reflectorSchemaVersion: 'v1',
            compressionLevel: 1,
            allowedObservationIds: ['obs-a' => true],
            maxReflections: 2,
            reflectionContentMaxChars: 100,
            replacementMaxChars: 200,
            reflectionsMaxTokens: 10_000,
        );
        $levelRejected = $levelMismatch([
            'replacement_text' => 'Summary',
            'reflections' => [
                [
                    'content' => 'Wrong level',
                    'supporting_observation_ids' => ['obs-a'],
                    'compression_level' => 0,
                ],
            ],
        ]);
        $this->assertSame('rejected', $levelRejected['status']);
        $this->assertSame('compression_level_mismatch', $levelRejected['error']);
        $this->assertFalse($levelMismatch->hasRecorded());
    }

    public function testEmptyReflectionsRejectedWithoutStateMutation(): void
    {
        $handler = new RecordReflectionsToolHandler(
            runId: 'run-1',
            requestId: 'req-empty',
            reflectorSchemaVersion: 'v1',
            compressionLevel: 0,
            allowedObservationIds: ['obs-a' => true],
            maxReflections: 2,
            reflectionContentMaxChars: 100,
            replacementMaxChars: 200,
            reflectionsMaxTokens: 10_000,
        );

        $rejected = $handler([
            'replacement_text' => 'Summary only is not enough',
            'reflections' => [],
        ]);

        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('empty_reflections', $rejected['error']);
        $this->assertFalse($handler->hasRecorded());
        $this->assertNull($handler->replacementText());
        $this->assertSame([], $handler->reflections());
    }
}
