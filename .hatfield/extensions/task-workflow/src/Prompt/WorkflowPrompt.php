<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Prompt;

use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;

final readonly class WorkflowPrompt implements PromptContributorInterface
{
    public function __construct(
        private string $codeRoot,
        private string $taskRoot,
    ) {
    }

    public function contribute(): string
    {
        $context = 'Code checkout (current working directory): `'.$this->codeRoot.'`.'
            ."\n".'Resolved external task board: `'.$this->taskRoot.'`.';

        return '

## Project task workflow

'.$context.'

Tasks use the external board under TODO, IN-PROGRESS, CODE-REVIEW, DONE, ARCHIVE, and CANCELLED. These paths come from the active project and the same board resolver used by the task tools; do not copy them into installed skill files.

Use task tools for status transitions; task-board metadata never commits to the code repository. Before phase work or a status transition, read the Hatfield task-workflow router and the exact phase procedure it links. The router alone is not the procedure.
';
    }
}
