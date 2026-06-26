<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\PendingSubagentCancellationMessageBuilder;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

final class PendingSubagentCancellationMessageBuilderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('pending-subagent-cancel');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testBuildsSingleCancellationFromLatestSubagentProgress(): void
    {
        $events = [
            new RunEvent('parent-1', 3, 1, RunEventTypeEnum::ToolExecutionUpdate->value, [
                'tool_call_id' => 'tc_sub',
                'tool_name' => 'subagent',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_abc123',
                ],
            ], new \DateTimeImmutable()),
        ];

        $builder = new PendingSubagentCancellationMessageBuilder(
            eventStore: $this->eventStoreReturning($events),
            artifactRegistry: $this->makeRegistry(),
        );

        $message = $builder->buildForPendingSubagent('parent-1', 'tc_sub');

        self::assertNotNull($message);
        self::assertStringContainsString('Subagent scout cancelled by parent run.', $message);
        self::assertStringContainsString('Artifact: agent_abc123', $message);
        self::assertStringContainsString('Status: cancelled', $message);
        self::assertStringContainsString('agent_retrieve', $message);
    }

    private function makeRegistry(): AgentArtifactRegistry
    {
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

        return new AgentArtifactRegistry(
            pathResolver: new AgentArtifactPathResolver($hatfieldSessionStore),
            serializer: $serializer,
            validator: (new ValidatorBuilder())->enableAttributeMapping()->getValidator(),
            lockFactory: new LockFactory(new FlockStore()),
        );
    }

    /**
     * @param list<RunEvent> $events
     */
    private function eventStoreReturning(array $events): \Ineersa\AgentCore\Contract\EventStoreInterface
    {
        return new class($events) implements \Ineersa\AgentCore\Contract\EventStoreInterface {
            /** @param list<RunEvent> $events */
            public function __construct(private readonly array $events)
            {
            }

            public function append(RunEvent $event): void
            {
            }

            public function appendMany(array $events): void
            {
            }

            public function allFor(string $runId): array
            {
                return array_values(array_filter(
                    $this->events,
                    static fn (RunEvent $event): bool => $event->runId === $runId,
                ));
            }
        };
    }
}
