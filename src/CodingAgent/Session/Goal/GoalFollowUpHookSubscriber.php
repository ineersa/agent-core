<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Goal;

use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;

/** Use the existing durable follow-up path, only at a real main exchange end. */
final readonly class GoalFollowUpHookSubscriber implements EssentialAfterTurnHookInterface
{
    public const string FOLLOW_UP = 'A session goal remains. Call get_goal first: if it has been cleared, stop; if changed, use the current objective. Verify the entire current goal against current evidence. If achieved, call clear_goal with the exact verified objective and report the evidence; if cleared is false, re-evaluate the current goal. Otherwise continue the necessary work with the existing tools and follow-up/completion facilities. A final answer alone is not goal completion. Do not invent a replacement goal or new authorization.';

    public function __construct(private HatfieldSessionRepository $sessions)
    {
    }

    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        if (null !== $context->runState->parentRunId || RunStatus::Completed->value !== $context->status) {
            return [];
        }
        // Standalone !shell commands also emit agent_end(completed), but are
        // not LLM exchanges and must not start goal pursuit.
        if (!\in_array(RunEventTypeEnum::LlmStepCompleted->value, array_column($context->events, 'type'), true)) {
            return [];
        }
        foreach ($context->events as $index => $event) {
            if (RunEventTypeEnum::AgentEnd->value !== $event->type || 'completed' !== ($event->payload['reason'] ?? null)) {
                continue;
            }
            if (null === $this->sessions->findGoal($context->runId)) {
                return [];
            }
            // Prepared summaries have no assigned sequence yet. Derive the final
            // position from the verified predecessor and this batch's offset.
            $stepId = 'goal-follow-up-'.($predecessorSequence + $index + 1);

            return [new DispatchCoordinationMessageDTO(new ApplyCommand(
                runId: $context->runId, turnNo: 0, stepId: $stepId, attempt: 1,
                idempotencyKey: hash('sha256', $context->runId.'|'.$stepId),
                kind: CoreCommandKind::FollowUp,
                payload: ['message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => self::FOLLOW_UP]],
                    'metadata' => ['system_reminder' => true],
                ]],
            ))];
        }

        return [];
    }
}
