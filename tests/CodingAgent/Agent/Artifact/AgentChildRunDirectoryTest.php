<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\LoggerInterface;
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
 * Tests for AgentChildRunDirectory — proves directory discovers child
 * artifacts and rescans on cache misses in long-lived processes.
 *
 * Test thesis: after a child artifact is created in a parent session,
 * AgentChildRunDirectory::locate() finds it via a session/registry scan.
 * When a second child artifact is created later (in the same process,
 * without calling register()), a subsequent locate() on the new runId
 * rescans and discovers it — proving there is no stale one-time scan
 * flag that would permanently miss later-created children.
 */
final class AgentChildRunDirectoryTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $hatfieldSessionStore;
    private AgentArtifactRegistry $registry;
    private AgentChildRunDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->hatfieldSessionStore = $store;

        $serializer = new Serializer(
            [
                new DateTimeNormalizer(),
                new BackedEnumNormalizer(),
                new ObjectNormalizer(
                    nameConverter: new CamelCaseToSnakeCaseNameConverter(),
                ),
            ],
            [new JsonEncoder()],
        );

        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();

        $pathResolver = new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($this->hatfieldSessionStore));

        $this->registry = new AgentArtifactRegistry(
            pathResolver: $pathResolver,
            serializer: $serializer,
            validator: $validator,
            lockFactory: new LockFactory(new FlockStore()),
        );

        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get('logger');

        $this->directory = new AgentChildRunDirectory(
            $this->hatfieldSessionStore,
            $this->registry,
            $logger,
        );
    }

    /**
     * Prove that a child artifact created via the registry is locatable.
     */
    public function testLocateFindsChildArtifactAfterCreation(): void
    {
        $parentSessionId = $this->hatfieldSessionStore->createSession('Locator test parent');

        $childAgentRunId = 'directory-child-1-'.bin2hex(random_bytes(4));
        $entry = $this->registry->create($parentSessionId, 'scout-001', $childAgentRunId, 'scout', AgentArtifactKindEnum::Subagent);

        // Locate should find the entry (requires a session scan since we
        // did not call register()).
        $found = $this->directory->locate($childAgentRunId);

        $this->assertNotNull($found, 'Locator must find child artifact after creation');
        $this->assertSame($childAgentRunId, $found->agentRunId);
        $this->assertSame($parentSessionId, $found->parentRunId);
        $this->assertSame($entry->artifactId, $found->artifactId);
    }

    /**
     * Prove that a later-created child artifact is discovered on a
     * subsequent locate() call — i.e. no stale one-time scan flag
     * permanently hides it.
     */
    public function testLocateRescansAndFindsLateChildArtifact(): void
    {
        $parentSessionId = $this->hatfieldSessionStore->createSession('Locator rescan parent');

        // Create and locate child-1 (warms the cache with child-1 entry).
        $child1RunId = 'directory-rescan-1-'.bin2hex(random_bytes(4));
        $this->registry->create($parentSessionId, 'rescan-artifact-1', $child1RunId, 'scout', AgentArtifactKindEnum::Subagent);

        $found1 = $this->directory->locate($child1RunId);
        $this->assertNotNull($found1, 'First child must be locatable');
        $this->assertSame($child1RunId, $found1->agentRunId);

        // Now create a second child (different agentRunId and artifactId) —
        // do NOT call register(). If the directory has a stale-scanned flag,
        // locate(child-2) would return null.
        $child2RunId = 'directory-rescan-2-'.bin2hex(random_bytes(4));
        $this->registry->create($parentSessionId, 'rescan-artifact-2', $child2RunId, 'scout', AgentArtifactKindEnum::Subagent);

        $found2 = $this->directory->locate($child2RunId);

        $this->assertNotNull(
            $found2,
            'Locator must rescan and find late-created child artifact — '
            .'stale process-wide scanned flag would hide it',
        );
        $this->assertSame($child2RunId, $found2->agentRunId, 'Late child must have correct agentRunId');

        // child-1 should still be in the cache (fast-path, no rescan needed).
        $found1Again = $this->directory->locate($child1RunId);
        $this->assertNotNull($found1Again, 'First child must remain in cache after second locate');
        $this->assertSame($child1RunId, $found1Again->agentRunId);
    }

    /**
     * Prove that pre-registering an entry avoids the scan entirely on
     * the first lookup (fast path for SubagentExecutionService).
     */
    public function testRegisterMakesEntryImmediatelyLocatable(): void
    {
        $parentSessionId = $this->hatfieldSessionStore->createSession('Locator register parent');

        $childAgentRunId = 'directory-reg-'.bin2hex(random_bytes(4));
        $entry = $this->registry->create($parentSessionId, 'scout-003', $childAgentRunId, 'scout', AgentArtifactKindEnum::Subagent);

        // Register directly — the directory should use the cached entry
        // without scanning.
        $this->directory->register($entry);

        $found = $this->directory->locate($childAgentRunId);
        $this->assertNotNull($found, 'Pre-registered entry must be locatable without scan');
        $this->assertSame($entry->artifactId, $found->artifactId);
    }

    /**
     * Prove that locate() returns null for a genuinely unknown runId.
     */
    public function testLocateReturnsNullForUnknownRunId(): void
    {
        $result = $this->directory->locate('nonexistent-child-run-id');
        $this->assertNull($result, 'Locator must return null for unknown run IDs');
    }

    /**
     * Prove that corrupted/unreadable registry for one parent does not
     * block location of child runs in other parents.
     *
     * Creates one good parent (with artifacts) and a second parent
     * whose registry directory permissions prevent reading. The directory
     * must still find the good parent's artifacts.
     */
    public function testCorruptRegistryDoesNotBlockOtherParents(): void
    {
        $goodParentId = $this->hatfieldSessionStore->createSession('Locator good parent');
        $badParentId = $this->hatfieldSessionStore->createSession('Locator bad parent');

        // Create a child in the good parent.
        $childRunId = 'directory-good-'.bin2hex(random_bytes(4));
        $entry = $this->registry->create($goodParentId, 'worker-001', $childRunId, 'worker', AgentArtifactKindEnum::Subagent);

        // Corrupt the bad parent's registry.json by writing invalid JSON.
        // This makes AgentArtifactRegistry::list() throw, which the
        // directory's scanAllSessions() catches and skips.  No chmod/permission
        // tricks needed — portable across filesystems and CI environments.
        $pathResolver = new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($this->hatfieldSessionStore));
        $badRegistryPath = $pathResolver->registryPath($badParentId);
        $badRegistryDir = \dirname($badRegistryPath);

        if (!is_dir($badRegistryDir)) {
            mkdir($badRegistryDir, 0755, true);
        }
        // Write a file that is a file but not valid JSON — triggers
        // JsonException in loadRegistry().
        file_put_contents($badRegistryPath, 'NOT JSON {{{');

        // Locating the good child must still succeed — the corrupt
        // parent's failure is logged and skipped, not propagated.
        $found = $this->directory->locate($childRunId);
        $this->assertNotNull(
            $found,
            'Locator must find child artifacts in healthy parents even '
            .'when another parent has a corrupt registry',
        );
        $this->assertSame($childRunId, $found->agentRunId);
    }
}
