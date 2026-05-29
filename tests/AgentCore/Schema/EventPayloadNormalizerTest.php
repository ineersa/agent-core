<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Schema;

use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventPayloadNormalizerTest extends TestCase
{
    private EventPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new EventPayloadNormalizer();
    }

    #[Test]
    public function denormalizeReturnsNullForIncompatibleSchemaVersion(): void
    {
        $payload = [
            'schema_version' => '0.99',
            'run_id' => 'test-run',
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [],
        ];

        $result = $this->normalizer->denormalizeRunEvent($payload);
        $this->assertNull($result, 'Incompatible schema version should return null');
    }

    #[Test]
    public function denormalizeReturnsNullForMissingRequiredFields(): void
    {
        $payload = [
            'schema_version' => '1.0',
            // missing run_id, seq, turn_no, type, payload
        ];

        $result = $this->normalizer->denormalizeRunEvent($payload);
        $this->assertNull($result, 'Missing required fields should return null');
    }

    #[Test]
    public function denormalizeThrowsOnInvalidTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timestamp');

        $payload = [
            'schema_version' => '1.0',
            'run_id' => 'test-run',
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [],
            'ts' => 'not-a-valid-timestamp',
        ];

        $this->normalizer->denormalizeRunEvent($payload);
    }

    #[Test]
    public function denormalizeSucceedsWithValidPayload(): void
    {
        $payload = [
            'schema_version' => '1.0',
            'run_id' => 'test-run',
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => ['prompt' => 'hello'],
        ];

        $event = $this->normalizer->denormalizeRunEvent($payload);
        $this->assertNotNull($event);
        $this->assertSame('test-run', $event->runId);
        $this->assertSame(1, $event->seq);
        $this->assertSame('run_started', $event->type);
        $this->assertSame('hello', $event->payload['prompt']);
    }

    #[Test]
    public function denormalizeAcceptsNoSchemaVersionWhenFieldsArePresent(): void
    {
        // Pre-schema events have no schema_version — still valid if fields are present.
        $payload = [
            'run_id' => 'legacy-run',
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [],
        ];

        $event = $this->normalizer->denormalizeRunEvent($payload);
        $this->assertNotNull($event);
        $this->assertSame('legacy-run', $event->runId);
    }

    #[Test]
    public function normalizeAndDenormalizeRoundTrip(): void
    {
        $normalizer = new EventPayloadNormalizer();

        $payload = [
            'run_id' => 'roundtrip-run',
            'seq' => 42,
            'turn_no' => 2,
            'type' => 'tool_result',
            'payload' => ['result' => 'ok'],
        ];

        $normalized = $normalizer->normalize(
            runId: 'roundtrip-run',
            seq: 42,
            turnNo: 2,
            type: 'tool_result',
            payload: ['result' => 'ok'],
        );

        $this->assertArrayHasKey('schema_version', $normalized);
        $this->assertSame('roundtrip-run', $normalized['run_id']);

        $denormalized = $normalizer->denormalizeRunEvent($normalized);
        $this->assertNotNull($denormalized);
        $this->assertSame('roundtrip-run', $denormalized->runId);
        $this->assertSame(42, $denormalized->seq);
        $this->assertSame(2, $denormalized->turnNo);
        $this->assertSame('tool_result', $denormalized->type);
        $this->assertSame('ok', $denormalized->payload['result']);
    }
}
