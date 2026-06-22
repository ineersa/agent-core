<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * DB-backed integration test proving that child agent artifacts do not
 * pollute normal session listing.
 *
 * Test thesis: HatfieldSessionStore::listSessions() queries the
 * hatfield_session DB table.  Since child agent runs are file-backed
 * only (no DB row), they are naturally excluded from session catalogs
 * and pickers without any runtime filtering.
 *
 * Uses IsolatedKernelTestCase to access the real EntityManager-backed
 * HatfieldSessionStore, so createSession() produces a real DB row.
 */
final class AgentArtifactSessionListingTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $hatfieldSessionStore;
    private AgentArtifactRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->hatfieldSessionStore = $store;

        $this->registry = new AgentArtifactRegistry(
            hatfieldSessionStore: $this->hatfieldSessionStore,
            lockFactory: new LockFactory(new FlockStore()),
        );
    }

    public function testChildArtifactsDoNotAppearInSessionListing(): void
    {
        // Create a real parent session (DB row + directory).
        $parentSessionId = $this->hatfieldSessionStore->createSession('Test parent session');

        // Create a child artifact/run under the parent.
        $childAgentRunId = 'child-run-'.bin2hex(random_bytes(4));
        $this->registry->create($parentSessionId, 'scout-001', $childAgentRunId, 'scout');

        // Verify the session listing includes the parent.
        $sessions = $this->hatfieldSessionStore->listSessions();
        $sessionIds = array_column($sessions, 'sessionId');

        self::assertContains($parentSessionId, $sessionIds, 'Parent session must appear in listing');

        // Child agentRunId must NOT be in the session listing — it was
        // never created as a DB row.
        self::assertNotContains($childAgentRunId, $sessionIds, 'Child agent run must not pollute session listing');

        // Also verify the child agentRunId does not report as existing
        // in the session store.
        self::assertFalse($this->hatfieldSessionStore->exists($childAgentRunId));
    }
}
