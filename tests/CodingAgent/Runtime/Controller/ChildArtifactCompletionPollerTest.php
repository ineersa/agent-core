<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildArtifactLaunchContextStore;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\ChildArtifactCompletionPoller;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
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
 * Test thesis: ChildArtifactCompletionPoller finalizes terminal fork artifacts,
 * writes handoff, appends parent subagent_progress, advances parent seq, and
 * sends [FORK_DONE] append_message notifications.
 */
#[CoversClass(ChildArtifactCompletionPoller::class)]
final class ChildArtifactCompletionPollerTest extends TestCase
{
    private string $projectDir;

    /** @var array<array{string, UserCommand}> */
    private array $sentCommands = [];

    /** @var list<RunEvent> */
    private array $appendedEvents = [];

    private AgentArtifactPathResolver $pathResolver;

    private AgentArtifactRegistry $registry;

    private AgentChildArtifactLaunchContextStore $launchContextStore;

    private InMemoryRunStore $parentRunStore;

    private InMemoryRunStore $childRunStore;

    private string $sessionId;

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentCommands = [];
        $this->appendedEvents = [];

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-child-artifact-poller');
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

        $this->pathResolver = new AgentArtifactPathResolver($hatfieldSessionStore);

        $this->registry = new AgentArtifactRegistry(
            pathResolver: $this->pathResolver,
            serializer: $serializer,
            validator: $validator,
            lockFactory: new LockFactory(new FlockStore()),
        );

        $this->launchContextStore = new AgentChildArtifactLaunchContextStore($this->pathResolver);

        $this->parentRunStore = new InMemoryRunStore();
        $this->childRunStore = new InMemoryRunStore();

        $this->clock = new MockClock(new \DateTimeImmutable('2026-07-07T12:00:00Z'));

