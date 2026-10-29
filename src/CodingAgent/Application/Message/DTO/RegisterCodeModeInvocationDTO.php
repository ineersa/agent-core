<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRequesterRoleEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterCodeModeInvocationDTO
{
    public function __construct(
        public DeferredToolCompletionCorrelation $invocation,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $incarnation,
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $correlation,
        public BashRequesterRoleEnum $role,
    ) {
    }
}
