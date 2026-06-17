<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Domain\Event\BoundaryHookEvent;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
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
 * Tests the commit-time SafeGuardApprovalCommitSubscriber integration with
 * the real HookDispatcher, ExtensionHookRegistry, and SafeGuardToolCallHook.
 *
 * Exercises the full path:
 *   SafeGuard requires approval → answer_human committed → commit subscriber
 *   routes answer → approval marked → retry tool call is allowed
 *
 * This replaces the broken polling-based ExtensionApprovalAnswerSubscriber
 * that ran in a different (controller) process where pending approvals
 * were not registered (issue #130).
 */
final class SafeGuardApprovalCommitSubscriberTest extends TestCase
{
    private string $cwd;
    private SafeGuardToolCallHook $hook;
    private ApprovalSessionTracker $tracker;
    private ExtensionHookRegistry $hookRegistry;
    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        $this->tracker = new ApprovalSessionTracker();
        $this->hookRegistry = new ExtensionHookRegistry();

        $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = new SafeGuardPolicy();

        $this->hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: $this->tracker,
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );

        $this->hookRegistry->addToolCallHook($this->hook);

        // Serializer stack matching the production config
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );
        $this->serializer = new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: $propertyTypeExtractor,
            ),
        ]);
    }

    /**
     * REPRODUCTION TEST — BEFORE THE FIX THIS FAILS:
     *
     * The old ExtensionApprovalAnswerSubscriber ran in RuntimeEventTranslator
     * (controller/polling process), never at commit time. The approval was
     * NEVER marked in the worker process. This test proves that the
     * commit-time subscriber SAFEGUARD-05 correctly routes the answer.
     *
     * Flow:
     *   1. SafeGuard returns RequireApproval for write outside CWD
     *   2. Pending approval is registered in ExtensionHookRegistry
     *   3. answer_human is committed → AfterTurnCommitHookContext created
     *   4. HookDispatcher dispatches to SafeGuardApprovalCommitSubscriber
     *   5. Subscriber routes answer via resolveApproval → onApprovalAnswered
     *   6. ApprovalSessionTracker marks approved → retry is ALLOWED
     */
    public function testCommitTimeSubscriberRoutesHumanResponseApproval(): void
    {
        // ── 1. Tool call that triggers SafeGuard RequireApproval ──

        $toolCall = new ToolCallContextDTO(
            toolCallId: 'call_sg_test_01',
            toolName: 'write',
            arguments: ['path' => '/outside-cwd/file.txt'],
            orderIndex: 0,
        );

        $decision = $this->hook->onToolCall($toolCall);

        $this->assertSame(
            ToolCallDecisionKindEnum::RequireApproval,
            $decision->kind,
            'Write outside CWD should require approval',
        );

        $questionId = (string) ($decision->details['question_id'] ?? '');
        $operationKey = (string) ($decision->details['operation_key'] ?? '');
        $this->assertNotEmpty($questionId);
        $this->assertNotEmpty($operationKey);

        // ── 2. Simulate what ExtensionToolHookEventSubscriber does
        //       when it receives RequireApproval ──

        $this->hookRegistry->registerPendingApproval(
            questionId: $questionId,
            hook: $this->hook,
            details: $decision->details,
        );

        // Verify pending before dispatch
        $this->assertFalse(
            $this->tracker->isApproved($operationKey),
            'Approval should NOT be marked before answer is routed',
        );

        // ── 3. Create the context that RunCommit::commit() would
        //       produce after applying answer_human ──

        $runState = new RunState(
            runId: 'test-commit-flow-01',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 10,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [],
            activeStepId: null,
            retryableFailure: false,
        );

        $committedEvents = [
            new RunEvent(
                runId: 'test-commit-flow-01',
                seq: 11,
                turnNo: 1,
                type: 'agent_command_applied',
                payload: [
                    'kind' => 'human_response',
                    'idempotency_key' => 'ik_test_01',
                    'question_id' => $questionId,
                    'answer' => 'Allow once',
                    'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Allow once']]],
                    'options' => [],
                ],
            ),
        ];

        $context = AfterTurnCommitHookContext::fromRunState($runState, $committedEvents, 0);

        // ── 4. Build the real HookDispatcher with our subscriber ──

        $subscriber = new SafeGuardApprovalCommitSubscriber($this->hookRegistry);
        $eventDispatcher = new EventDispatcher();

        $dispatcher = new HookDispatcher(
            registry: new HookSubscriberRegistry([$subscriber]),
            eventDispatcher: $eventDispatcher,
            normalizer: $this->serializer,
            denormalizer: $this->serializer,
        );

        // ── 5. Dispatch — this is the EXACT code path that
        //       RunCommit::commit() uses after the fix ──

        $result = $dispatcher->dispatchAfterTurnCommit($context);

        // ── 6. Assert: approval is now marked (retry should succeed) ──

        $this->assertTrue(
            $this->tracker->isApproved($operationKey),
            'Approval must be marked AFTER commit-time subscriber runs. '
            .'This is the core bug fix: before SafeGuardApprovalCommitSubscriber, '
            .'the answer was only routed in the controller/polling process, '
            .'so approvals were NEVER marked in the worker.',
        );

        // ── 7. Verify retry: tool call with same operation key is allowed ──

        $retryCall = new ToolCallContextDTO(
            toolCallId: 'call_sg_test_02',
            toolName: 'write',
            arguments: ['path' => '/outside-cwd/file.txt'],
            orderIndex: 0,
        );

        $retryDecision = $this->hook->onToolCall($retryCall);

        $this->assertSame(
            ToolCallDecisionKindEnum::Allow,
            $retryDecision->kind,
            'After approval is marked, the retried tool call must be allowed '
            .'(not re-blocked). Before the fix, this assertion FAILS because '
            .'approval was never marked in the worker process.',
        );
    }

    /**
     * Prove that WITHOUT the commit subscriber (the old/broken behavior),
     * the approval is NOT marked and the retry tool call is still blocked.
     *
     * This demonstrates the bug that SafeGuardApprovalCommitSubscriber fixes.
     */
    public function testWithoutCommitSubscriberApprovalIsNotMarked(): void
    {
        // ── 1. Same initial setup: tool call → RequireApproval ──

        $toolCall = new ToolCallContextDTO(
            toolCallId: 'call_sg_no_sub_01',
            toolName: 'write',
            arguments: ['path' => '/outside-cwd/other.txt'],
            orderIndex: 0,
        );

        $decision = $this->hook->onToolCall($toolCall);

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $decision->kind);

        $questionId = (string) ($decision->details['question_id'] ?? '');
        $operationKey = (string) ($decision->details['operation_key'] ?? '');
        $this->assertNotEmpty($questionId);

        $this->hookRegistry->registerPendingApproval(
            questionId: $questionId,
            hook: $this->hook,
            details: $decision->details,
        );

        // ── 2. Dispatch through HookDispatcher WITHOUT any subscriber ──

        $runState = new RunState(
            runId: 'test-no-sub-01',
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

        $committedEvents = [
            new RunEvent(
                runId: 'test-no-sub-01',
                seq: 6,
                turnNo: 1,
                type: 'agent_command_applied',
                payload: [
                    'kind' => 'human_response',
                    'question_id' => $questionId,
                    'answer' => 'Allow once',
                ],
            ),
        ];

        $context = AfterTurnCommitHookContext::fromRunState($runState, $committedEvents, 0);

        // HookDispatcher with NO subscriber (simulates broken old behavior)
        $dispatcher = new HookDispatcher(
            registry: new HookSubscriberRegistry([]),
            eventDispatcher: new EventDispatcher(),
            normalizer: $this->serializer,
            denormalizer: $this->serializer,
        );

        $dispatcher->dispatchAfterTurnCommit($context);

        // ── 3. Assert: approval is NOT marked ──

        $this->assertFalse(
            $this->tracker->isApproved($operationKey),
            'Without SafeGuardApprovalCommitSubscriber, the human answer is '
            .'NEVER routed to onApprovalAnswered(). The approval is NOT marked, '
            .'so the retry tool call is re-blocked. This reproduces issue #130.',
        );

        // ── 4. Retry tool call is still blocked ──

        $retryCall = new ToolCallContextDTO(
            toolCallId: 'call_sg_no_sub_02',
            toolName: 'write',
            arguments: ['path' => '/outside-cwd/other.txt'],
            orderIndex: 0,
        );

        $retryDecision = $this->hook->onToolCall($retryCall);

        $this->assertNotSame(
            ToolCallDecisionKindEnum::Allow,
            $retryDecision->kind,
            'Without the commit subscriber, the retry tool call is re-blocked '
            .'(Allow once was ignored). This is the bug that issue #130 reports.',
        );
    }

    /**
     * Prove that the subscriber correctly routes "Always allow" to
     * onApprovalAnswered, and that onApprovalAnswered persists the
     * pattern via SafeGuardPolicyWriter.
     */
    public function testAlwaysAllowPersistsAndAutoApproves(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sg_commit_test_' . uniqid();
        mkdir($tmpDir, 0o755, true);
        $settingsPath = $tmpDir . '/settings.yaml';

        try {
            // Set up with a real policy writer
            $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
            $classifier = SafeGuardClassifier::fromConfig($config);
            $policy = SafeGuardPolicy::fromConfig($config);
            $tracker = new ApprovalSessionTracker();
            $policyWriter = new \Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardPolicyWriter($settingsPath);
            $hook = new SafeGuardToolCallHook(
                classifier: $classifier,
                policy: $policy,
                approvalTracker: $tracker,
                policyWriter: $policyWriter,
                cwd: $this->cwd,
                autoDenyInNoninteractive: false,
            );
            $hookRegistry = new ExtensionHookRegistry();
            $hookRegistry->addToolCallHook($hook);

            // Tool call → RequireApproval
            $toolCall = new ToolCallContextDTO(
                toolCallId: 'call_always_01',
                toolName: 'write',
                arguments: ['path' => '/outside-cwd/always-allow.txt'],
                orderIndex: 0,
            );

            $decision = $hook->onToolCall($toolCall);
            $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $decision->kind);

            $questionId = (string) ($decision->details['question_id'] ?? '');
            $operationKey = (string) ($decision->details['operation_key'] ?? '');
            $this->assertNotEmpty($questionId);
            $this->assertNotEmpty($operationKey);

            $hookRegistry->registerPendingApproval($questionId, $hook, $decision->details);

            // Commit subscriber processes the answer
            $runState = new RunState(
                runId: 'test-always-01',
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

            $committedEvents = [
                new RunEvent(
                    runId: 'test-always-01',
                    seq: 6,
                    turnNo: 1,
                    type: 'agent_command_applied',
                    payload: [
                        'kind' => 'human_response',
                        'question_id' => $questionId,
                        'answer' => 'Always allow',
                    ],
                ),
            ];

            $context = AfterTurnCommitHookContext::fromRunState($runState, $committedEvents, 0);

            $subscriber = new SafeGuardApprovalCommitSubscriber($hookRegistry);
            $dispatcher = new HookDispatcher(
                registry: new HookSubscriberRegistry([$subscriber]),
                eventDispatcher: new EventDispatcher(),
                normalizer: $this->serializer,
                denormalizer: $this->serializer,
            );

            $dispatcher->dispatchAfterTurnCommit($context);

            // Assert: approval is marked
            $this->assertTrue($tracker->isApproved($operationKey));

            // Assert: first retry is allowed (consumed)
            $retryCall = new ToolCallContextDTO(
                toolCallId: 'call_always_02',
                toolName: 'write',
                arguments: ['path' => '/outside-cwd/always-allow.txt'],
                orderIndex: 0,
            );
            $this->assertSame(ToolCallDecisionKindEnum::Allow, $hook->onToolCall($retryCall)->kind);

            // Assert: policy file was written
            $this->assertFileExists($settingsPath, 'Always allow must persist to settings.yaml');
            $content = file_get_contents($settingsPath);
            $this->assertStringContainsString('allow_write_outside_cwd', $content ?? '');
            $this->assertStringContainsString('/outside-cwd/always-allow.txt', $content ?? '');

            // Assert: subsequent write to same path is auto-approved (by policy allowlist, not tracker)
            $subsequentCall = new ToolCallContextDTO(
                toolCallId: 'call_always_03',
                toolName: 'write',
                arguments: ['path' => '/outside-cwd/always-allow.txt'],
                orderIndex: 0,
            );

            // Re-create the hook with a fresh tracker (no pre-existing approvals)
            // but with the persisted policy, to prove policy-based approval works
            $freshTracker = new ApprovalSessionTracker();
            $freshHook = new SafeGuardToolCallHook(
                classifier: $classifier,
                policy: SafeGuardPolicy::fromConfig(new SafeGuardConfig(
                    allowWriteOutsideCwd: ['/outside-cwd/always-allow.txt'],
                    autoDenyInNoninteractive: false,
                )),
                approvalTracker: $freshTracker,
                policyWriter: null,
                cwd: $this->cwd,
                autoDenyInNoninteractive: false,
            );

            $freshDecision = $freshHook->onToolCall($subsequentCall);
            $this->assertSame(
                ToolCallDecisionKindEnum::Allow,
                $freshDecision->kind,
                'Subsequent write to an allowlisted path must be auto-approved '
                .'via the classifier allowlist path, with no human prompt.',
            );
        } finally {
            if (file_exists($settingsPath)) {
                unlink($settingsPath);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Verify that non-human_response events do NOT trigger approval routing.
     */
    public function testSubscriberIgnoresNonHumanResponseEvents(): void
    {
        // Register a pending approval
        $toolCall = new ToolCallContextDTO(
            toolCallId: 'call_ignore_01',
            toolName: 'write',
            arguments: ['path' => '/outside-cwd/ignore.txt'],
            orderIndex: 0,
        );

        $decision = $this->hook->onToolCall($toolCall);
        $questionId = (string) ($decision->details['question_id'] ?? '');
        $this->assertNotEmpty($questionId);

        $this->hookRegistry->registerPendingApproval($questionId, $this->hook, $decision->details);

        // Create context with a steer event (not human_response)
        $runState = new RunState(
            runId: 'test-ignore-01',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 3,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [],
            activeStepId: null,
            retryableFailure: false,
        );

        $committedEvents = [
            new RunEvent(
                runId: 'test-ignore-01',
                seq: 4,
                turnNo: 1,
                type: 'agent_command_applied',
                payload: [
                    'kind' => 'steer',
                    'idempotency_key' => 'ik_steer',
                ],
            ),
        ];

        $context = AfterTurnCommitHookContext::fromRunState($runState, $committedEvents, 0);

        $subscriber = new SafeGuardApprovalCommitSubscriber($this->hookRegistry);
        $dispatcher = new HookDispatcher(
            registry: new HookSubscriberRegistry([$subscriber]),
            eventDispatcher: new EventDispatcher(),
            normalizer: $this->serializer,
            denormalizer: $this->serializer,
        );

        $dispatcher->dispatchAfterTurnCommit($context);

        // Approval should still be pending (not consumed)
        $entry = $this->hookRegistry->resolveApproval($questionId);
        $this->assertNotNull(
            $entry,
            'Non-human_response events must not trigger approval routing — '
            .'the pending approval should still be resolvable.',
        );
    }
}
