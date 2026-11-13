<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TaskInputDTO
{
    public function __construct(
        #[Assert\NotBlank(groups: ['read', 'update', 'move'])]
        public string $task = '',
        #[Assert\NotBlank(groups: ['create'])]
        public string $title = '',
        #[Assert\NotBlank(groups: ['create'])]
        public ?string $body = null,
        #[Assert\Choice(choices: ['TODO', 'IN-PROGRESS', 'CODE-REVIEW', 'DONE', 'CANCELLED'], groups: ['move'])]
        #[Assert\NotBlank(groups: ['move'])]
        public string $to = '',
        public ?string $from = null,
        public bool $adopt = false,
        public ?string $pr = null,
        public ?string $branch = null,
        public ?string $acceptedHead = null,
        public ?string $prTitle = null,
        public ?string $prBody = null,
        public ?string $comment = null,
        public ?string $artifact = null,
        public ?string $status = null,
        #[Assert\Range(min: 1, max: 10000, groups: ['list'])]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100, groups: ['list'])]
        public int $perPage = 30,
    ) {
    }
}