        $this->sessionId = 'parent-session-'.bin2hex(random_bytes(4));
        $_SERVER['HATFIELD_SESSION_ID'] = $this->sessionId;
        $_ENV['HATFIELD_SESSION_ID'] = $this->sessionId;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HATFIELD_SESSION_ID'], $_ENV['HATFIELD_SESSION_ID']);
        TestDirectoryIsolation::removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function testPollOnceFinalizesCompletedForkArtifact(): void
    {
        $parentRunId = $this->sessionId;
        $artifactId = 'agent_fork_01';
        $agentRunId = 'child-run-'.bin2hex(random_bytes(8));
        $assistantHandoff = 'Fork finished implementation summary';

        $this->seedParentRun($parentRunId);
        $this->seedForkArtifact($parentRunId, $artifactId, $agentRunId, AgentArtifactStatusEnum::Running);
        $this->writeLaunchContext($parentRunId, $artifactId);

        $this->childRunStore->compareAndSwap(new RunState(
            runId: $agentRunId,
            status: RunStatus::Completed,
            version: 1,
            lastSeq: 5,
            turnNo: 2,
            messages: [
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => $assistantHandoff]]),
            ],
        ), 0);

        $poller = $this->createPoller();
        $poller->pollOnce();

        $entry = $this->registry->get($parentRunId, $artifactId);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        $this->assertSame($assistantHandoff, $entry->summary);

        $handoff = $this->registry->readHandoff($parentRunId, $artifactId);
        $this->assertStringContainsString('## Result', $handoff);
        $this->assertStringContainsString($assistantHandoff, $handoff);

        $this->assertCount(1, $this->appendedEvents);
        $event = $this->appendedEvents[0];
        $this->assertSame(RunEventTypeEnum::ToolExecutionUpdate->value, $event->type);
        $this->assertSame($parentRunId, $event->runId);
        $this->assertSame(1, $event->seq);
        $this->assertSame('call_fork_parent', $event->payload['tool_call_id'] ?? null);
        $this->assertSame('fork', $event->payload['tool_name'] ?? null);
        $progress = $event->payload['subagent_progress'] ?? null;
        $this->assertIsArray($progress);
        $this->assertSame('completed', $progress['status'] ?? null);
        $this->assertSame('fork', $progress['agent_name'] ?? null);

        $parentState = $this->parentRunStore->get($parentRunId);
        $this->assertNotNull($parentState);
        $this->assertSame(1, $parentState->lastSeq);

        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame($this->sessionId, $runId);
        $this->assertSame('append_message', $command->type);
        $this->assertStringContainsString('[FORK_DONE]', $command->text ?? '');
        $this->assertStringContainsString($agentRunId, $command->text ?? '');
        $this->assertStringContainsString('agent_retrieve', $command->text ?? '');

        // Idempotent: second poll must not duplicate terminal side effects.
        $poller->pollOnce();
        $this->assertCount(1, $this->appendedEvents);
        $this->assertCount(1, $this->sentCommands);
    }

    public function testPollOnceEmitsRunningProgressForActiveChild(): void
    {
        $parentRunId = $this->sessionId;
        $artifactId = 'agent_fork_running';
        $agentRunId = 'child-run-active';

        $this->seedParentRun($parentRunId);
        $this->seedForkArtifact($parentRunId, $artifactId, $agentRunId, AgentArtifactStatusEnum::Running);
        $this->writeLaunchContext($parentRunId, $artifactId);

        $this->childRunStore->compareAndSwap(new RunState(
            runId: $agentRunId,
            status: RunStatus::Running,
            version: 1,
            lastSeq: 3,
            turnNo: 1,
        ), 0);

        $poller = $this->createPoller();
        $poller->pollOnce();

        $this->assertCount(1, $this->appendedEvents);
        $progress = $this->appendedEvents[0]->payload['subagent_progress'] ?? null;
        $this->assertIsArray($progress);
        $this->assertSame('running', $progress['status'] ?? null);

        $entry = $this->registry->get($parentRunId, $artifactId);
        $this->assertSame(AgentArtifactStatusEnum::Running, $entry?->status);
        $this->assertEmpty($this->sentCommands);
    }

    public function testPollOnceMarksNeedsClarificationForWaitingHuman(): void
    {
        $parentRunId = $this->sessionId;
        $artifactId = 'agent_fork_waiting';
        $agentRunId = 'child-run-waiting';

        $this->seedParentRun($parentRunId);
        $this->seedForkArtifact($parentRunId, $artifactId, $agentRunId, AgentArtifactStatusEnum::Running);
        $this->writeLaunchContext($parentRunId, $artifactId);

        $this->childRunStore->compareAndSwap(new RunState(
            runId: $agentRunId,
            status: RunStatus::WaitingHuman,
            version: 1,
            lastSeq: 7,
            turnNo: 1,
        ), 0);

        $poller = $this->createPoller();
        $poller->pollOnce();

        $entry = $this->registry->get($parentRunId, $artifactId);
        $this->assertSame(AgentArtifactStatusEnum::NeedsClarification, $entry?->status);

        $progress = $this->appendedEvents[0]->payload['subagent_progress'] ?? null;
        $this->assertIsArray($progress);
        $this->assertSame('waiting_human', $progress['status'] ?? null);
        $this->assertEmpty($this->sentCommands);
    }

    private function seedParentRun(string $parentRunId): void
    {
        $this->parentRunStore->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            lastSeq: 0,
            turnNo: 1,
        ), 0);
    }

    private function seedForkArtifact(
        string $parentRunId,
        string $artifactId,
        string $agentRunId,
        AgentArtifactStatusEnum $status,
    ): void {
        $this->registry->create(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: 'fork',
            kind: AgentArtifactKindEnum::Fork,
        );

        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$parentRunId.'/artifacts/agents/'.$artifactId.'/events.jsonl';
        if (!is_file($eventsPath)) {
            file_put_contents($eventsPath, '');
        }

        if (AgentArtifactStatusEnum::Pending !== $status) {
            $this->registry->update(

                parentRunId: $parentRunId,
                artifactId: $artifactId,
                status: $status,
                startedAt: new \DateTimeImmutable(),
            );
        }
    }

    private function clockStartedMicros(): int
    {
        $instant = $this->clock->now();
        $seconds = (int) $instant->format('U');
        $micro = (int) $instant->format('u');

        return ($seconds * 1_000_000) + $micro;
    }

    private function writeLaunchContext(string $parentRunId, string $artifactId): void
    {
        $this->launchContextStore->write($parentRunId, $artifactId, [
            'parent_tool_call_id' => 'call_fork_parent',
            'parent_turn_no' => 1,
            'parent_tool_name' => 'fork',
            'task_summary' => 'Implement fork poller test',
            'agent_name' => 'fork',
            'resolved_model' => 'test-model',
            'progress_started_micros' => $this->clockStartedMicros(),
        ]);
    }

    private function createPoller(): ChildArtifactCompletionPoller
    {
        $progressBuilder = new SubagentProgressSnapshotBuilder();

        $childEventStoreFactory = new \Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory(
            pathResolver: $this->pathResolver,
            eventPayloadNormalizer: new \Ineersa\AgentCore\Schema\EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
        );
        $summaryBuilder = new SubagentChildProgressSummaryBuilder($childEventStoreFactory);

        $sessionClient = new class($this->sentCommands) implements AgentSessionClient {
            /** @param array<array{string, UserCommand}> $sentCommands */
            public function __construct(private array &$sentCommands)
            {
            }

            public function start(\Ineersa\CodingAgent\Runtime\Contract\StartRunRequest $request): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected');
            }

            public function attach(string $runId): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected');
            }

            public function send(string $runId, UserCommand $command): void
            {
                $this->sentCommands[] = [$runId, $command];
            }

            public function events(string $runId): iterable
            {
                return [];
            }

            public function cancel(string $runId): void
            {
            }

            public function shellExecute(string $command, string $sessionId, string $cwd): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected');
            }

            public function completeRun(string $runId): void
            {
            }

            public function compact(string $runId, ?string $customInstructions = null): void
            {
            }
        };

        $eventStore = new class($this->appendedEvents) implements EventStoreInterface {
            /** @param list<RunEvent> $appendedEvents */
            public function __construct(private array &$appendedEvents)
            {
            }

            public function append(RunEvent $event): void
            {
                $this->appendedEvents[] = $event;
            }

            public function appendMany(array $events): void
            {
                foreach ($events as $event) {
                    $this->append($event);
                }
            }

            public function allFor(string $runId): array
            {
                $matched = [];
                foreach ($this->appendedEvents as $event) {
                    if ($event->runId === $runId) {
                        $matched[] = $event;
                    }
                }

                return $matched;
            }
        };

        $agentRunner = $this->createStub(AgentRunnerInterface::class);

        return new ChildArtifactCompletionPoller(
            artifactRegistry: $this->registry,
            launchContextStore: $this->launchContextStore,
            runStore: $this->childRunStore,
            parentRunStore: $this->parentRunStore,
            eventStore: $eventStore,
            agentRunner: $agentRunner,
            sessionClient: $sessionClient,
            progressSnapshotBuilder: $progressBuilder,
            childProgressSummaryBuilder: $summaryBuilder,
            agentsConfig: new AgentsConfig(subagentToolTimeoutSeconds: 3600),
            logger: new NullLogger(),
            clock: $this->clock,
        );
    }
}
