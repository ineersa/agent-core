<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Tests for AgentArtifactRegistry covering create, update, read, list,
 * path layout, locking safety, path traversal rejection, and corrupt
 * registry/malformed-entry resilience.
 *
 * Test thesis: The registry correctly stores parent-scoped artifact
 * entries under .hatfield/sessions/<parent>/artifacts/agents/ without
 * creating top-level session directories, rejects path traversal in all
 * public APIs, and propagates corrupt data aggressively instead of
 * silently clobbering.
 */
final class AgentArtifactRegistryTest extends TestCase
{
    private string $projectDir;
    private AgentArtifactPathResolver $pathResolver;
    private AgentArtifactRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-agent-registry');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);

        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->projectDir,
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            )],
            [new JsonEncoder()],
        );

        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();

        $this->pathResolver = new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($hatfieldSessionStore));

        $this->registry = new AgentArtifactRegistry(
            pathResolver: $this->pathResolver,
            serializer: $serializer,
            validator: $validator,
            lockFactory: new LockFactory(new FlockStore()),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    // ── Create ────────────────────────────────────────────────────────────

    public function testCreateStoresRegistryAndFilesUnderParentSession(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_01HX';
        $agentRunId = 'child-'.bin2hex(random_bytes(4));

        $entry = $this->registry->create($parentRunId, $artifactId, $agentRunId, 'scout', AgentArtifactKindEnum::Subagent);

        $this->assertSame($artifactId, $entry->artifactId);
        $this->assertSame($parentRunId, $entry->parentRunId);
        $this->assertSame($agentRunId, $entry->agentRunId);
        $this->assertSame('scout', $entry->agentName);
        $this->assertSame(AgentArtifactStatusEnum::Pending, $entry->status);
        $this->assertSame("artifacts/agents/{$artifactId}", $entry->paths->artifactDir);

        // Verify files exist on disk
        $base = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents';
        $this->assertFileExists($base.'/registry.json');
        $this->assertFileExists($base.'/'.$artifactId.'/metadata.json');
        $this->assertFileExists($base.'/'.$artifactId.'/handoff.md');
        $this->assertDirectoryExists($base.'/'.$artifactId);

        // Verify no top-level child session directory was created
        $this->assertDirectoryDoesNotExist($this->projectDir.'/.hatfield/sessions/'.$agentRunId);
    }

    public function testCreateWithDifferentArtifactIds(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $entry1 = $this->registry->create($parentRunId, 'scout-001', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
        $entry2 = $this->registry->create($parentRunId, 'scout-002', 'child-b', 'scout', AgentArtifactKindEnum::Subagent);

        $this->assertSame('scout-001', $entry1->artifactId);
        $this->assertSame('scout-002', $entry2->artifactId);

        $all = $this->registry->list($parentRunId);
        $this->assertCount(2, $all);
    }

    public function testPromoteToRunningForwardOnlyDoesNotRegressCompletedUnderLock(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_01HX';
        $agentRunId = 'child-'.bin2hex(random_bytes(4));

        $this->registry->create($parentRunId, $artifactId, $agentRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->update($parentRunId, $artifactId, status: AgentArtifactStatusEnum::Completed, completedAt: new \DateTimeImmutable());

        $promoted = $this->registry->promoteToRunningForwardOnly($parentRunId, $artifactId, new \DateTimeImmutable());

        $this->assertSame(AgentArtifactStatusEnum::Completed, $promoted?->status);
        $entry = $this->registry->get($parentRunId, $artifactId);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
    }

    public function testCreateRejectsDuplicateArtifactIdInSameParent(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->registry->create($parentRunId, 'agent_01HX', 'child-b', 'reviewer', AgentArtifactKindEnum::Subagent);
    }

    public function testCreateRejectsEmptyParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->registry->create('', 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
    }

    public function testCreateRejectsPathSeparatorsInIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->create('parent', 'a/b', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
    }

    public function testCreateRejectsDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be "."');

        $this->registry->create('parent', '.', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
    }

    public function testCreateRejectsDotDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be ".."');

        $this->registry->create('parent', '..', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
    }

    public function testCreateRejectsBackslashInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->create('parent', 'a\\b', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
    }

    public function testCreateRejectsNulByteInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NUL bytes');

        $this->registry->create('parent', "bad\0id", 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
    }

    public function testRegistryJsonIsValid(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $path = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/registry.json';
        $data = json_decode(file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(1, $data['schema_version']);
        $this->assertIsArray($data['entries']);
        $this->assertCount(1, $data['entries']);
        $this->assertSame('agent_01HX', $data['entries'][0]['artifact_id']);
        $this->assertSame('scout', $data['entries'][0]['agent_name']);
        $this->assertSame('pending', $data['entries'][0]['status']);
        // Paths are nested (serializer-native shape)
        $this->assertIsArray($data['entries'][0]['paths']);
        $this->assertSame('artifacts/agents/agent_01HX', $data['entries'][0]['paths']['artifact_dir']);
    }

    // ── Get ───────────────────────────────────────────────────────────────

    public function testGetReturnsExistingEntry(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'scout-001', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $entry = $this->registry->get($parentRunId, 'scout-001');

        $this->assertNotNull($entry);
        $this->assertSame('scout-001', $entry->artifactId);
        $this->assertSame('scout', $entry->agentName);
    }

    public function testGetReturnsNullForMissingArtifactId(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $this->assertNull($this->registry->get($parentRunId, 'nonexistent'));
    }

    public function testGetReturnsNullForDifferentParent(): void
    {
        $parentA = 'parent-a-'.bin2hex(random_bytes(4));
        $parentB = 'parent-b-'.bin2hex(random_bytes(4));
        $this->registry->create($parentA, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $this->assertNull($this->registry->get($parentB, 'agent_01HX'));
    }

    public function testGetRejectsPathTraversalInParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->get('../sessions', 'agent_01HX');
    }

    public function testGetRejectsDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be "."');

        $this->registry->get('parent', '.');
    }

    public function testGetRejectsPathTraversalInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be ".."');

        $this->registry->get('parent', '..');
    }

    public function testFindByAgentRunId(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-run-abc', 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->create($parentRunId, 'agent_02HX', 'child-run-xyz', 'reviewer', AgentArtifactKindEnum::Subagent);

        // Find by agentRunId
        $entry = $this->registry->findByAgentRunId($parentRunId, 'child-run-abc');
        $this->assertNotNull($entry);
        $this->assertSame('agent_01HX', $entry->artifactId);
        $this->assertSame('scout', $entry->agentName);

        // Missing agentRunId
        $this->assertNull($this->registry->findByAgentRunId($parentRunId, 'nonexistent'));
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function testUpdateTransitionsStatusToRunning(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Running,
            startedAt: new \DateTimeImmutable('2026-06-22T12:00:00+00:00'),
        );

        $this->assertNotNull($updated);
        $this->assertSame(AgentArtifactStatusEnum::Running, $updated->status);
        $this->assertNotNull($updated->startedAt);

        // Re-read from registry confirms persistence
        $reloaded = $this->registry->get($parentRunId, 'agent_01HX');
        $this->assertNotNull($reloaded);
        $this->assertSame(AgentArtifactStatusEnum::Running, $reloaded->status);
    }

    public function testUpdateTransitionsToCompleted(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Completed,
            summary: 'Found 3 files, 2 risks',
            completedAt: new \DateTimeImmutable('2026-06-22T12:05:00+00:00'),
        );

        $this->assertNotNull($updated);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $updated->status);
        $this->assertSame('Found 3 files, 2 risks', $updated->summary);

        // Reload confirms
        $reloaded = $this->registry->get($parentRunId, 'agent_01HX');
        $this->assertNotNull($reloaded);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $reloaded->status);
        $this->assertSame('Found 3 files, 2 risks', $reloaded->summary);
    }

    public function testUpdateTransitionsToFailed(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Failed,
            failureReason: 'Tool execution error',
            completedAt: new \DateTimeImmutable(),
        );

        $this->assertNotNull($updated);
        $this->assertSame(AgentArtifactStatusEnum::Failed, $updated->status);
        $this->assertSame('Tool execution error', $updated->failureReason);
    }

    public function testUpdateTransitionsToNeedsClarification(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::NeedsClarification,
            needsClarification: 'Which approach: monorepo or multi-repo?',
            completedAt: new \DateTimeImmutable(),
        );

        $this->assertNotNull($updated);
        $this->assertSame(AgentArtifactStatusEnum::NeedsClarification, $updated->status);
        $this->assertSame('Which approach: monorepo or multi-repo?', $updated->needsClarification);
    }

    public function testUpdatePreservesIdentityFields(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Completed,
        );

        $this->assertNotNull($updated);
        // Identity fields preserved
        $this->assertSame('agent_01HX', $updated->artifactId);
        $this->assertSame($parentRunId, $updated->parentRunId);
        $this->assertSame('child-a', $updated->agentRunId);
        $this->assertSame('scout', $updated->agentName);
    }

    public function testUpdateReturnsNullForMissingArtifact(): void
    {
        $this->assertNull(
            $this->registry->update('parent-1', 'nonexistent', status: AgentArtifactStatusEnum::Completed),
        );
    }

    public function testUpdateRejectsPathTraversalInParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->update('../sessions', 'agent_01HX', status: AgentArtifactStatusEnum::Running);
    }

    public function testUpdateRejectsDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be "."');

        $this->registry->update('parent', '.', status: AgentArtifactStatusEnum::Running);
    }

    public function testUpdateRejectsPathTraversalInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->update('parent', 'a/b', status: AgentArtifactStatusEnum::Running);
    }

    // ── List ──────────────────────────────────────────────────────────────

    public function testListReturnsEmptyForNoArtifacts(): void
    {
        $this->assertCount(0, $this->registry->list('parent-never-created'));
    }

    public function testListReturnsAllEntriesForParent(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'scout-001', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->create($parentRunId, 'reviewer-001', 'child-b', 'reviewer', AgentArtifactKindEnum::Subagent);

        $entries = $this->registry->list($parentRunId);
        $this->assertCount(2, $entries);

        $names = array_map(static fn ($e) => $e->agentName, $entries);
        $this->assertContains('scout', $names);
        $this->assertContains('reviewer', $names);
    }

    public function testListRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->list('../sessions');
    }

    // ── Resolve path helpers ──────────────────────────────────────────────

    public function testResolveArtifactsBasePath(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $expected = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents';

        $this->assertSame($expected, $this->pathResolver->resolveArtifactsBasePath($parentRunId));
    }

    public function testResolveArtifactsBasePathRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->pathResolver->resolveArtifactsBasePath('../sessions');
    }

    public function testResolveArtifactDir(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $expected = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/agent_01HX';

        $this->assertSame($expected, $this->pathResolver->resolveArtifactDir($parentRunId, 'agent_01HX'));
    }

    public function testResolveArtifactDirRejectsDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be "."');

        $this->pathResolver->resolveArtifactDir('parent', '.');
    }

    public function testResolveArtifactDirRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->pathResolver->resolveArtifactDir('parent', 'a/b');
    }

    // ── Locking / concurrent update safety ───────────────────────────────

    public function testSequentialUpdatesDoNotCorruptRegistry(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        // Simulate multiple sequential updates (pending → running → completed)
        $this->registry->update(
            $parentRunId, 'agent_01HX',
            status: AgentArtifactStatusEnum::Running,
            startedAt: new \DateTimeImmutable(),
        );

        $this->registry->update(
            $parentRunId, 'agent_01HX',
            status: AgentArtifactStatusEnum::Completed,
            summary: 'All done',
            completedAt: new \DateTimeImmutable(),
        );

        $entry = $this->registry->get($parentRunId, 'agent_01HX');
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        $this->assertSame('All done', $entry->summary);

        // Registry JSON is still valid
        $path = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/registry.json';
        $data = json_decode(file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data['entries']);
    }

    public function testMultipleArtifactsDifferentParentsDoNotInterfere(): void
    {
        $parentA = 'parent-a-'.bin2hex(random_bytes(4));
        $parentB = 'parent-b-'.bin2hex(random_bytes(4));

        $this->registry->create($parentA, 'agent_a', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->create($parentB, 'agent_b', 'child-b', 'reviewer', AgentArtifactKindEnum::Subagent);

        // Update only parent A
        $this->registry->update($parentA, 'agent_a', status: AgentArtifactStatusEnum::Completed);

        // Parent B entry remains Pending
        $entryB = $this->registry->get($parentB, 'agent_b');
        $this->assertNotNull($entryB);
        $this->assertSame(AgentArtifactStatusEnum::Pending, $entryB->status);
    }

    // ── Metadata files ────────────────────────────────────────────────────

    public function testMetadataJsonContainsRequiredFields(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $metadataPath = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/agent_01HX/metadata.json';
        $this->assertFileExists($metadataPath);

        $meta = json_decode(file_get_contents($metadataPath), true, 512, \JSON_THROW_ON_ERROR);
        // Serializer-native nested shape (no "kind" — metadata mirrors registry entry)
        $this->assertSame('agent_01HX', $meta['artifact_id']);
        $this->assertSame($parentRunId, $meta['parent_run_id']);
        $this->assertSame('child-a', $meta['agent_run_id']);
        $this->assertSame('scout', $meta['agent_name']);
        $this->assertSame('pending', $meta['status']);
        $this->assertIsArray($meta['paths']);
        $this->assertSame('artifacts/agents/agent_01HX', $meta['paths']['artifact_dir']);
    }

    public function testHandoffMdIsCreatedEmpty(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);

        $handoffPath = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/agent_01HX/handoff.md';
        $this->assertFileExists($handoffPath);
        $this->assertSame('', file_get_contents($handoffPath));
    }

    // ── Corrupt registry / malformed entry resilience ────────────────────

    public function testCorruptRegistryJsonThrowsOnCreate(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        // Write corrupt JSON as the registry
        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        file_put_contents($agentsDir.'/registry.json', 'this is not json {{{');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt registry.json');

        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout', AgentArtifactKindEnum::Subagent);
    }

    public function testCorruptRegistryJsonThrowsOnGet(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        file_put_contents($agentsDir.'/registry.json', 'corrupt');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt registry.json');

        $this->registry->get($parentRunId, 'any-id');
    }

    public function testCorruptRegistryJsonThrowsOnList(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        file_put_contents($agentsDir.'/registry.json', 'not valid json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt registry.json');

        $this->registry->list($parentRunId);
    }

    public function testMalformedRegistryEntryThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        // Valid JSON but entry missing required fields — denormalization fails
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
            'entries' => [['foo' => 'bar']],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be denormalized');

        $this->registry->list($parentRunId);
    }

    public function testRegistryWithUnknownStatusThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        // Nested paths shape (serializer-native)
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
            'entries' => [[
                'artifact_id' => 'agent_01HX',
                'parent_run_id' => $parentRunId,
                'agent_run_id' => 'child-a',
                'agent_name' => 'scout',
                'kind' => 'subagent',
                'status' => 'nonexistent_status',
                'created_at' => '2026-06-22T12:00:00+00:00',
                'paths' => [
                    'artifact_dir' => 'artifacts/agents/agent_01HX',
                    'handoff_path' => 'artifacts/agents/agent_01HX/handoff.md',
                    'metadata_path' => 'artifacts/agents/agent_01HX/metadata.json',
                    'events_path' => 'artifacts/agents/agent_01HX/events.jsonl',
                    'state_path' => 'artifacts/agents/agent_01HX/state.json',
                ],
            ]],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be denormalized');

        $this->registry->list($parentRunId);
    }

    public function testRegistryWithMismatchedSchemaVersionThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 999,
            'entries' => [],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported schema_version');

        $this->registry->list($parentRunId);
    }

    public function testRegistryWithMissingSchemaVersionThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'entries' => [],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported schema_version');

        $this->registry->list($parentRunId);
    }

    public function testRegistryMissingEntriesKeyThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required "entries"');

        $this->registry->list($parentRunId);
    }

    public function testRegistryWithEmptyIdentityFieldFailsValidation(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        // Registry entry with blank artifact_id — Validator should catch this.
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
            'entries' => [[
                'artifact_id' => '',
                'parent_run_id' => $parentRunId,
                'agent_run_id' => 'child-a',
                'agent_name' => 'scout',
                'kind' => 'subagent',
                'status' => 'pending',
                'created_at' => '2026-06-22T12:00:00+00:00',
                'paths' => [
                    'artifact_dir' => 'artifacts/agents/',
                    'handoff_path' => 'artifacts/agents//handoff.md',
                    'metadata_path' => 'artifacts/agents//metadata.json',
                    'events_path' => 'artifacts/agents//events.jsonl',
                    'state_path' => 'artifacts/agents//state.json',
                ],
            ]],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed validation');

        $this->registry->list($parentRunId);
    }

    public function testRegistryWithTamperedArtifactDirThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->pathResolver->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0755, true);
        // Tampered artifact_dir — file paths are correct but artifact_dir does
        // not match the canonical path for the artifact ID.
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
            'entries' => [[
                'artifact_id' => 'agent_01HX',
                'parent_run_id' => $parentRunId,
                'agent_run_id' => 'child-a',
                'agent_name' => 'scout',
                'kind' => 'subagent',
                'status' => 'pending',
                'created_at' => '2026-06-22T12:00:00+00:00',
                'paths' => [
                    'artifact_dir' => 'artifacts/agents/evil_dir',
                    'handoff_path' => 'artifacts/agents/agent_01HX/handoff.md',
                    'metadata_path' => 'artifacts/agents/agent_01HX/metadata.json',
                    'events_path' => 'artifacts/agents/agent_01HX/events.jsonl',
                    'state_path' => 'artifacts/agents/agent_01HX/state.json',
                ],
            ]],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected paths');

        $this->registry->list($parentRunId);
    }

    public function testReadHandoffReturnsWrittenContent(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_read_01';
        $agentRunId = 'child-'.bin2hex(random_bytes(4));

        $this->registry->create($parentRunId, $artifactId, $agentRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->writeHandoff($parentRunId, $artifactId, '# Handoff

Done.');

        $this->assertSame('# Handoff

Done.', $this->registry->readHandoff($parentRunId, $artifactId));
    }

    public function testReadHandoffRejectsPathTraversalArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->readHandoff('parent-1', '../evil');
    }
}
