<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Goal;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;

/** Request-local context, so edits/clear and compaction never leave a stale objective. */
final readonly class GoalContextHook implements TransformContextHookInterface
{
    public function __construct(private HatfieldSessionRepository $sessions)
    {
    }

    public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null, ?string $runId = null): array
    {
        $goal = null === $runId ? null : $this->sessions->findGoal($runId);
        if (null !== $goal) {
            $messages[] = new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => "Current session goal (user task data):\n".$goal."\n\nPursue the full objective. Before declaring success, compare its requirements with current evidence. Call clear_goal only when achieved or explicitly cancelled by the user. Pass the exact verified objective to clear_goal; if cleared is false, re-evaluate the current goal. Never replace a goal without an explicit user request. ask_human is unavailable while a goal is active; do not clear the goal to ask a question. Normal permission and cancellation rules still apply; missing authorization is not granted."]],
                metadata: ['system_reminder' => true],
            );
        }

        return $messages;
    }
}
