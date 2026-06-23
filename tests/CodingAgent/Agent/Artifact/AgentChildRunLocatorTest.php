<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunLocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Tests for AgentChildRunLocator — proves locator discovers child
 * artifacts and rescans on cache misses in long-lived processes.
 *
 * Test thesis: after a child artifact is created in a parent session,
 * AgentChildRunLocator::locate() finds it via a session/registry scan.
 * When a second child artifact is created later (in the same process,
 * without calling register()), a subsequent locate() on the new runId
 * rescans and discovers it — proving there is no stale one-time scan
 * flag that would permanently miss later-created children.
 */
final class AgentChildRunLocatorTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $hatfieldSessionStore;
    private AgentArtifactRegistry $registry;
    private AgentChildRunLocator $locator;

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

        $pathResolver = new AgentArtifactPathResolver($this->hatfieldSessionStore);

        $this->registry = new AgentArtifactRegistry(
            pathResolver: $pathResolver,
            serializer: $serializer,
            validator: $validator,
            lockFactory: new LockFactory(new FlockStore()),
        );

        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get('logger');

        $this->locator = new AgentChildRunLocator(
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

        $childAgentRunId = 'locator-child-1-'.bin2hex(random_bytes(4));
        $entry = $this->registry->create($parentSessionId, 'scout-001', $childAgentRunId, 'scout');

        // Locate should find the entry (requires a session scan since we
        // did not call register()).
        $found = $this->locator->locate($childAgentRunId);

        self::assertNotNull($found, 'Locator must find child artifact after creation');
        self::assertSame($childAgentRunId, $found->agentRunId);
        self::assertSame($parentSessionId, $found->parentRunId);
        self::assertSame($entry->artifactId, $found->artifactId);
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
        $child1RunId = 'locator-rescan-1-'.bin2hex(random_bytes(4));
        $this->registry->create($parentSessionId, 'rescan-artifact-1', $child1RunId, 'scout');

        $found1 = $this->locator->locate($child1RunId);
        self::assertNotNull($found1, 'First child must be locatable');
        self::assertSame($child1RunId, $found1->agentRunId);

        // Now create a second child (different agentRunId and artifactId) —
        // do NOT call register(). If the locator has a stale-scanned flag,
        // locate(child-2) would return null.
        $child2RunId = 'locator-rescan-2-'.bin2hex(random_bytes(4));
        $this->registry->create($parentSessionId, 'rescan-artifact-2', $child2RunId, 'scout');

        $found2 = $this->locator->locate($child2RunId);

        self::assertNotNull(
            $found2,
            'Locator must rescan and find late-created child artifact — '
            .'stale process-wide scanned flag would hide it',
        );
        self::assertSame($child2RunId, $found2->agentRunId, 'Late child must have correct agentRunId');

        // child-1 should still be in the cache (fast-path, no rescan needed).
        $found1Again = $this->locator->locate($child1RunId);
        self::assertNotNull($found1Again, 'First child must remain in cache after second locate');
        self::assertSame($child1RunId, $found1Again->agentRunId);
    }

    /**
     * Prove that pre-registering an entry avoids the scan entirely on
     * the first lookup (fast path for SubagentExecutionService).
     */
    public function testRegisterMakesEntryImmediatelyLocatable(): void
    {
        $parentSessionId = $this->hatfieldSessionStore->createSession('Locator register parent');

        $childAgentRunId = 'locator-reg-'.bin2hex(random_bytes(4));
        $entry = $this->registry->create($parentSessionId, 'scout-003', $childAgentRunId, 'scout');

        // Register directly — the locator should use the cached entry
        // without scanning.
        $this->locator->register($entry);

        $found = $this->locator->locate($childAgentRunId);
        self::assertNotNull($found, 'Pre-registered entry must be locatable without scan');
        self::assertSame($entry->artifactId, $found->artifactId);
    }

    /**
     * Prove that locate() returns null for a genuinely unknown runId.
     */
    public function testLocateReturnsNullForUnknownRunId(): void
    {
        $result = $this->locator->locate('nonexistent-child-run-id');
        self::assertNull($result, 'Locator must return null for unknown run IDs');
    }

    /**
     * Prove that corrupted/unreadable registry for one parent does not
     * block location of child runs in other parents.
     *
     * Creates one good parent (with artifacts) and a second parent
     * whose registry directory permissions prevent reading. The locator
     * must still find the good parent's artifacts.
     */
    public function testCorruptRegistryDoesNotBlockOtherParents(): void
    {
        $goodParentId = $this->hatfieldSessionStore->createSession('Locator good parent');
        $badParentId = $this->hatfieldSessionStore->createSession('Locator bad parent');

        // Create a child in the good parent.
        $childRunId = 'locator-good-'.bin2hex(random_bytes(4));
        $entry = $this->registry->create($goodParentId, 'worker-001', $childRunId, 'worker');

        // Corrupt the bad parent's registry directory: set permissions
        // to 0000 so registry reads fail.
        $pathResolver = new AgentArtifactPathResolver($this->hatfieldSessionStore);
        $badArtifactsDir = $pathResolver->resolveArtifactsBasePath($badParentId);

        if (!is_dir($badArtifactsDir)) {
            mkdir($badArtifactsDir, 0700, true);
        }
        chmod($badArtifactsDir, 0000);

        // Restore permissions in tearDown to allow cleanup.
        $this->addToAssertionCount(0); // Intentional: assertion is in the locate() call.

        try {
            // Locating the good child must still succeed — the corrupt
            // parent's failure is logged and skipped, not propagated.
            $found = $this->locator->locate($childRunId);
            self::assertNotNull(
                $found,
                'Locator must find child artifacts in healthy parents even '
                .'when another parent has a corrupt/unreadable registry',
            );
            self::assertSame($childRunId, $found->agentRunId);
        } finally {
            // Restore permissions for cleanup.
            if (is_dir($badArtifactsDir)) {
                chmod($badArtifactsDir, 0700);
            }
        }
    }
}
