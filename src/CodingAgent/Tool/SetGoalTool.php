<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Session\Goal\DTO\GoalObjectiveDTO;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

final readonly class SetGoalTool implements HatfieldToolProviderInterface
{
    public function __construct(private SessionGoalService $goals, private StackToolExecutionContextAccessor $context, private ToolRuntime $runtime)
    {
    }

    /** @return array{goal: ?string} */
    public function __invoke(#[MapToolArguments] GoalObjectiveDTO $arguments): array
    {
        return $this->runtime->run(fn (): array => ['goal' => $this->goals->set($this->context->current()?->runId() ?? '', $arguments->objective)]);
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'set_goal',
            description: 'Set or replace the persistent main-session goal only on an explicit user request. Do not invent goals from ordinary tasks.',
            handler: $this,
            promptLine: 'set_goal objective — set the main-session goal only when explicitly requested by the user',
            mainOnly: true,
        );
    }
}
