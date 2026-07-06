<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
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
 * Tests for AgentArtifactKindEnum and the kind field on AgentArtifactEntryDTO.
 *
 * Test thesis:
 *   - AgentArtifactKindEnum has the expected cases and string values.
 *   - AgentArtifactEntryDTO serializes and deserializes the kind field correctly.
 *   - Existing entry serialization defaults produce the 'subagent' kind.
 *   - The serializer honors #[SerializedName] annotations (snake_case keys).
 */
#[CoversClass(AgentArtifactKindEnum::class)]
#[CoversClass(AgentArtifactEntryDTO::class)]
final class AgentArtifactKindEnumTest extends TestCase
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

    public function testEnumCases(): void
    {
        $this->assertSame('subagent', AgentArtifactKindEnum::Subagent->value);
        $this->assertSame('fork', AgentArtifactKindEnum::Fork->value);
    }

    public function testEntryDtoRoundTripWithSubagentKind(): void
    {
        $now = new \DateTimeImmutable();
        $paths = AgentArtifactPathsDTO::forArtifactId('test_001');

        $original = new AgentArtifactEntryDTO(
            artifactId: 'test_001',
            parentRunId: 'parent_001',
            agentRunId: 'agent_001',
            agentName: 'scout',
            kind: AgentArtifactKindEnum::Subagent,
            status: AgentArtifactStatusEnum::Pending,
            paths: $paths,
            createdAt: $now,
        );

        $json = $this->serializer->serialize($original, 'json');
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('kind', $data);
        $this->assertSame('subagent', $data['kind']);
        $this->assertArrayHasKey('artifact_id', $data);
        $this->assertArrayHasKey('parent_run_id', $data);
        $this->assertArrayHasKey('agent_run_id', $data);
        $this->assertArrayHasKey('agent_name', $data);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertSame('test_001', $data['artifact_id']);

        $restored = $this->serializer->deserialize($json, AgentArtifactEntryDTO::class, 'json');
        $this->assertSame(AgentArtifactKindEnum::Subagent, $restored->kind);
    }

    public function testEntryDtoRoundTripWithForkKind(): void
    {
        $now = new \DateTimeImmutable();
        $paths = AgentArtifactPathsDTO::forArtifactId('fork_001');

        $original = new AgentArtifactEntryDTO(
            artifactId: 'fork_001',
            parentRunId: 'parent_001',
            agentRunId: 'fork_agent_001',
            agentName: 'fork-child',
            kind: AgentArtifactKindEnum::Fork,
            status: AgentArtifactStatusEnum::Running,
            paths: $paths,
            createdAt: $now,
        );

        $json = $this->serializer->serialize($original, 'json');
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('kind', $data);
        $this->assertSame('fork', $data['kind']);
        $this->assertArrayHasKey('artifact_id', $data);
        $this->assertSame('fork_001', $data['artifact_id']);

        $restored = $this->serializer->deserialize($json, AgentArtifactEntryDTO::class, 'json');
        $this->assertSame(AgentArtifactKindEnum::Fork, $restored->kind);
        $this->assertSame(AgentArtifactStatusEnum::Running, $restored->status);
    }
}
