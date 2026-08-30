<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Prompt;

use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;

final readonly class WorkflowPrompt implements PromptContributorInterface
{
    public function __construct(
        private string $taskRoot,
    ) {
    }

    public function contribute(): string
    {
        $boardDesc = 'Task board at `'.$this->taskRoot.'` (external to code repo).';

        return '

## Project task workflow

Tasks use the external board under TODO, IN-PROGRESS, CODE-REVIEW, DONE, ARCHIVE, and CANCELLED. '.$boardDesc.'

Use task tools for status transitions; task-board metadata never commits to the code repository. Before phase work or a status transition, read the Hatfield task-workflow router and the exact phase procedure it links. The router alone is not the procedure.
';
    }
}
