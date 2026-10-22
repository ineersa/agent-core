<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Pending reservation lifecycle through the child-run artifact lifecycle adapter.
 *
 * reservePending() pre-registers the child run in AgentChildRunDirectory so
 * locate(childRunId) succeeds immediately alongside the canonical artifact.
 */
final class ChildRunArtifactLifecycleServiceTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $hatfieldSessionStore;
    private AgentArtifactRegistry $registry;
    private AgentChildRunDirectory $childRunDirectory;
    private ChildRunArtifactLifecycleService $adapter;

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
                    classMetadataFactory: ($__cmf = new ClassMetadataFactory(new AttributeLoader())),
                    nameConverter: new MetadataAwareNameConverter($__cmf, new CamelCaseToSnakeCaseNameConverter()),
                ),
            ],
            [new JsonEncoder()],
        );

        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();

        $pathResolver = new SessionAgentArtifactPathResolver($this->hatfieldSessionStore);

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

        $this->adapter = new ChildRunArtifactLifecycleService($this->registry, $this->childRunDirectory);
    }

    public function testPendingReservationRegistersArtifactAndDirectoryLocation(): void
    {
        $parentRunId = $this->hatfieldSessionStore->createSession('Lifecycle adapter pending reservation');
        $childRunId = 'lifecycle-pending-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_'.bin2hex(random_bytes(8));

        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: $childRunId,
            artifactId: $artifactId,
            displayName: 'scout',
            taskSummary: 'pending reservation',
            artifactKind: AgentArtifactKindEnum::Subagent,
        );

        $this->adapter->reservePending($identity);

        $this->assertNotNull($this->childRunDirectory->locate($childRunId));
        $this->assertNotNull($this->registry->get($parentRunId, $artifactId));

        $artifactPathResolver = new SessionAgentArtifactPathResolver($this->hatfieldSessionStore);
        $this->assertDirectoryExists($artifactPathResolver->resolveArtifactDir($parentRunId, $artifactId));
    }
}
