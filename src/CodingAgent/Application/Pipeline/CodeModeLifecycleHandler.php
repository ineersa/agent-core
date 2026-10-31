<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\CodingAgent\Application\Message\DTO\ReadyCodeModeInvocationDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** One-way readiness owner handler. Registration is prepared before dispatch. */
#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class CodeModeLifecycleHandler
{
    public function __construct(private CodeModeLifecycleService $lifecycle, private ValidatorInterface $validator)
    {
    }

    public function __invoke(ReadyCodeModeInvocationDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        $this->lifecycle->ready($command->runId, $command->deferredId);
    }
}
