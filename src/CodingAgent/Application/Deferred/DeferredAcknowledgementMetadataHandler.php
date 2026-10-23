<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Application\Deferred\DTO\MarkDeferredAcknowledgedDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class DeferredAcknowledgementMetadataHandler implements CoordinationActionValidatorInterface
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(MarkDeferredAcknowledgedDTO $message): void
    {
        $this->validate($message);
        $this->deferred->markAcknowledged($message->deferredId);
    }

    public function supports(object $action): bool
    {
        return $action instanceof MarkDeferredAcknowledgedDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof MarkDeferredAcknowledgedDTO) {
            throw new \InvalidArgumentException('Unsupported deferred acknowledgement action.');
        }
        $errors = $this->validator->validate($action);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($action, $errors);
        }
    }
}
