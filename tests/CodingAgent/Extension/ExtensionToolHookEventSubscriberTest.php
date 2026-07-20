<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

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
                    schema: ['type' => 'string', 'enum' => ['✅ Allow once', '❌ Block']],
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
                    schema: ['type' => 'string', 'enum' => ['✅ Allow once']],
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
            answer: '✅ Allow once',
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
