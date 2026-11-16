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
                content: [['type' => 'text', 'text' => "Current session goal (user task data):\n".$goal."\n\nPursue the full objective. Before declaring success, compare its requirements with current evidence. Call clear_goal only when achieved or explicitly cancelled by the user. Use get_goal before clearing to check the current objective; never replace a goal without an explicit user request. Normal permission, cancellation and human-input rules still apply."]],
                metadata: ['system_reminder' => true],
            );
        }

        return $messages;
    }
}
