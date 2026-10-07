<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\CodingAgent\Application\Message\RedriveRepairEffectsDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;

final readonly class RedriveRepairEffectsHandler implements CoordinationActionValidatorInterface
{
    public function __construct(private StepDispatcher $dispatcher, private \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface $store)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(RedriveRepairEffectsDTO $action): void
    {
        $this->validate($action);
        $pending = $this->store->verifiedPendingTransition($action->runId);
        if (null === $pending || !array_any($pending->work['actions'] ?? [], static fn (mixed $captured): bool => $captured instanceof RedriveRepairEffectsDTO && serialize($captured) === serialize($action))) {
            throw new \RuntimeException('Repair delivery requires its complete verified owner transition.');
        }
        // Do not arm work here. Missing or retired authorization must stay missing
        // or retired when the original delivery reaches its execution gate.
        $this->dispatcher->dispatchEffects($action->effects);
    }

    public function supports(object $action): bool
    {
        return $action instanceof RedriveRepairEffectsDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof RedriveRepairEffectsDTO || '' === $action->runId || [] === $action->effects || !array_is_list($action->effects)) {
            throw new \RuntimeException('Invalid prepared repair delivery.');
        }
        foreach ($action->effects as $effect) {
            $message = $effect instanceof Envelope ? $effect->getMessage() : $effect;
            if ((!$message instanceof AdvanceRun && !$message instanceof DurableExecutionResult && !$message instanceof ExecutionRequest)
                || $message->runId() !== $action->runId) {
                throw new \RuntimeException('Prepared repair delivery differs from its owner.');
            }
            if ($message instanceof ExecutionRequest && (!$effect instanceof Envelope || !$effect->last(ExecutionAuthorizationStamp::class) instanceof ExecutionAuthorizationStamp)) {
                throw new \RuntimeException('Prepared repair request lacks its original authorization.');
            }
        }
    }
}
