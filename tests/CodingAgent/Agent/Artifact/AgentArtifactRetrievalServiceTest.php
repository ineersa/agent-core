<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRetrievalService;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
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
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            )],
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
        $this->registry->create($parent, $artifactId, $childRun, 'scout');
        $this->registry->update($parent, $artifactId, status: AgentArtifactStatusEnum::Completed, summary: 'ok');
        $this->registry->writeHandoff($parent, $artifactId, "## Result\n\nFound routing config.");

        $service = $this->makeService();
        $out = $service->retrieve($parent, ['artifact_id' => $artifactId, 'mode' => 'handoff']);

        self::assertStringContainsString('artifact_id: agent_done', $out);
        self::assertStringContainsString('Found routing config.', $out);
        self::assertStringContainsString('status: completed', $out);
    }

    public function testRetrievesFailedMetadataWithFailureReason(): void
    {
        $parent = 'parent-b';
        $artifactId = 'agent_fail';
        $childRun = 'child-run-2';
        $this->registry->create($parent, $artifactId, $childRun, 'reviewer');
        $this->registry->update(
            $parent,
            $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: 'Child attempted unsupported human interaction.',
        );

        $service = $this->makeService();
        $out = $service->retrieve($parent, ['artifact_id' => $artifactId, 'mode' => 'metadata']);

        self::assertStringContainsString('status: failed', $out);
        self::assertStringContainsString('failure_reason: Child attempted unsupported human interaction.', $out);
    }

    public function testRetrievesNeedsClarificationMetadataWhenReservedStatusSet(): void
    {
        $parent = 'parent-c';
        $artifactId = 'agent_nc';
        $childRun = 'child-run-3';
        $this->registry->create($parent, $artifactId, $childRun, 'scout');
        $this->registry->update(
            $parent,
            $artifactId,
            status: AgentArtifactStatusEnum::NeedsClarification,
            needsClarification: 'Reserved future interactive mode note.',
        );

        $service = $this->makeService();
        $out = $service->retrieve($parent, ['artifact_id' => $artifactId, 'mode' => 'metadata']);

        self::assertStringContainsString('status: needs_clarification', $out);
        self::assertStringContainsString('needs_clarification: Reserved future interactive mode note.', $out);
    }

    public function testResolvesByAgentRunIdInCurrentParent(): void
    {
        $parent = 'parent-d';
        $artifactId = 'agent_by_run';
        $childRun = 'uuid-child-99';
        $this->registry->create($parent, $artifactId, $childRun, 'worker');
        $this->registry->writeHandoff($parent, $artifactId, 'handoff-by-run');

        $service = $this->makeService();
        $out = $service->retrieve($parent, ['agent_run_id' => $childRun, 'mode' => 'handoff']);

        self::assertStringContainsString('artifact_id: agent_by_run', $out);
        self::assertStringContainsString('handoff-by-run', $out);
    }

    public function testRejectsUnknownArtifactId(): void
    {
        $service = $this->makeService();

        try {
            $service->retrieve('parent-x', ['artifact_id' => 'missing']);
            self::fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Unknown artifact_id', $e->getMessage());
        }
    }

    public function testRejectsCrossParentAgentRunId(): void
    {
        $otherParent = 'parent-other';
        $artifactId = 'agent_foreign';
        $childRun = 'foreign-child';
        $this->registry->create($otherParent, $artifactId, $childRun, 'scout');
        $this->directory->register($this->registry->get($otherParent, $artifactId));

        $service = $this->makeService();

        try {
            $service->retrieve('parent-current', ['agent_run_id' => $childRun]);
            self::fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('different parent session', $e->getMessage());
        }
    }

    public function testRejectsPathTraversalArtifactId(): void
    {
        $service = $this->makeService();

        try {
            $service->retrieve('parent-1', ['artifact_id' => '../secret']);
            self::fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('artifactId', $e->getMessage());
        }
    }

    public function testRejectsMismatchedArtifactIdAndAgentRunId(): void
    {
        $parent = 'parent-e';
        $this->registry->create($parent, 'artifact-one', 'run-one', 'scout');
        $this->registry->create($parent, 'artifact-two', 'run-two', 'scout');

        $service = $this->makeService();

        try {
            $service->retrieve($parent, ['artifact_id' => 'artifact-one', 'agent_run_id' => 'run-two']);
            self::fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('different subagent artifacts', $e->getMessage());
        }
    }

    public function testBoundedEventsOmitRawPayloadSecrets(): void
    {
        $parent = 'parent-f';
        $artifactId = 'agent_events';
        $childRun = 'child-events';
        $this->registry->create($parent, $artifactId, $childRun, 'scout');

        $secret = 'RAW_TOOL_OUTPUT_SECRET_12345';
        $events = [];
        for ($i = 1; $i <= 25; ++$i) {
            $events[] = new RunEvent(
                runId: $childRun,
                seq: $i,
                turnNo: 0,
                type: RunEventTypeEnum::ToolExecutionEnd->value,
                payload: ['tool_name' => 'bash', 'output' => $secret.'-'.$i],
            );
        }

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects(self::once())->method('allFor')->with(self::identicalTo($childRun))->willReturn($events);
        $runStore = $this->createStub(RunStoreInterface::class);

        $service = $this->makeService($runStore, $eventStore);
        $out = $service->retrieve($parent, ['artifact_id' => $artifactId, 'mode' => 'events', 'limit' => 5]);

        self::assertStringContainsString('Showing last 5 of 25 events', $out);
        self::assertStringNotContainsString($secret, $out);
        self::assertStringNotContainsString($secret.'-1', $out);
        self::assertStringContainsString('tool end: bash', $out);
    }

    public function testBoundedHistorySkipsSystemAndOmitsRawText(): void
    {
        $parent = 'parent-g';
        $artifactId = 'agent_hist';
        $childRun = 'child-hist';
        $this->registry->create($parent, $artifactId, $childRun, 'scout');

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

        $state = new RunState(runId: $childRun, status: RunStatus::Completed, messages: $messages);
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects(self::once())->method('get')->with(self::identicalTo($childRun))->willReturn($state);
        $eventStore = $this->createStub(EventStoreInterface::class);

        $service = $this->makeService($runStore, $eventStore);
        $out = $service->retrieve($parent, ['artifact_id' => $artifactId, 'mode' => 'history', 'limit' => 3]);

        self::assertStringContainsString('Showing last 3 of', $out);
        self::assertStringNotContainsString($secret, $out);
        self::assertStringNotContainsString($toolSecret, $out);
        self::assertStringNotContainsString('role=system', $out);
        self::assertStringNotContainsString('role=user-context', $out);
        self::assertStringNotContainsString('role=tool', $out);
    }

    public function testDebugModeEmitsRelativeArtifactPathsOnly(): void
    {
        $parent = 'parent-debug';
        $artifactId = 'agent_debug_paths';
        $childRun = 'child-debug-run';
        $this->registry->create($parent, $artifactId, $childRun, 'scout');

        $isolatedRoot = (string) getcwd();
        self::assertNotSame('', $isolatedRoot);

        $service = $this->makeService();
        $out = $service->retrieve($parent, ['artifact_id' => $artifactId, 'mode' => 'debug']);

        self::assertStringContainsString('# Subagent artifact debug paths', $out);
        self::assertStringContainsString('artifacts/agents/'.$artifactId.'/', $out);
        self::assertStringContainsString('- artifact_dir: artifacts/agents/'.$artifactId, $out);
        self::assertStringContainsString('- metadata_path: artifacts/agents/'.$artifactId.'/metadata.json', $out);
        self::assertStringContainsString('- handoff_path: artifacts/agents/'.$artifactId.'/handoff.md', $out);
        self::assertStringContainsString('- events_path: artifacts/agents/'.$artifactId.'/events.jsonl', $out);
        self::assertStringContainsString('- state_path: artifacts/agents/'.$artifactId.'/state.json', $out);
        self::assertStringNotContainsString($isolatedRoot, $out);
        self::assertStringNotContainsString($isolatedRoot.'/.hatfield/sessions', $out);
    }

    public function testRejectsCrossParentArtifactIdViaSessionListing(): void
    {
        $foreignParent = $this->hatfieldSessionStore->createSession('Foreign parent for artifact retrieve');
        $artifactId = 'agent_foreign_artifact';
        $childRun = 'foreign-child-artifact';
        $this->registry->create($foreignParent, $artifactId, $childRun, 'scout');

        $service = $this->makeService();

        try {
            $service->retrieve('parent-current-artifact', ['artifact_id' => $artifactId]);
            self::fail('expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('different parent session', $e->getMessage());
        }
    }

    private function makeService(
        ?RunStoreInterface $runStore = null,
        ?EventStoreInterface $eventStore = null,
    ): AgentArtifactRetrievalService {
        return new AgentArtifactRetrievalService(
            artifactRegistry: $this->registry,
            childRunDirectory: $this->directory,
            hatfieldSessionStore: $this->hatfieldSessionStore,
            runStore: $runStore ?? $this->createStub(RunStoreInterface::class),
            eventStore: $eventStore ?? $this->createStub(EventStoreInterface::class),
            logger: self::getContainer()->get('logger'),
        );
    }
}
