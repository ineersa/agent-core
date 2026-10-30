<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchSuccessDTO;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** One-way child launch-success owner handler. Failures retire through sealed ToolCallResult. */
#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class ChildInvocationLifecycleHandler
{
    public function __construct(
        private ChildInvocationLifecycleService $lifecycle,
        private DeferredToolCompletionRepository $deferred,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(RecordChildLaunchSuccessDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }

        $this->lifecycle->applyLaunchSuccessState(
            $command->parentRunId,
            $command->parentToolCallId,
            $command->lifecycleId,
            $command->startedAt,
        );
        $metadata = $this->deferred->findMetadataByRunAndToolCall($command->parentRunId, $command->parentToolCallId);
        if (null !== $metadata && $metadata->deferredId === $command->lifecycleId) {
            $this->deferred->markReady($metadata->deferredId);
        }
    }
}
