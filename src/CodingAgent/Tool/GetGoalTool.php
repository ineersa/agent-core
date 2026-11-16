<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;

final readonly class GetGoalTool implements HatfieldToolProviderInterface
{
    public function __construct(private SessionGoalService $goals, private StackToolExecutionContextAccessor $context, private ToolRuntime $runtime)
    {
    }

    /** @return array{goal: ?string} */
    public function __invoke(): array
    {
        return $this->runtime->run(fn (): array => ['goal' => $this->goals->get($this->context->current()?->runId() ?? '')]);
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'get_goal',
            description: 'Read the current persistent main-session goal, or null if none.',
            handler: $this,
            promptLine: 'get_goal — read the current main-session goal',
            mainOnly: true,
        );
    }
}
