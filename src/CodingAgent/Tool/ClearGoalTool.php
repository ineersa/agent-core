<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;

final readonly class ClearGoalTool implements HatfieldToolProviderInterface
{
    public function __construct(private SessionGoalService $goals, private StackToolExecutionContextAccessor $context, private ToolRuntime $runtime)
    {
    }

    /** @return array{goal: ?string} */
    public function __invoke(): array
    {
        return $this->runtime->run(fn (): array => ['goal' => $this->goals->set($this->context->current()?->runId() ?? '', null)]);
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'clear_goal',
            description: 'Clear the current main-session goal after verifying it is achieved, or on explicit user cancellation. Read get_goal first to check the current objective.',
            handler: $this,
            promptLine: 'clear_goal — clear the current goal after evidence-backed completion or explicit cancellation',
            mainOnly: true,
        );
    }
}
