<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Fork\ForkRunMetadataDTO;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Tests for ForkRunMetadataDTO serializer round-trip fidelity.
 *
 * Test thesis: ForkRunMetadataDTO serializes to and deserializes from
 * JSON via Symfony Serializer with correct snake_case field names,
 * preserving all field values.
 */
#[CoversClass(ForkRunMetadataDTO::class)]
final class ForkRunMetadataDTOTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);

        $this->serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: $nameConverter,
            )],
            [new JsonEncoder()],
        );
    }

    public function testRoundTrip(): void
    {
        $startedAt = new \DateTimeImmutable('2025-06-01T10:00:00+00:00');
        $completedAt = new \DateTimeImmutable('2025-06-01T10:30:00+00:00');

        $original = new ForkRunMetadataDTO(
            runId: 'fork_abc123',
            parentRunId: 'parent_run_001',
            childRunId: 'child_run_xyz',
            level: ForkLevelEnum::Senior,
            resolvedModel: 'openai/gpt-4',
            cwd: '/home/project',
            task: 'Implement feature X',
            status: AgentArtifactStatusEnum::Completed,
            startedAt: $startedAt,
            completedAt: $completedAt,
            pid: 12345,
            error: null,
            validationAttempts: 2,
        );

        $json = $this->serializer->serialize($original, 'json');
        $restored = $this->serializer->deserialize($json, ForkRunMetadataDTO::class, 'json');

        self::assertSame($original->runId, $restored->runId);
        self::assertSame($original->parentRunId, $restored->parentRunId);
        self::assertSame($original->childRunId, $restored->childRunId);
        self::assertSame($original->level, $restored->level);
        self::assertSame($original->resolvedModel, $restored->resolvedModel);
        self::assertSame($original->cwd, $restored->cwd);
        self::assertSame($original->task, $restored->task);
        self::assertSame($original->status, $restored->status);
        self::assertSame($original->startedAt->format(\DATE_ATOM), $restored->startedAt->format(\DATE_ATOM));
        self::assertSame($original->completedAt->format(\DATE_ATOM), $restored->completedAt->format(\DATE_ATOM));
        self::assertSame($original->pid, $restored->pid);
        self::assertSame($original->error, $restored->error);
        self::assertSame($original->validationAttempts, $restored->validationAttempts);
    }

    public function testDefaultValues(): void
    {
        $dto = new ForkRunMetadataDTO(
            runId: 'fork_001',
            parentRunId: 'parent_001',
        );

        self::assertNull($dto->childRunId);
        self::assertSame(ForkLevelEnum::Middle, $dto->level);
        self::assertNull($dto->resolvedModel);
        self::assertSame('', $dto->cwd);
        self::assertSame('', $dto->task);
        self::assertSame(AgentArtifactStatusEnum::Pending, $dto->status);
        self::assertNull($dto->startedAt);
        self::assertNull($dto->completedAt);
        self::assertNull($dto->pid);
        self::assertNull($dto->error);
        self::assertSame(0, $dto->validationAttempts);
    }

    public function testSnakeCaseSerialization(): void
    {
        $dto = new ForkRunMetadataDTO(
            runId: 'fork_001',
            parentRunId: 'parent_001',
            validationAttempts: 3,
        );

        $json = $this->serializer->serialize($dto, 'json');
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        // Verify snake_case keys.
        self::assertArrayHasKey('run_id', $data);
        self::assertArrayHasKey('parent_run_id', $data);
        self::assertArrayHasKey('child_run_id', $data);
        self::assertArrayHasKey('resolved_model', $data);
        self::assertArrayHasKey('validation_attempts', $data);

        // Verify values.
        self::assertSame('fork_001', $data['run_id']);
        self::assertSame('parent_001', $data['parent_run_id']);
        self::assertSame(3, $data['validation_attempts']);
    }

    public function testRoundTripWithNullOptionals(): void
    {
        $original = new ForkRunMetadataDTO(
            runId: 'fork_null_test',
            parentRunId: 'parent_null_test',
        );

        $json = $this->serializer->serialize($original, 'json');
        $restored = $this->serializer->deserialize($json, ForkRunMetadataDTO::class, 'json');

        self::assertSame('fork_null_test', $restored->runId);
        self::assertSame('parent_null_test', $restored->parentRunId);
        self::assertNull($restored->childRunId);
        self::assertNull($restored->resolvedModel);
        self::assertNull($restored->startedAt);
        self::assertNull($restored->completedAt);
        self::assertNull($restored->pid);
        self::assertNull($restored->error);
    }
}
