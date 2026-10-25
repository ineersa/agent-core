<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\MarkCodeModeBackgroundedDTO;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class CodeModeBackgroundCoordinationHandler implements CoordinationActionValidatorInterface, EssentialAfterTurnHookInterface
{
    public function __construct(
        private DeferredToolCompletionRepository $deferred,
        private ApplicationDbTransactionInterface $transactions,
        private ValidatorInterface $validator,
        #[Autowire(lazy: true)] private RunMessageProcessor $processor,
        private OwnedRunIdsProvider $owners,
        private RunLockManager $locks,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(MarkCodeModeBackgroundedDTO $action): void
    {
        $this->validate($action);
        $this->transactions->transactional(function () use ($action): void {
            if (null !== $action->continuationRequired) {
                $metadata = $this->deferred->findMetadataByDeferredId($action->deferredId);
                if ($action->continuationRequired && \in_array($metadata?->interruptionReason, ['stopped', 'cancelled', 'history_discarded'], true)) {
                    $this->deferred->markCompleted($action->deferredId);
                    $this->deferred->requireContinuation($action->deferredId, false);

                    return;
                }
                if (null !== $action->interruptionReason) {
                    $this->deferred->interrupt($action->deferredId, $action->interruptionReason);
                }
                $this->deferred->requireContinuation($action->deferredId, $action->continuationRequired);

                return;
            }
            if (null !== $action->interruptionReason) {
                $this->deferred->interrupt($action->deferredId, $action->interruptionReason);
                // A retained terminal needs no future worker delivery to retire
                // an explicitly abandoned invocation. A live host stays pending.
                if ('controller_lost' !== $action->interruptionReason && null !== $this->deferred->findMetadataByDeferredId($action->deferredId)?->acceptedResultReference) {
                    $this->deferred->markCompleted($action->deferredId);
                }

                return;
            }
            // Detach only the caller wait. The host remains pending until its
            // ordinary accepted terminal is settled through shared completion.
            $this->deferred->markBackgrounded($action->deferredId, $action->acceptedAt);
        });
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function detach(BackgroundCodeModeInvocationDTO $message): void
    {
        $violations = $this->validator->validate($message);
        if (0 !== \count($violations)) {
            throw new ValidationFailedException($message, $violations);
        }
        $this->processor->process('background_code_mode', $message);
    }

    public function supports(object $action): bool
    {
        return $action instanceof MarkCodeModeBackgroundedDTO;
    }

    #[AsEventListener]
    public function controllerStarting(ControllerSessionStartingEvent $event): void
    {
        $this->park($event->sessionId);
    }

    #[AsEventListener]
    public function controllerStopping(ControllerSessionShutdownEvent $event): void
    {
        if (!$event->permanentDeletion) {
            $this->park($event->sessionId);
        }
    }

    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        $reason = \in_array(RunEventTypeEnum::HistoryTailDiscarded->value, array_column($context->events, 'type'), true)
            ? 'history_discarded' : (\in_array($context->status, ['cancelling', 'cancelled'], true) ? 'cancelled' : null);
        if (null === $reason) {
            return [];
        }

        return array_map(static fn (string $id): object => new DispatchLocalMetadataMessageDTO(new MarkCodeModeBackgroundedDTO($id, new \DateTimeImmutable(), $reason)), $this->deferred->codeModeIdentities($context->runId));
    }

    public function validate(object $action): void
    {
        if (!$action instanceof MarkCodeModeBackgroundedDTO) {
            throw new \InvalidArgumentException('Unsupported code-mode background coordination action.');
        }
        $violations = $this->validator->validate($action);
        if (0 !== \count($violations)) {
            throw new ValidationFailedException($action, $violations);
        }
    }

    private function park(string $root): void
    {
        foreach ($this->owners->forOwner($root) as $run) {
            $this->locks->synchronized($run, function () use ($run): void {
                foreach ($this->deferred->controllerLossIdentities($run) as $id) {
                    $this(new MarkCodeModeBackgroundedDTO($id, new \DateTimeImmutable(), 'controller_lost', true));
                }
            });
        }
    }
}
