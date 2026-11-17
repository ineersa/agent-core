<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Session\Goal\DTO\GoalObjectiveDTO;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

final readonly class ClearGoalTool implements HatfieldToolProviderInterface
{
    public function __construct(private SessionGoalService $goals, private StackToolExecutionContextAccessor $context, private ToolRuntime $runtime)
    {
    }

    /** @return array{cleared: bool, goal: ?string} */
    public function __invoke(#[MapToolArguments] GoalObjectiveDTO $arguments): array
    {
        return $this->runtime->run(function () use ($arguments): array {
            $runId = $this->context->current()?->runId() ?? '';

            return [
                'cleared' => $this->goals->clear($runId, $arguments->objective),
                'goal' => $this->goals->get($runId),
            ];
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'clear_goal',
            description: 'Clear the main-session goal only if it still exactly matches objective, the goal you verified as achieved or explicitly cancelled. Read get_goal first. If cleared is false, re-evaluate the returned current goal; do not clear a replacement based on old work.',
            handler: $this,
            promptLine: 'clear_goal objective — compare-and-clear the exact verified goal after completion or explicit cancellation',
            mainOnly: true,
        );
    }
}
