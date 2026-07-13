<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildArtifactLifecycleAdapter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
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
 * Pending reservation lifecycle through the child-run artifact lifecycle adapter.
 *
 * Test thesis: reservePending() pre-registers the child run in AgentChildRunDirectory so
 * locate(childRunId) succeeds immediately; removePendingReservation() must drop both the
 * canonical registry row and the in-process cache entry so locate() returns null afterward.
 * Without the adapter's finally { unregister() }, a discarded Pending child would stay
 * cache-locatable even after discardPendingReservation() removed the registry row.
 */
final class AgentChildArtifactLifecycleAdapterTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $hatfieldSessionStore;
    private AgentArtifactRegistry $registry;
    private AgentChildRunDirectory $childRunDirectory;
    private AgentChildArtifactLifecycleAdapter $adapter;

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

        $this->childRunDirectory = new AgentChildRunDirectory(
            $this->hatfieldSessionStore,
            $this->registry,
            self::getContainer()->get('logger'),
        );

        $this->adapter = new AgentChildArtifactLifecycleAdapter($this->registry, $this->childRunDirectory);
    }

    public function testRemovePendingReservationClearsRegistryAndDirectoryLocate(): void
    {
        $parentRunId = $this->hatfieldSessionStore->createSession('Lifecycle adapter pending discard');
        $childRunId = 'lifecycle-pending-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_'.bin2hex(random_bytes(8));

        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: 'scout',
            taskSummary: 'pending reservation',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Subagent,
        );

        $this->adapter->reservePending($identity);

        $this->assertNotNull($this->childRunDirectory->locate($childRunId));
        $this->assertNotNull($this->registry->get($parentRunId, $artifactId));

        $artifactPathResolver = new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($this->hatfieldSessionStore));
        $this->assertDirectoryExists($artifactPathResolver->resolveArtifactDir($parentRunId, $artifactId));

        $this->adapter->removePendingReservation($identity);

        $this->assertNull($this->registry->get($parentRunId, $artifactId));
        $this->assertDirectoryDoesNotExist($artifactPathResolver->resolveArtifactDir($parentRunId, $artifactId));
        $this->assertNull(
            $this->childRunDirectory->locate($childRunId),
            'Discarded Pending child must not remain locatable via pre-registered cache entry.',
        );
    }
}
