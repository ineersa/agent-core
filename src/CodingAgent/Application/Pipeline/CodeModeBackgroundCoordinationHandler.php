<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\MarkCodeModeBackgroundedDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class CodeModeBackgroundCoordinationHandler implements CoordinationActionValidatorInterface
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ApplicationDbTransactionInterface $transactions,
        private ValidatorInterface $validator,
        private RunMessageProcessor $processor,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(MarkCodeModeBackgroundedDTO $action): void
    {
        $this->validate($action);
        $this->transactions->transactional(function () use ($action): void {
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
}
