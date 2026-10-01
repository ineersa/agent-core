<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRetrievalService;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\AgentRetrieveArgumentsDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\EventInspectionSummarizer;
use Ineersa\CodingAgent\Session\History\HistoryDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Session\History\InMemoryHistoryProjectionStore;
use Ineersa\CodingAgent\Tests\Session\RunState\InMemoryRunStateStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
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

#[CoversClass(AgentArtifactRetrievalService::class)]
final class AgentArtifactRetrievalServiceTest extends IsolatedKernelTestCase
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
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                classMetadataFactory: ($__cmf = new ClassMetadataFactory(new AttributeLoader())),
                nameConverter: new MetadataAwareNameConverter($__cmf, new CamelCaseToSnakeCaseNameConverter()),
            )],
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

        $this->directory = new AgentChildRunDirectory(
            hatfieldSessionStore: $this->hatfieldSessionStore,
            artifactRegistry: $this->registry,
            logger: self::getContainer()->get('logger'),
        );
    }

    public function testRetrievesCompletedHandoffByArtifactId(): void
    {
        $parent = 'parent-a';
        $artifactId = 'agent_done';
        $childRun = 'child-run-1';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'ok');
        $this->registry->writeHandoff($parent, $artifactId, "## Result\n\nFound routing config.");

        $service = $this->makeService();
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'handoff']));

        $this->assertStringContainsString('artifact_id: agent_done', $out);
        $this->assertStringContainsString('Found routing config.', $out);
        $this->assertStringContainsString('status: completed', $out);
    }

    public function testRetrievesFailedMetadataWithFailureReason(): void
    {
        $parent = 'parent-b';
        $artifactId = 'agent_fail';
        $childRun = 'child-run-2';
        $this->registry->create($parent, $artifactId, $childRun, 'reviewer', AgentArtifactKindEnum::Subagent);
        $this->registry->update(
            $parent,
            $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: 'Child attempted unsupported human interaction.',
        );

        $service = $this->makeService(historyProjectionStore: $this->seedEventInspection($childRun, 0));
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'metadata']));

        $this->assertStringContainsString('status: failed', $out);
        $this->assertStringContainsString('failure_reason: Child attempted unsupported human interaction.', $out);
    }

    public function testRetrievesNeedsClarificationMetadataWhenReservedStatusSet(): void
    {
        $parent = 'parent-c';
        $artifactId = 'agent_nc';
        $childRun = 'child-run-3';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->update(
            $parent,
            $artifactId,
            status: AgentArtifactStatusEnum::NeedsClarification,
            needsClarification: 'Reserved future interactive mode note.',
        );

        $service = $this->makeService(historyProjectionStore: $this->seedEventInspection($childRun, 0));
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'metadata']));

        $this->assertStringContainsString('status: needs_clarification', $out);
        $this->assertStringContainsString('needs_clarification: Reserved future interactive mode note.', $out);
    }

    public function testResolvesByAgentRunIdInCurrentParent(): void
    {
        $parent = 'parent-d';
        $artifactId = 'agent_by_run';
        $childRun = 'uuid-child-99';
        $this->registry->create($parent, $artifactId, $childRun, 'worker', AgentArtifactKindEnum::Subagent);
        $this->registry->writeHandoff($parent, $artifactId, 'handoff-by-run');

        $service = $this->makeService();
        $out = $service->retrieve($parent, $this->args(['agent_run_id' => $childRun, 'mode' => 'handoff']));

        $this->assertStringContainsString('artifact_id: agent_by_run', $out);
        $this->assertStringContainsString('handoff-by-run', $out);
    }

    public function testRejectsMissingIdentifiers(): void
    {
        $service = $this->makeService();

        try {
            // Identifier presence is validated by AgentRetrieveArgumentsDTO constraints
            // before the service in production; service still fails closed without ids.
            $service->retrieve('parent-x', $this->args([]));
            $this->fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Unable to resolve subagent artifact', $e->getMessage());
        }
    }

    public function testRejectsUnknownArtifactId(): void
    {
        $service = $this->makeService();

        try {
            $service->retrieve('parent-x', $this->args(['artifact_id' => 'missing']));
            $this->fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Unknown artifact_id', $e->getMessage());
        }
    }

    public function testRejectsCrossParentAgentRunId(): void
    {
        $otherParent = 'parent-other';
        $artifactId = 'agent_foreign';
        $childRun = 'foreign-child';
        $this->registry->create($otherParent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);
        $this->directory->register($this->registry->get($otherParent, $artifactId));

        $service = $this->makeService();

        try {
            $service->retrieve('parent-current', $this->args(['agent_run_id' => $childRun]));
            $this->fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('different parent session', $e->getMessage());
        }
    }

    public function testRejectsPathTraversalArtifactId(): void
    {
        $service = $this->makeService();

        try {
            $service->retrieve('parent-1', $this->args(['artifact_id' => '../secret']));
            $this->fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('artifactId', $e->getMessage());
        }
    }

    public function testRejectsMismatchedArtifactIdAndAgentRunId(): void
    {
        $parent = 'parent-e';
        $this->registry->create($parent, 'artifact-one', 'run-one', 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->create($parent, 'artifact-two', 'run-two', 'scout', AgentArtifactKindEnum::Subagent);

        $service = $this->makeService();

        try {
            $service->retrieve($parent, $this->args(['artifact_id' => 'artifact-one', 'agent_run_id' => 'run-two']));
            $this->fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('different subagent artifacts', $e->getMessage());
        }
    }

    public function testBoundedEventsOmitRawPayloadSecrets(): void
    {
        $parent = 'parent-f';
        $artifactId = 'agent_events';
        $childRun = 'child-events';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);

        $secret = 'RAW_TOOL_OUTPUT_SECRET_12345';
        /** @var ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec */
        $toolExecutionEndPayloadCodec = self::getContainer()->get(ToolExecutionEndPayloadCodec::class);
        $summarizer = new EventInspectionSummarizer($toolExecutionEndPayloadCodec);
        $tail = [];
        for ($i = 1; $i <= 25; ++$i) {
            $event = new RunEvent(
                runId: $childRun,
                seq: $i,
                turnNo: 0,
                type: RunEventTypeEnum::ToolExecutionEnd->value,
                payload: $toolExecutionEndPayloadCodec->toEventPayload(new ToolCallResult(
                    runId: $childRun,
                    turnNo: 0,
                    stepId: 'tool-step',
                    attempt: 1,
                    idempotencyKey: 'tool-result-'.$i,
                    toolCallId: 'call-'.$i,
                    orderIndex: $i,
                    result: ['tool_name' => 'bash', 'output' => $secret.'-'.$i],
                )),
                createdAt: new \DateTimeImmutable('@'.(1_700_000_000 + $i)),
            );
            $tail[] = $summarizer->summarize($event);
        }

        $historyStore = new InMemoryHistoryProjectionStore();
        $historyStore->remember($childRun, new HistoryProjectionSnapshot(
            history: new HistoryDTO([], [], 0),
            lastSeq: 25,
            eventCount: 25,
            sanitizedEventTail: $tail,
        ));
        $service = $this->makeService(historyProjectionStore: $historyStore);
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'events', 'limit' => 5]));

        $this->assertStringContainsString('Showing last 5 of 25 events', $out);
        $this->assertStringNotContainsString($secret, $out);
        $this->assertStringNotContainsString($secret.'-1', $out);
        $this->assertStringContainsString('tool end: bash', $out);
    }

    public function testMetadataAndEventsUseMaintainedProjectionWithoutArchiveReads(): void
    {
        $parent = 'parent-projection';
        $artifactId = 'agent_projection';
        $childRun = 'child-projection';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);

        $historyStore = new InMemoryHistoryProjectionStore();
        $historyStore->initializeFromEvents($childRun, [
            new RunEvent(
                runId: $childRun,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: ['step_id' => 's1', 'payload' => ['messages' => []]],
                createdAt: new \DateTimeImmutable('@1700000001'),
            ),
            new RunEvent(
                runId: $childRun,
                seq: 5,
                turnNo: 1,
                type: RunEventTypeEnum::LlmStepCompleted->value,
                payload: ['payload' => ['tool_calls' => []]],
                createdAt: new \DateTimeImmutable('@1700000005'),
            ),
        ]);

        $service = $this->makeService(historyProjectionStore: $historyStore);
        $meta = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'metadata']));
        $events = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'events', 'limit' => 10]));

        $this->assertStringContainsString('event_count: 2', $meta);
        $this->assertStringContainsString('Showing last 2 of 2 events', $events);
        $this->assertStringContainsString('type=llm_step_completed', $events);
        $this->assertSame(1, $historyStore->initializeFromEventsCalls);
        $this->assertSame(2, $historyStore->getCalls);
    }

    public function testBoundedHistorySkipsSystemAndOmitsRawText(): void
    {
        $parent = 'parent-g';
        $artifactId = 'agent_hist';
        $childRun = 'child-hist';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);

        $secret = 'FULL_PROMPT_SECRET_XYZ';
        $toolSecret = 'RAW_TOOL_OUTPUT_HISTORY_SECRET_999';
        $messages = [
            new AgentMessage(role: 'system', content: [['type' => 'text', 'text' => $secret]]),
            new AgentMessage(role: 'user-context', content: [['type' => 'text', 'text' => 'agents md context']]),
            new AgentMessage(role: 'tool', content: [['type' => 'text', 'text' => $toolSecret]], toolName: 'read'),
        ];
        for ($i = 0; $i < 30; ++$i) {
            $messages[] = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'short user message '.$i]]);
        }

        $state = new RunState(runId: $childRun, status: RunStatus::Completed, messages: $messages, model: 'test-model');
        $store = new InMemoryRunStateStore();
        $store->initialize($state);
        $service = $this->makeService(runStateStore: $store);
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'history', 'limit' => 3]));

        $this->assertStringContainsString('Showing last 3 of', $out);
        $this->assertStringNotContainsString($secret, $out);
        $this->assertStringNotContainsString($toolSecret, $out);
        $this->assertStringNotContainsString('role=system', $out);
        $this->assertStringNotContainsString('role=user-context', $out);
        $this->assertStringNotContainsString('role=tool', $out);
    }

    public function testDebugModeEmitsRelativeArtifactPathsOnly(): void
    {
        $parent = 'parent-debug';
        $artifactId = 'agent_debug_paths';
        $childRun = 'child-debug-run';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);

        $isolatedRoot = (string) getcwd();
        $this->assertNotSame('', $isolatedRoot);

        $service = $this->makeService();
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'debug']));

        $this->assertStringContainsString('# Subagent artifact debug paths', $out);
        $this->assertStringContainsString('artifacts/agents/'.$artifactId.'/', $out);
        $this->assertStringContainsString('- artifact_dir: artifacts/agents/'.$artifactId, $out);
        $this->assertStringContainsString('- metadata_path: artifacts/agents/'.$artifactId.'/metadata.json', $out);
        $this->assertStringNotContainsString('handoff_path', $out);
        $this->assertStringContainsString('- events_path: artifacts/agents/'.$artifactId.'/events.jsonl', $out);
        $this->assertStringNotContainsString($isolatedRoot, $out);
        $this->assertStringNotContainsString($isolatedRoot.'/.hatfield/sessions', $out);
    }

    public function testRetrievesCancelledHandoffWithPartialContext(): void
    {
        $parent = 'parent-cancel-retrieve';
        $artifactId = 'agent_cancel_handoff';
        $childRun = 'child-cancel-retrieve';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->update($parent, $artifactId, status: AgentArtifactStatusEnum::Cancelled, summary: 'Cancelled by parent run.');
        $this->registry->writeHandoff($parent, $artifactId, "# Subagent handoff\n\nStatus: cancelled\nArtifact: {$artifactId}\n\n## Partial context\n\n- turn_no: 2\n\n## Retrieval\n\nUse agent_retrieve");

        $service = $this->makeService();
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'handoff']));

        $this->assertStringContainsString('status: cancelled', $out);
        $this->assertStringContainsString('artifact_id: '.$artifactId, $out);
        $this->assertStringContainsString('Partial context', $out);
    }

    public function testRetrievesCancelledMetadata(): void
    {
        $parent = 'parent-cancel-meta';
        $artifactId = 'agent_cancel_meta';
        $childRun = 'child-cancel-meta';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry->update($parent, $artifactId, status: AgentArtifactStatusEnum::Cancelled, summary: 'Child run was cancelled.');

        $state = new RunState(
            runId: $childRun,
            status: RunStatus::Cancelled,
            version: 1,
            turnNo: 4,
            lastSeq: 18,
            messages: [],
            model: 'test-model');
        $store = new InMemoryRunStateStore();
        $store->initialize($state);

        $service = $this->makeService(
            runStateStore: $store,
            historyProjectionStore: $this->seedEventInspection($childRun, 18),
        );
        $out = $service->retrieve($parent, $this->args(['artifact_id' => $artifactId, 'mode' => 'metadata']));

        $this->assertStringContainsString('status: cancelled', $out);
        $this->assertStringContainsString('turn_no: 4', $out);
        $this->assertStringContainsString('last_seq: 18', $out);
    }

    public function testHandoffHistoryListsAndFetchesByHandoffId(): void
    {
        $parent = 'parent-handoff-history';
        $artifactId = 'agent_hist_retrieve';
        $childRun = 'child-hist-retrieve';
        $this->registry->create($parent, $artifactId, $childRun, 'scout', AgentArtifactKindEnum::Subagent);
        $firstId = $this->registry->writeHandoff(
            $parent,
            $artifactId,
            '# First archive body',
            status: AgentArtifactStatusEnum::Completed,
            summary: 'first done',
        );
        $this->registry->writeHandoff(
            $parent,
            $artifactId,
            '# Latest handoff body',
            status: AgentArtifactStatusEnum::Completed,
            summary: 'second done',
        );

        $service = $this->makeService();

        $list = $service->retrieve($parent, $this->args([
            'artifact_id' => $artifactId,
            'mode' => 'handoff_history',
        ]));
        $this->assertStringContainsString('Handoffs (oldest → newest)', $list);
        $this->assertStringContainsString('id='.$firstId, $list);
        $this->assertStringContainsString('status=completed', $list);
        $this->assertStringContainsString('first done', $list);
        $this->assertStringNotContainsString('# Latest handoff body', $list);

        $body = $service->retrieve($parent, $this->args([
            'artifact_id' => $artifactId,
            'mode' => 'handoff_history',
            'handoff_id' => $firstId,
        ]));
        $this->assertStringContainsString('## Handoff '.$firstId, $body);
        $this->assertStringContainsString('# First archive body', $body);

        $latest = $service->retrieve($parent, $this->args([
            'artifact_id' => $artifactId,
            'mode' => 'handoff',
        ]));
        $this->assertStringContainsString('# Latest handoff body', $latest);
    }

    private function makeService(
        ?RunStateStoreInterface $runStateStore = null,
        ?HistoryProjectionStoreInterface $historyProjectionStore = null,
    ): AgentArtifactRetrievalService {
        return new AgentArtifactRetrievalService(
            artifactRegistry: $this->registry,
            childRunDirectory: $this->directory,
            runStateStore: $runStateStore ?? new InMemoryRunStateStore(),
            historyProjectionStore: $historyProjectionStore ?? new InMemoryHistoryProjectionStore(),
            logger: self::getContainer()->get('logger'),
        );
    }

    /**
     * @param list<array{seq: int, turn_no: int, type: string, created_at: string, summary: string}> $tail
     */
    private function seedEventInspection(string $runId, int $eventCount, array $tail = []): InMemoryHistoryProjectionStore
    {
        $store = new InMemoryHistoryProjectionStore();
        $store->remember($runId, new HistoryProjectionSnapshot(
            history: new HistoryDTO([], [], 0),
            lastSeq: max(0, $eventCount),
            eventCount: $eventCount,
            sanitizedEventTail: $tail,
        ));

        return $store;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function args(array $arguments): AgentRetrieveArgumentsDTO
    {
        return new AgentRetrieveArgumentsDTO(
            artifact_id: isset($arguments['artifact_id']) && \is_string($arguments['artifact_id']) ? $arguments['artifact_id'] : null,
            agent_run_id: isset($arguments['agent_run_id']) && \is_string($arguments['agent_run_id']) ? $arguments['agent_run_id'] : null,
            mode: isset($arguments['mode']) && \is_string($arguments['mode']) ? $arguments['mode'] : null,
            limit: isset($arguments['limit']) && \is_int($arguments['limit']) ? $arguments['limit'] : null,
            handoff_id: isset($arguments['handoff_id']) && \is_string($arguments['handoff_id']) ? $arguments['handoff_id'] : null,
        );
    }
}
