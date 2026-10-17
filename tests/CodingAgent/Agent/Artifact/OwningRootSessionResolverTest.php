<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\OwningRootSessionResolver;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRetainedToolCompletionBodyStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class OwningRootSessionResolverTest extends IsolatedKernelTestCase
{
    public function testColdDepthTwoDeferredOwnershipSealsUnderRootWithoutPhantomUuidSessions(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        /** @var AgentArtifactRegistry $registry */
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        /** @var DeferredSubagentBatchRepository $batches */
        $batches = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        /** @var DeferredSubagentChildRepository $children */
        $children = self::getContainer()->get(DeferredSubagentChildRepository::class);
        /** @var SessionRetainedToolCompletionBodyStore $store */
        $store = self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class);
        /** @var RetainedToolCompletionEnvelopeFactory $factory */
        $factory = self::getContainer()->get(RetainedToolCompletionEnvelopeFactory::class);

        $root = $sessions->createSession('owning-root-depth2');
        $identity = new DeferredSubagentBatchIdentityFactory();

        $depth1Tool = 'tool-depth1-'.bin2hex(random_bytes(3));
        $depth1Lifecycle = $identity->batchLifecycleId($root, $depth1Tool);
        $depth1 = $identity->childIdentity($root, $depth1Tool, 1);
        $batches->reserveBatch(
            lifecycleId: $depth1Lifecycle,
            parentRunId: $root,
            parentTurnNo: 1,
            parentToolCallId: $depth1Tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $depth1['childRunId'],
                'artifactId' => $depth1['artifactId'],
                'agentName' => 'depth1',
                'task' => 'depth1 task',
                'launchModel' => 'deepseek/deepseek-v4-flash',
                'launchReasoning' => 'medium',
            ]],
        );
        $registry->create($root, $depth1['artifactId'], $depth1['childRunId'], 'depth1', AgentArtifactKindEnum::Subagent);

        $depth2Tool = 'tool-depth2-'.bin2hex(random_bytes(3));
        $depth2Lifecycle = $identity->batchLifecycleId($depth1['childRunId'], $depth2Tool);
        $depth2 = $identity->childIdentity($depth1['childRunId'], $depth2Tool, 1);
        $batches->reserveBatch(
            lifecycleId: $depth2Lifecycle,
            parentRunId: $depth1['childRunId'],
            parentTurnNo: 1,
            parentToolCallId: $depth2Tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $depth2['childRunId'],
                'artifactId' => $depth2['artifactId'],
                'agentName' => 'depth2',
                'task' => 'depth2 task',
                'launchModel' => 'deepseek/deepseek-v4-flash',
                'launchReasoning' => 'medium',
            ]],
        );
        // Nested child registry lives under the UUID depth-1 parent, not the root session.
        $registry->create($depth1['childRunId'], $depth2['artifactId'], $depth2['childRunId'], 'depth2', AgentArtifactKindEnum::Subagent);

        // Fresh objects: no process cache pre-registration. Cold workers only see DB/session state.
        $coldChildren = new AgentChildRunDirectory($sessions, $registry, new NullLogger());
        $coldResolver = new OwningRootSessionResolver($coldChildren, $sessions, $children, $batches);
        $this->assertSame($root, $coldResolver->resolveOwningRootSessionId($depth2['childRunId']));
        $this->assertSame($root, $coldResolver->resolveOwningRootSessionId($depth1['childRunId']));

        $generation = $registry->writeHandoff(
            $depth1['childRunId'],
            $depth2['artifactId'],
            str_repeat('depth2-handoff-', 80),
            summary: 'depth2',
        );
        $normalized = [
            'tool_name' => 'tasks',
            'content' => [['type' => 'text', 'text' => 'depth2 completed']],
            'is_error' => false,
            'details' => [
                'native_artifact_handoffs' => [[
                    'parent_run_id' => $generation->parentRunId,
                    'artifact_id' => $generation->artifactId,
                    'handoff_id' => $generation->handoffId,
                    'bytes' => $generation->bytes,
                    'sha256' => $generation->sha256,
                    'media_type' => $generation->mediaType,
                ]],
            ],
        ];
        $envelope = $factory->build(
            completionId: 'completion-depth2',
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: $depth2Lifecycle,
                runId: $depth2['childRunId'],
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-depth2',
                toolCallId: $depth2Tool,
                toolName: 'tasks',
                arguments: ['tasks' => [['description' => 'depth2']]],
                orderIndex: 0,
                invocationId: 'inv-depth2',
                executionOwnerKind: 'subagent',
            ),
            result: new ToolCallResult($depth2['childRunId'], 1, 'step-1', 1, 'key-depth2', $depth2Tool, 0, $normalized),
            normalizedToolResult: $normalized,
            executionOwnerKind: 'subagent',
        );

        $this->assertSame($root, $envelope->origin->controllerRunId);
        $this->assertSame($depth2['childRunId'], $envelope->origin->sourceRunId);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $envelope->body->kind);
        $this->assertTrue($store->isHeld($root, 'completion-depth2'));
        $this->assertTrue($store->exists($envelope->body, $root));

        $sessionsBase = $sessions->resolveSessionsBasePath();
        $this->assertDirectoryDoesNotExist($sessionsBase.'/'.$depth2['childRunId']);
        $this->assertDirectoryDoesNotExist($sessionsBase.'/'.$depth2['childRunId'].'/runtime/retained-tool-completions');
        $this->assertFileExists($sessionsBase.'/'.$root.'/runtime/retained-tool-completions/completion-depth2.json');
        $this->assertFileExists($sessionsBase.'/'.$depth1['childRunId'].'/artifacts/agents/'.$depth2['artifactId'].'/handoffs/'.$generation->handoffId.'.md');

        $inspected = $store->inspectJson($envelope->body, $root, maxDecodeBytes: 64);
        $this->assertSame($generation->sha256, $inspected['sha256']);
        $this->assertTrue($inspected['truncated']);

        $foreign = $sessions->createSession('owning-root-depth2-foreign');
        try {
            $store->exists($envelope->body, $foreign);
            $this->fail('Expected foreign-scope rejection for sealed body.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }
        $this->assertNotSame($foreign, $coldResolver->resolveOwningRootSessionId($depth2['childRunId']));

        $multi = [
            'tool_name' => 'tasks',
            'content' => [['type' => 'text', 'text' => 'batch completed']],
            'is_error' => false,
            'details' => [
                'native_artifact_handoffs' => [
                    [
                        'parent_run_id' => $generation->parentRunId,
                        'artifact_id' => $generation->artifactId,
                        'handoff_id' => $generation->handoffId,
                        'bytes' => $generation->bytes,
                        'sha256' => $generation->sha256,
                        'media_type' => $generation->mediaType,
                    ],
                    [
                        'parent_run_id' => $generation->parentRunId,
                        'artifact_id' => $generation->artifactId,
                        'handoff_id' => $generation->handoffId,
                        'bytes' => $generation->bytes,
                        'sha256' => $generation->sha256,
                        'media_type' => $generation->mediaType,
                    ],
                ],
            ],
        ];
        $manifestEnvelope = $factory->build(
            completionId: 'completion-depth2-manifest',
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: $depth2Lifecycle.'-manifest',
                runId: $depth2['childRunId'],
                turnNo: 1,
                stepId: 'step-2',
                attempt: 1,
                idempotencyKey: 'key-depth2-manifest',
                toolCallId: $depth2Tool.'-manifest',
                toolName: 'tasks',
                arguments: ['tasks' => [['description' => 'depth2-manifest']]],
                orderIndex: 1,
                invocationId: 'inv-depth2-manifest',
                executionOwnerKind: 'subagent',
            ),
            result: new ToolCallResult($depth2['childRunId'], 1, 'step-2', 1, 'key-depth2-manifest', $depth2Tool.'-manifest', 1, $multi),
            normalizedToolResult: $multi,
            executionOwnerKind: 'subagent',
        );
        $this->assertSame($root, $manifestEnvelope->origin->controllerRunId);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $manifestEnvelope->body->kind);
        $this->assertTrue($store->isHeld($root, 'completion-depth2-manifest'));

        $sessions->deleteSession($root);
        $this->assertFalse($store->isHeld($root, 'completion-depth2'));
        $this->assertFalse($store->isHeld($root, 'completion-depth2-manifest'));
        $this->assertDirectoryDoesNotExist($sessionsBase.'/'.$root);
        $this->assertDirectoryExists($sessionsBase.'/'.$foreign);
        // UUID parent artifact trees are outside the root session directory and are not
        // claimed as deleted by root session deletion. Promised C4 bodies were sealed under root.
        $this->assertFileExists($sessionsBase.'/'.$depth1['childRunId'].'/artifacts/agents/'.$depth2['artifactId'].'/handoffs/'.$generation->handoffId.'.md');
        $sessions->deleteSession($foreign);
    }
}
