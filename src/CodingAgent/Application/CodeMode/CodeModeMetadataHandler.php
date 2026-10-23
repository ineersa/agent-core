<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Application\CodeMode\DTO\MarkCodeModeDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class CodeModeMetadataHandler implements CoordinationActionValidatorInterface
{
    public function __construct(private CodeModeExecutionRepository $executions, private DeferredToolCompletionRepositoryInterface $deferred, private ValidatorInterface $validator)
    {
    }

    public function __invoke(MarkCodeModeDTO $message): void
    {
        $this->validate($message);
        if ($message->detached) {
            $this->executions->detach($message->identity);
        } else {
            $this->executions->settle($message->identity);
            $this->deferred->markCompleted($message->identity);
        }
    }

    public function supports(object $action): bool
    {
        return $action instanceof MarkCodeModeDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof MarkCodeModeDTO) {
            throw new \InvalidArgumentException('Unsupported code-mode metadata action.');
        }
        $errors = $this->validator->validate($action);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($action, $errors);
        }
    }
}
