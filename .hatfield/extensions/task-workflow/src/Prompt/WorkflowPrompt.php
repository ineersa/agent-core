<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Prompt;

use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;

final readonly class WorkflowPrompt implements PromptContributorInterface
{
    public function __construct(private string $primaryRoot, private string $repository)
    {
    }

    public function contribute(): string
    {
        return "\n\n## Project task workflow\n\nPrimary project checkout: `".$this->primaryRoot."`.\nGitHub task repository: `".$this->repository."`.\n\nRead the selected issue directly; list only to choose work or resolve identity.\nThe issue body is the current specification, implementation plan, decisions,\ntest plan and actual QA results. Keep agent metadata and execution logs out.\nWorkflow is authoritative, not GitHub open/closed state. Closed active issues\nand unfinished local cleanup still require explicit finalization.\n\nMain alone calls task tools and follows the `task-workflow` skill. Implement\ndirectly or use optional bounded implementation forks, one writer per checkout.\nUse move_task for transitions; it owns project hooks, PR operations and cleanup.\nHonor the authorized endpoint and project review requirements. Never infer merge\nauthorization or claim completion before confirmed cleanup and workspace removal.\n";
    }
}
