<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

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

        $this->registry = new AgentArtifactRegistry(
            hatfieldSessionStore: $hatfieldSessionStore,
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

        $entry = $this->registry->create($parentRunId, $artifactId, $agentRunId, 'scout');

        self::assertSame($artifactId, $entry->artifactId);
        self::assertSame($parentRunId, $entry->parentRunId);
        self::assertSame($agentRunId, $entry->agentRunId);
        self::assertSame('scout', $entry->agentName);
        self::assertSame(AgentArtifactStatusEnum::Pending, $entry->status);
        self::assertSame("artifacts/agents/{$artifactId}", $entry->paths->artifactDir);

        // Verify files exist on disk
        $base = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents';
        self::assertFileExists($base.'/registry.json');
        self::assertFileExists($base.'/'.$artifactId.'/metadata.json');
        self::assertFileExists($base.'/'.$artifactId.'/handoff.md');
        self::assertDirectoryExists($base.'/'.$artifactId);

        // Verify no top-level child session directory was created
        self::assertDirectoryDoesNotExist($this->projectDir.'/.hatfield/sessions/'.$agentRunId);
    }

    public function testCreateWithDifferentArtifactIds(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $entry1 = $this->registry->create($parentRunId, 'scout-001', 'child-a', 'scout');
        $entry2 = $this->registry->create($parentRunId, 'scout-002', 'child-b', 'scout');

        self::assertSame('scout-001', $entry1->artifactId);
        self::assertSame('scout-002', $entry2->artifactId);

        $all = $this->registry->list($parentRunId);
        self::assertCount(2, $all);
    }

    public function testCreateRejectsDuplicateArtifactIdInSameParent(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->registry->create($parentRunId, 'agent_01HX', 'child-b', 'reviewer');
    }

    public function testCreateRejectsEmptyParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->registry->create('', 'agent_01HX', 'child-a', 'scout');
    }

    public function testCreateRejectsPathSeparatorsInIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->create('parent', 'a/b', 'child-a', 'scout');
    }

    public function testCreateRejectsDotDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be ".."');

        $this->registry->create('parent', '..', 'child-a', 'scout');
    }

    public function testCreateRejectsBackslashInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->create('parent', 'a\\b', 'child-a', 'scout');
    }

    public function testRegistryJsonIsValid(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $path = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/registry.json';
        $data = json_decode(file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['schema_version']);
        self::assertIsArray($data['entries']);
        self::assertCount(1, $data['entries']);
        self::assertSame('agent_01HX', $data['entries'][0]['artifact_id']);
        self::assertSame('scout', $data['entries'][0]['agent_name']);
        self::assertSame('pending', $data['entries'][0]['status']);
    }

    // ── Get ───────────────────────────────────────────────────────────────

    public function testGetReturnsExistingEntry(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'scout-001', 'child-a', 'scout');

        $entry = $this->registry->get($parentRunId, 'scout-001');

        self::assertNotNull($entry);
        self::assertSame('scout-001', $entry->artifactId);
        self::assertSame('scout', $entry->agentName);
    }

    public function testGetReturnsNullForMissingArtifactId(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        self::assertNull($this->registry->get($parentRunId, 'nonexistent'));
    }

    public function testGetReturnsNullForDifferentParent(): void
    {
        $parentA = 'parent-a-'.bin2hex(random_bytes(4));
        $parentB = 'parent-b-'.bin2hex(random_bytes(4));
        $this->registry->create($parentA, 'agent_01HX', 'child-a', 'scout');

        self::assertNull($this->registry->get($parentB, 'agent_01HX'));
    }

    public function testGetRejectsPathTraversalInParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->get('../sessions', 'agent_01HX');
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
        $this->registry->create($parentRunId, 'agent_01HX', 'child-run-abc', 'scout');
        $this->registry->create($parentRunId, 'agent_02HX', 'child-run-xyz', 'reviewer');

        // Find by agentRunId
        $entry = $this->registry->findByAgentRunId($parentRunId, 'child-run-abc');
        self::assertNotNull($entry);
        self::assertSame('agent_01HX', $entry->artifactId);
        self::assertSame('scout', $entry->agentName);

        // Missing agentRunId
        self::assertNull($this->registry->findByAgentRunId($parentRunId, 'nonexistent'));
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function testUpdateTransitionsStatusToRunning(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Running,
            startedAt: new \DateTimeImmutable('2026-06-22T12:00:00+00:00'),
        );

        self::assertNotNull($updated);
        self::assertSame(AgentArtifactStatusEnum::Running, $updated->status);
        self::assertNotNull($updated->startedAt);

        // Re-read from registry confirms persistence
        $reloaded = $this->registry->get($parentRunId, 'agent_01HX');
        self::assertNotNull($reloaded);
        self::assertSame(AgentArtifactStatusEnum::Running, $reloaded->status);
    }

    public function testUpdateTransitionsToCompleted(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Completed,
            summary: 'Found 3 files, 2 risks',
            completedAt: new \DateTimeImmutable('2026-06-22T12:05:00+00:00'),
        );

        self::assertNotNull($updated);
        self::assertSame(AgentArtifactStatusEnum::Completed, $updated->status);
        self::assertSame('Found 3 files, 2 risks', $updated->summary);

        // Reload confirms
        $reloaded = $this->registry->get($parentRunId, 'agent_01HX');
        self::assertNotNull($reloaded);
        self::assertSame(AgentArtifactStatusEnum::Completed, $reloaded->status);
        self::assertSame('Found 3 files, 2 risks', $reloaded->summary);
    }

    public function testUpdateTransitionsToFailed(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Failed,
            failureReason: 'Tool execution error',
            completedAt: new \DateTimeImmutable(),
        );

        self::assertNotNull($updated);
        self::assertSame(AgentArtifactStatusEnum::Failed, $updated->status);
        self::assertSame('Tool execution error', $updated->failureReason);
    }

    public function testUpdateTransitionsToNeedsClarification(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::NeedsClarification,
            needsClarification: 'Which approach: monorepo or multi-repo?',
            completedAt: new \DateTimeImmutable(),
        );

        self::assertNotNull($updated);
        self::assertSame(AgentArtifactStatusEnum::NeedsClarification, $updated->status);
        self::assertSame('Which approach: monorepo or multi-repo?', $updated->needsClarification);
    }

    public function testUpdatePreservesIdentityFields(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $updated = $this->registry->update(
            parentRunId: $parentRunId,
            artifactId: 'agent_01HX',
            status: AgentArtifactStatusEnum::Completed,
        );

        self::assertNotNull($updated);
        // Identity fields preserved
        self::assertSame('agent_01HX', $updated->artifactId);
        self::assertSame($parentRunId, $updated->parentRunId);
        self::assertSame('child-a', $updated->agentRunId);
        self::assertSame('scout', $updated->agentName);
    }

    public function testUpdateReturnsNullForMissingArtifact(): void
    {
        self::assertNull(
            $this->registry->update('parent-1', 'nonexistent', status: AgentArtifactStatusEnum::Completed),
        );
    }

    public function testUpdateRejectsPathTraversalInParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->update('../sessions', 'agent_01HX', status: AgentArtifactStatusEnum::Running);
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
        self::assertCount(0, $this->registry->list('parent-never-created'));
    }

    public function testListReturnsAllEntriesForParent(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'scout-001', 'child-a', 'scout');
        $this->registry->create($parentRunId, 'reviewer-001', 'child-b', 'reviewer');

        $entries = $this->registry->list($parentRunId);
        self::assertCount(2, $entries);

        $names = array_map(static fn ($e) => $e->agentName, $entries);
        self::assertContains('scout', $names);
        self::assertContains('reviewer', $names);
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

        self::assertSame($expected, $this->registry->resolveArtifactsBasePath($parentRunId));
    }

    public function testResolveArtifactsBasePathRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->resolveArtifactsBasePath('../sessions');
    }

    public function testResolveArtifactDir(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $expected = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/agent_01HX';

        self::assertSame($expected, $this->registry->resolveArtifactDir($parentRunId, 'agent_01HX'));
    }

    public function testResolveArtifactDirRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->registry->resolveArtifactDir('parent', 'a/b');
    }

    // ── Locking / concurrent update safety ───────────────────────────────

    public function testSequentialUpdatesDoNotCorruptRegistry(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

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
        self::assertNotNull($entry);
        self::assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        self::assertSame('All done', $entry->summary);

        // Registry JSON is still valid
        $path = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/registry.json';
        $data = json_decode(file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['entries']);
    }

    public function testMultipleArtifactsDifferentParentsDoNotInterfere(): void
    {
        $parentA = 'parent-a-'.bin2hex(random_bytes(4));
        $parentB = 'parent-b-'.bin2hex(random_bytes(4));

        $this->registry->create($parentA, 'agent_a', 'child-a', 'scout');
        $this->registry->create($parentB, 'agent_b', 'child-b', 'reviewer');

        // Update only parent A
        $this->registry->update($parentA, 'agent_a', status: AgentArtifactStatusEnum::Completed);

        // Parent B entry remains Pending
        $entryB = $this->registry->get($parentB, 'agent_b');
        self::assertNotNull($entryB);
        self::assertSame(AgentArtifactStatusEnum::Pending, $entryB->status);
    }

    // ── Metadata files ────────────────────────────────────────────────────

    public function testMetadataJsonContainsRequiredFields(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $metadataPath = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/agent_01HX/metadata.json';
        self::assertFileExists($metadataPath);

        $meta = json_decode(file_get_contents($metadataPath), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('agent_child', $meta['kind']);
        self::assertSame('agent_01HX', $meta['artifact_id']);
        self::assertSame($parentRunId, $meta['parent_run_id']);
        self::assertSame('child-a', $meta['agent_run_id']);
        self::assertSame('scout', $meta['agent_name']);
        self::assertSame('pending', $meta['status']);
    }

    public function testHandoffMdIsCreatedEmpty(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');

        $handoffPath = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/agent_01HX/handoff.md';
        self::assertFileExists($handoffPath);
        self::assertSame('', file_get_contents($handoffPath));
    }

    // ── Corrupt registry / malformed entry resilience ────────────────────

    public function testCorruptRegistryJsonThrowsOnCreate(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        // Write corrupt JSON as the registry
        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
        file_put_contents($agentsDir.'/registry.json', 'this is not json {{{');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt registry.json');

        $this->registry->create($parentRunId, 'agent_01HX', 'child-a', 'scout');
    }

    public function testCorruptRegistryJsonThrowsOnGet(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
        file_put_contents($agentsDir.'/registry.json', 'corrupt');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt registry.json');

        $this->registry->get($parentRunId, 'any-id');
    }

    public function testCorruptRegistryJsonThrowsOnList(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
        file_put_contents($agentsDir.'/registry.json', 'not valid json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt registry.json');

        $this->registry->list($parentRunId);
    }

    public function testMalformedRegistryEntryThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
        // Valid JSON but entry missing required fields
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
            'entries' => [['foo' => 'bar']],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or non-string required fields');

        $this->registry->list($parentRunId);
    }

    public function testRegistryWithUnknownStatusThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
            'entries' => [[
                'artifact_id' => 'agent_01HX',
                'parent_run_id' => $parentRunId,
                'agent_run_id' => 'child-a',
                'agent_name' => 'scout',
                'status' => 'nonexistent_status',
                'created_at' => '2026-06-22T12:00:00+00:00',
                'handoff_path' => 'artifacts/agents/agent_01HX/handoff.md',
                'metadata_path' => 'artifacts/agents/agent_01HX/metadata.json',
                'events_path' => 'artifacts/agents/agent_01HX/events.jsonl',
                'state_path' => 'artifacts/agents/agent_01HX/state.json',
            ]],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown status');

        $this->registry->list($parentRunId);
    }

    public function testRegistryWithMismatchedSchemaVersionThrows(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
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

        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
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

        $agentsDir = $this->registry->resolveArtifactsBasePath($parentRunId);
        mkdir($agentsDir, 0777, true);
        file_put_contents($agentsDir.'/registry.json', json_encode([
            'schema_version' => 1,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required "entries"');

        $this->registry->list($parentRunId);
    }
}
