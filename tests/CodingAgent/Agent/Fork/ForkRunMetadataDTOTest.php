<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Fork\ForkRunMetadataDTO;
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

        $this->assertSame($original->runId, $restored->runId);
        $this->assertSame($original->parentRunId, $restored->parentRunId);
        $this->assertSame($original->childRunId, $restored->childRunId);
        $this->assertSame($original->resolvedModel, $restored->resolvedModel);
        $this->assertSame($original->cwd, $restored->cwd);
        $this->assertSame($original->task, $restored->task);
        $this->assertSame($original->status, $restored->status);
        $this->assertSame($original->startedAt->format(\DATE_ATOM), $restored->startedAt->format(\DATE_ATOM));
        $this->assertSame($original->completedAt->format(\DATE_ATOM), $restored->completedAt->format(\DATE_ATOM));
        $this->assertSame($original->pid, $restored->pid);
        $this->assertSame($original->error, $restored->error);
        $this->assertSame($original->validationAttempts, $restored->validationAttempts);
    }

    public function testDefaultValues(): void
    {
        $dto = new ForkRunMetadataDTO(
            runId: 'fork_001',
            parentRunId: 'parent_001',
        );

        $this->assertNull($dto->childRunId);
        $this->assertNull($dto->resolvedModel);
        $this->assertSame('', $dto->cwd);
        $this->assertSame('', $dto->task);
        $this->assertSame(AgentArtifactStatusEnum::Pending, $dto->status);
        $this->assertNull($dto->startedAt);
        $this->assertNull($dto->completedAt);
        $this->assertNull($dto->pid);
        $this->assertNull($dto->error);
        $this->assertSame(0, $dto->validationAttempts);
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
        $this->assertArrayHasKey('run_id', $data);
        $this->assertArrayHasKey('parent_run_id', $data);
        $this->assertArrayHasKey('child_run_id', $data);
        $this->assertArrayHasKey('resolved_model', $data);
        $this->assertArrayHasKey('validation_attempts', $data);

        // Verify values.
        $this->assertSame('fork_001', $data['run_id']);
        $this->assertSame('parent_001', $data['parent_run_id']);
        $this->assertSame(3, $data['validation_attempts']);
    }

    public function testRoundTripWithNullOptionals(): void
    {
        $original = new ForkRunMetadataDTO(
            runId: 'fork_null_test',
            parentRunId: 'parent_null_test',
        );

        $json = $this->serializer->serialize($original, 'json');
        $restored = $this->serializer->deserialize($json, ForkRunMetadataDTO::class, 'json');

        $this->assertSame('fork_null_test', $restored->runId);
        $this->assertSame('parent_null_test', $restored->parentRunId);
        $this->assertNull($restored->childRunId);
        $this->assertNull($restored->resolvedModel);
        $this->assertNull($restored->startedAt);
        $this->assertNull($restored->completedAt);
        $this->assertNull($restored->pid);
        $this->assertNull($restored->error);
    }
}
