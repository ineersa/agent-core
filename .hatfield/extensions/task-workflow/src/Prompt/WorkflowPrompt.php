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

Main alone manages tracked work using task tools and the consolidated `task-workflow` skill. Implementation forks implement their assigned specification and return results; they do not manage task records, phases, commits, reviews, or PRs.
';
    }
}
