<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Application\Message\DTO\MarkChildBackgroundedDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ChildBackgroundCoordinationHandler implements CoordinationActionValidatorInterface
{
    public function __construct(
        private DeferredSubagentBatchRepository $batches,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ApplicationDbTransactionInterface $transactions,
        private ValidatorInterface $validator,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(MarkChildBackgroundedDTO $action): void
    {
        $this->validate($action);
        // This synchronous action runs before the finalizer's metadata transaction.
        // Both local writes must commit together; replay remains idempotent.
        $this->transactions->transactional(function () use ($action): void {
            $this->batches->markBackgrounded($action->lifecycleId, $action->acceptedAt);
            // Only the caller wait is completed. Native supervision remains in the
            // existing deferred batch until its ordinary terminal delivery.
            $this->deferred->markCompleted($action->lifecycleId);
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
