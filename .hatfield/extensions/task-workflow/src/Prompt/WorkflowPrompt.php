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

Use task tools for board transitions and metadata; never commit board metadata to the code repository or copy these project paths into installed skills.
Before phase work or a status transition, read the task-workflow router AND the exact phase procedure it links. The router alone is not the procedure. Follow the procedure’s ownership rules.
';
    }
}
