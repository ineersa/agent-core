<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\ApprovalSessionTracker;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\SafeGuardApprovalCommitSubscriber;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Full pipeline end-to-end test: exercises REAL RunCommit::commit() →
 * HookDispatcher → SafeGuardApprovalCommitSubscriber path.
 *
 * This is the definitive test for issue #130: without commit-time routing,
 * RunCommit::commit() would never call onApprovalAnswered() because the old
 * ExtensionApprovalAnswerSubscriber only fired from RuntimeEventTranslator
 * (controller/polling process), not from RunCommit in the worker.
 */
final class SafeGuardApprovalEndToEndTest extends TestCase
{
    public function testRunCommitDispatchApprovalIsMarked(): void
    {
        // ── 1. Setup: SafeGuard + ExtensionHookRegistry ──
        $tracker = new ApprovalSessionTracker();
        $hookRegistry = new ExtensionHookRegistry();

        $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = new SafeGuardPolicy();
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: $tracker,
            policyWriter: null,
            cwd: '/tmp',
            autoDenyInNoninteractive: false,
        );
        $hookRegistry->addToolCallHook($hook);

        // ── 2. Trigger RequireApproval ──
        $toolCall = new ToolCallContextDTO(
            toolCallId: 'call_e2e_01',
            toolName: 'write',
            arguments: ['path' => '/outside-cwd/e2e-test.txt'],
            orderIndex: 0,
        );
        $decision = $hook->onToolCall($toolCall);

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $decision->kind);
        $questionId = (string) ($decision->details['question_id'] ?? '');
        $operationKey = (string) ($decision->details['operation_key'] ?? '');
        $this->assertNotEmpty($questionId);
        $this->assertNotEmpty($operationKey);

        $hookRegistry->registerPendingApproval($questionId, $hook, $decision->details);

        // ── 3. Build stores and real RunCommit with HookDispatcher ──
        $runStore = new InMemoryRunStore();
        $eventStore = new RunEventStore();
        $commandStore = new InMemoryCommandStore();

        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );
        $serializer = new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: $propertyTypeExtractor,
            ),
        ]);

        $hookDispatcher = new HookDispatcher(
            registry: new HookSubscriberRegistry([new SafeGuardApprovalCommitSubscriber($hookRegistry)]),
            eventDispatcher: new EventDispatcher(),
            normalizer: $serializer,
            denormalizer: $serializer,
        );

        $runCommit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            replayService: new ReplayService(new RunEventStore(), new HotPromptStateStore()),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: new NullLogger(),
            hookDispatcher: $hookDispatcher,
        );

        // ── 4. Seed initial running state via compareAndSwap, then commit human_response ──
        $runId = 'run-sg-e2e-01';

        // compareAndSwap with expectedVersion=0 seeds a fresh run
        $initialState = new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 5,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [],
            activeStepId: null,
            retryableFailure: false,
        );
        $this->assertTrue($runStore->compareAndSwap($initialState, 0), 'Seed initial state must succeed');

        $nextState = new RunState(
            runId: $initialState->runId,
            status: RunStatus::Running,
            version: $initialState->version + 1,
            turnNo: $initialState->turnNo,
            lastSeq: $initialState->lastSeq + 1,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $initialState->messages,
            activeStepId: null,
            retryableFailure: false,
        );

        $committed = $runCommit->commit(
            $initialState,
            $nextState,
            [
                new RunEvent(
                    runId: $runId,
                    seq: $initialState->lastSeq + 1,
                    turnNo: $initialState->turnNo,
                    type: 'agent_command_applied',
                    payload: [
                        'kind' => 'human_response',
                        'idempotency_key' => 'ik_answer_01',
                        'question_id' => $questionId,
                        'answer' => 'Allow once',
                        'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Allow once']]],
                        'options' => [],
                    ],
                ),
            ],
        );

        $this->assertTrue($committed, 'RunCommit::commit() must succeed');

        // ── 5. ASSERT: approval is marked in the tracker.
        //        This proves RunCommit::commit() → HookDispatcher →
        //        SafeGuardApprovalCommitSubscriber routed the answer.
        //        Without the fix, this assertion FAILS. ──
        $this->assertTrue(
            $tracker->isApproved($operationKey),
            'Approval must be marked AFTER RunCommit::commit() dispatches '
            .'to SafeGuardApprovalCommitSubscriber. This is issue #130 fix.',
        );

        // ── 6. Retry: same tool call → Allow (approval consumed) ──
        $retryDecision = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_e2e_02',
            toolName: 'write',
            arguments: ['path' => '/outside-cwd/e2e-test.txt'],
            orderIndex: 0,
        ));

        $this->assertSame(
            ToolCallDecisionKindEnum::Allow,
            $retryDecision->kind,
            'After commit-time approval routing, the retried tool call must be allowed.',
        );
    }
}
