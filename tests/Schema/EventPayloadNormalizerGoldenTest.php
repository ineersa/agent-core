<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Schema;

use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventNameMap;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventPayloadNormalizerGoldenTest extends TestCase
{
    private const string RUN_ID = 'd2f2f4ab-8d80-4c6f-84bc-96db31207c72';

    private EventPayloadNormalizer $normalizer;
    private RunEventSerializer $serializer;

    protected function setUp(): void
    {
        $this->normalizer = new EventPayloadNormalizer(new EventNameMap());
        $this->serializer = new RunEventSerializer($this->normalizer);
    }

    #[DataProvider('eventReferenceCases')]
    public function testEventPayloadsMatchReferenceGoldenFiles(RunEvent $event, string $fixturePath): void
    {
        $expected = $this->fixture($fixturePath);

        self::assertSame($expected, $this->normalizer->normalizeRunEvent($event));
        self::assertSame($expected, $this->serializer->normalizeRunEvent($event));
    }

    public function testDenormalizeRoundTripsReferencePayload(): void
    {
        $payload = $this->fixture('events/tool-execution-end.json');

        $event = $this->normalizer->denormalizeRunEvent($payload);

        self::assertNotNull($event);
        self::assertSame($payload, $this->normalizer->normalizeRunEvent($event));
    }

    public function testDenormalizeRejectsIncompatibleSchemaVersion(): void
    {
        $payload = $this->fixture('events/turn-start.json');
        $payload['schema_version'] = '2.0';

        self::assertNull($this->normalizer->denormalizeRunEvent($payload));
    }

    /**
     * @return iterable<string, array{RunEvent, string}>
     */
    public static function eventReferenceCases(): iterable
    {
        yield 'turn_start' => [
            new RunEvent(
                runId: self::RUN_ID,
                seq: 21,
                turnNo: 3,
                type: 'turn_start',
                payload: [],
                createdAt: new \DateTimeImmutable('2026-04-12T12:12:12+00:00'),
            ),
            'events/turn-start.json',
        ];

        yield 'tool_execution_end' => [
            new RunEvent(
                runId: self::RUN_ID,
                seq: 27,
                turnNo: 3,
                type: 'tool_execution_end',
                payload: [
                    'tool_call_id' => 'call_tc_1',
                    'tool_name' => 'web_search',
                    'is_error' => false,
                    'result_ref' => 'artifact://run/d2f2/call_tc_1_result.json.zst',
                ],
                createdAt: new \DateTimeImmutable('2026-04-12T12:12:17+00:00'),
            ),
            'events/tool-execution-end.json',
        ];

        yield 'ext_compaction_start' => [
            new RunEvent(
                runId: self::RUN_ID,
                seq: 28,
                turnNo: 3,
                type: 'ext_compaction_start',
                payload: [
                    'reason' => 'threshold',
                    'requested_by' => 'policy',
                ],
                createdAt: new \DateTimeImmutable('2026-04-12T12:12:18+00:00'),
            ),
            'events/ext-compaction-start.json',
        ];

        yield 'agent_end' => [
            new RunEvent(
                runId: self::RUN_ID,
                seq: 33,
                turnNo: 4,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: new \DateTimeImmutable('2026-04-12T12:12:30+00:00'),
            ),
            'events/run-end.json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $relativePath): array
    {
        $fullPath = __DIR__.'/../Fixtures/Schema/'.$relativePath;
        $contents = file_get_contents($fullPath);
        self::assertNotFalse($contents, 'Failed to read fixture: '.$relativePath);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Fixture JSON must decode to an object: '.$relativePath);

        return $decoded;
    }
}
