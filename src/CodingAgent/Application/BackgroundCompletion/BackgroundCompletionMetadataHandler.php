<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\DTO\FinalizeCommandMetadataDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\ConsumeBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\RecordBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Deferred\DeferredBackgroundCompletionSettlement;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class BackgroundCompletionMetadataHandler implements CoordinationActionValidatorInterface
{
    public function __construct(
        private BackgroundCompletionReceiptRepository $receipts,
        private ValidatorInterface $validator,
        private DenormalizerInterface $denormalizer,
        private DeferredBackgroundCompletionSettlement $deferredSettlement,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function record(RecordBackgroundCompletionDTO $action, VerifiedTransitionDTO $pending): void
    {
        $this->assertCaptured($action, $pending);
        $this->validate($action);
        $this->receipts->record($action->receipt);
        if (BackgroundCompletionDispositionEnum::Forwarding !== $action->receipt->disposition) {
            $this->deferredSettlement->recordAdmission($action->receipt->identity, $action->receipt->sourceRunId, $action->receipt->deferredId);
        }
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function consume(FinalizeCommandMetadataDTO $action, VerifiedTransitionDTO $pending): void
    {
        $this->assertCaptured($action, $pending);
        $this->validate($action);
        $metadata = $action->metadata['background_completion'] ?? null;
        if (null === $metadata) {
            return;
        }
        $decision = $this->denormalizer->denormalize($metadata, ConsumeBackgroundCompletionDTO::class);
        if ($decision->identity !== $action->idempotencyKey || $decision->destinationRunId !== $action->runId) {
            throw new \RuntimeException('Background completion consumption differs from its mailbox decision.');
        }
        $receipt = $this->receipts->receipt($decision->identity)
            ?? throw new \RuntimeException('Background completion consumption has no receipt.');
        if ($receipt->sourceRunId !== $decision->sourceRunId) {
            throw new \RuntimeException('Background completion consumption changed source identity.');
        }
        $this->receipts->consume($decision->identity, $action->runId, BackgroundCompletionDispositionEnum::Forwarding === $decision->disposition);
    }

    public function supports(object $action): bool
    {
        return $action instanceof RecordBackgroundCompletionDTO || $action instanceof FinalizeCommandMetadataDTO;
    }

    public function validate(object $action): void
    {
        $value = $action;
        if ($action instanceof FinalizeCommandMetadataDTO) {
            $metadata = $action->metadata['background_completion'] ?? null;
            if (null === $metadata) {
                return;
            }
            $value = $this->denormalizer->denormalize($metadata, ConsumeBackgroundCompletionDTO::class);
        }
        $errors = $this->validator->validate($value);
        if (\count($errors) > 0) {
            throw new ValidationFailedException($value, $errors);
        }
    }

    private function assertCaptured(object $action, VerifiedTransitionDTO $pending): void
    {
        foreach ([...($pending->work['actions'] ?? []), ...($pending->work['after_turn_actions'] ?? [])] as $captured) {
            if ($captured instanceof DispatchLocalMetadataMessageDTO && serialize($captured->message) === serialize($action)) {
                return;
            }
        }
        throw new \RuntimeException('Background completion metadata requires its captured owner transition.');
    }
}
