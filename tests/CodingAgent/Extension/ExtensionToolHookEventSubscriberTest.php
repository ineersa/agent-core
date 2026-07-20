<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionToolHookEventSubscriber;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/** Slice C: RequireApproval becomes typed suspension; resume applies exact-hook answer. */
final class ExtensionToolHookEventSubscriberTest extends TestCase
{
    public function testRequireApprovalReturnsTypedSuspensionWithoutBlocking(): void
    {
        $hook = new class implements ToolCallHookInterface, ApprovalAnswerHookInterface {
            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::requireApproval(
                    prompt: 'Allow rm?',
                    questionId: 'q-1',
                    schema: ['type' => 'string', 'enum' => ['✅ Allow', '❌ Deny']],
                    details: ['operation_key' => 'bash:rm'],
                );
            }

            public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void
            {
            }

            public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::allow();
            }
        };

        $registry = new ExtensionHookRegistry();
        $registry->addToolCallHook($hook);
        $accessor = new StackToolExecutionContextAccessor();
        $subscriber = new ExtensionToolHookEventSubscriber($registry, '/tmp', $accessor);

        $toolCall = new ToolCall('call-1', 'bash', ['command' => 'rm -rf /tmp/x']);
        $event = $this->requested($toolCall);

        $accessor->with(new ToolContext(
            runId: 'run-1',
            turnNo: 2,
            toolCallId: 'call-1',
            toolName: 'bash',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 30,
            stepId: 'step-1',
        ), static function () use ($subscriber, $event): void {
            $subscriber->onToolCallRequested($event);
        });

        $result = $event->getResult();
        $this->assertNotNull($result);
        $raw = $result->getResult();
        $this->assertInstanceOf(ToolExecutionHumanInputSuspension::class, $raw);
        $this->assertSame('q-1', $raw->request->questionId);
        $this->assertSame('call-1', $raw->request->continuationRef['tool_call_id'] ?? null);
        $this->assertSame('step-1', $raw->request->continuationRef['step_id'] ?? null);
    }

    public function testResumedAllowInvokesOriginatingHookAndFallsThrough(): void
    {
        $answered = false;
        $hook = new class($answered) implements ToolCallHookInterface, ApprovalAnswerHookInterface {
            public function __construct(private bool &$answered)
            {
            }

            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                // Should not re-prompt when answer is present for this hook.
                return ToolCallDecisionDTO::requireApproval(
                    prompt: 'Allow?',
                    questionId: 'q-allow',
                    schema: ['type' => 'string', 'enum' => ['✅ Allow']],
                    details: [],
                );
            }

            public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void
            {
                $this->answered = true;
            }

            public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::allow();
            }
        };

        $registry = new ExtensionHookRegistry();
        $registry->addToolCallHook($hook);
        $accessor = new StackToolExecutionContextAccessor();
        $subscriber = new ExtensionToolHookEventSubscriber($registry, '/tmp', $accessor);
        $hookId = hash('crc32b', $hook::class);

        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: 'q-allow',
            answer: '✅ Allow',
            continuationRef: [
                'run_id' => 'run-1',
                'turn_no' => 1,
                'step_id' => 'step-1',
                'tool_call_id' => 'call-1',
            ],
            requestPayload: [
                'question_id' => 'q-allow',
                'hook_id' => $hookId,
                'hook_class' => $hook::class,
                'approval_context' => ['operation_key' => 'x'],
            ],
        );

        $toolCall = new ToolCall('call-1', 'bash', ['command' => 'echo hi']);
        $event = $this->requested($toolCall);

        $accessor->with(new ToolContext(
            runId: 'run-1',
            turnNo: 1,
            toolCallId: 'call-1',
            toolName: 'bash',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 30,
            humanInputAnswer: $answer,
            stepId: 'step-1',
        ), static function () use ($subscriber, $event): void {
            $subscriber->onToolCallRequested($event);
        });

        $this->assertTrue($answered);
        $this->assertNull($event->getResult()); // Allow → real handler may run
    }

    public function testResumedBlockEncodesDeniedPayloadAsToon(): void
    {
        $hook = new class implements ToolCallHookInterface, ApprovalAnswerHookInterface {
            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::requireApproval(
                    prompt: 'Allow write?',
                    questionId: 'q-block',
                    schema: ['type' => 'string', 'enum' => ['✅ Allow', '❌ Deny']],
                    details: [],
                );
            }

            public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void
            {
            }

            public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::block(
                    reason: 'safeguard_cancelled',
                    details: [
                        'message' => 'Tool "write" was cancelled by the user.',
                    ],
                );
            }
        };

        $registry = new ExtensionHookRegistry();
        $registry->addToolCallHook($hook);
        $accessor = new StackToolExecutionContextAccessor();
        $subscriber = new ExtensionToolHookEventSubscriber($registry, '/tmp', $accessor);
        $hookId = hash('crc32b', $hook::class);

        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: 'q-block',
            answer: 'Cancelled by user',
            continuationRef: [
                'run_id' => 'run-1',
                'turn_no' => 1,
                'step_id' => 'step-1',
                'tool_call_id' => 'call-1',
            ],
            requestPayload: [
                'question_id' => 'q-block',
                'hook_id' => $hookId,
                'hook_class' => $hook::class,
                'approval_context' => ['category' => 'write_outside_cwd'],
            ],
        );

        $toolCall = new ToolCall('call-1', 'write', ['path' => '/tmp/x']);
        $event = $this->requested($toolCall);

        $accessor->with(new ToolContext(
            runId: 'run-1',
            turnNo: 1,
            toolCallId: 'call-1',
            toolName: 'write',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 30,
            humanInputAnswer: $answer,
            stepId: 'step-1',
        ), static function () use ($subscriber, $event): void {
            $subscriber->onToolCallRequested($event);
        });

        $result = $event->getResult();
        $this->assertNotNull($result);
        $raw = $result->getResult();
        $this->assertIsString($raw);
        $decoded = Toon::decode($raw);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['denied'] ?? null);
        $this->assertSame('safeguard_cancelled', $decoded['reason'] ?? null);
        $this->assertSame('Tool "write" was cancelled by the user.', $decoded['message'] ?? null);
    }

    public function testUnconsumedResumedAnswerFailsClosedWhenOriginHookUnavailable(): void
    {
        $realHandlerWouldRun = false;
        $allowOnlyHook = new class($realHandlerWouldRun) implements ToolCallHookInterface {
            public function __construct(private bool &$realHandlerWouldRun)
            {
            }

            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                // Unrelated allow hook — not the origin of the resumed answer.
                $this->realHandlerWouldRun = true;

                return ToolCallDecisionDTO::allow();
            }
        };

        $registry = new ExtensionHookRegistry();
        $registry->addToolCallHook($allowOnlyHook);
        $accessor = new StackToolExecutionContextAccessor();
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $subscriber = new ExtensionToolHookEventSubscriber($registry, '/tmp', $accessor, logger: $logger);

        $missingHookClass = 'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardToolCallHook';
        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: 'q-missing',
            answer: '✅ Allow',
            continuationRef: [
                'run_id' => 'run-1',
                'turn_no' => 1,
                'step_id' => 'step-1',
                'tool_call_id' => 'call-1',
            ],
            requestPayload: [
                'question_id' => 'q-missing',
                'hook_id' => hash('crc32b', $missingHookClass),
                'hook_class' => $missingHookClass,
                'approval_context' => ['category' => 'write_outside_cwd'],
            ],
        );

        $toolCall = new ToolCall('call-1', 'write', ['path' => '/tmp/x']);
        $event = $this->requested($toolCall);

        $accessor->with(new ToolContext(
            runId: 'run-1',
            turnNo: 1,
            toolCallId: 'call-1',
            toolName: 'write',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 30,
            humanInputAnswer: $answer,
            stepId: 'step-1',
        ), static function () use ($subscriber, $event): void {
            $subscriber->onToolCallRequested($event);
        });

        $result = $event->getResult();
        $this->assertNotNull($result, 'must deny when origin hook is unloaded');
        $raw = $result->getResult();
        $this->assertIsString($raw);
        $decoded = Toon::decode($raw);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['denied'] ?? null);
        $this->assertSame('approval_origin_hook_unavailable', $decoded['reason'] ?? null);
        $this->assertStringContainsString('originating approval hook is unavailable', (string) ($decoded['message'] ?? ''));
        $this->assertStringNotContainsString('✅ Allow', (string) ($decoded['message'] ?? ''));

        // Real handler must not fall through: result is set, so toolbox stops.
        // The allow-only hook still ran (unrelated), but unconsumed answer forces denial after the loop.
        $this->assertTrue($realHandlerWouldRun);

        $errorLogs = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => 'error' === $r['level'] && str_contains((string) $r['message'], 'approval_origin_hook_unavailable'),
        ));
        $this->assertNotEmpty($errorLogs);
        $context = $errorLogs[0]['context'] ?? [];
        $this->assertSame('run-1', $context['run_id'] ?? null);
        $this->assertSame('call-1', $context['tool_call_id'] ?? null);
        $this->assertSame('q-missing', $context['question_id'] ?? null);
        $this->assertSame($missingHookClass, $context['hook_class'] ?? null);
        $this->assertArrayNotHasKey('answer', $context);
    }

    private function requested(ToolCall $toolCall): ToolCallRequested
    {
        return new ToolCallRequested(
            $toolCall,
            new Tool(
                reference: new ExecutionReference(self::class),
                name: $toolCall->getName(),
                description: 'test',
                parameters: null,
            ),
        );
    }
}
