<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Application\Message\DTO\MarkChildBackgroundedDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ChildBackgroundCoordinationHandler implements CoordinationActionValidatorInterface
{
    public function __construct(
        private DeferredSubagentBatchRepository $batches,
        private RunLockManager $locks,
        private MessageBusInterface $commandBus,
        private ValidatorInterface $validator,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(MarkChildBackgroundedDTO $action): void
    {
        $this->validate($action);
        $batch = $this->batches->findByLifecycleId($action->lifecycleId);
        // Activity projection only. Shared deferred acknowledgement stays pending
        // until true terminal settlement and is recorded earlier in this transaction.
        $this->batches->markBackgrounded($action->lifecycleId, $action->acceptedAt);
        if (null === $batch) {
            return;
        }
        // Lifecycle delivery must observe committed acknowledgement and activity
        // projection, so wait until the owner lock releases.
        $this->locks->afterRelease($batch->parentRunId, function () use ($action): void {
            $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($action->lifecycleId));
        });
    }

    public function supports(object $action): bool
    {
        return $action instanceof MarkChildBackgroundedDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof MarkChildBackgroundedDTO) {
            throw new \InvalidArgumentException('Unsupported child background coordination action.');
        }
        $violations = $this->validator->validate($action);
        if (0 !== \count($violations)) {
            throw new ValidationFailedException($action, $violations);
        }
    }
}
