<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Denormalizes and validates raw `agent_retrieve` tool arguments.
 */
final class AgentRetrieveArgumentsFactory
{
    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function fromToolArguments(array $arguments): AgentRetrieveArgumentsDTO
    {
        $normalized = $arguments;
        if (isset($normalized['limit']) && \is_string($normalized['limit']) && ctype_digit($normalized['limit'])) {
            $normalized['limit'] = (int) $normalized['limit'];
        }

        try {
            /** @var AgentRetrieveArgumentsDTO $dto */
            $dto = $this->denormalizer->denormalize(
                $normalized,
                AgentRetrieveArgumentsDTO::class,
            );
        } catch (\Throwable $e) {
            throw new ToolCallException('Invalid agent_retrieve arguments: '.$e->getMessage(), retryable: false);
        }

        $violations = $this->validator->validate($dto);
        if ($violations->count() > 0) {
            /** @var ConstraintViolationInterface $violation */
            $violation = $violations->get(0);
            $message = $violation->getMessage();
            $path = $violation->getPropertyPath();
            if ('' !== $path) {
                $message = \sprintf('"%s": %s', $path, $message);
            }

            throw new ToolCallException($message, retryable: false, hint: 'Example: {"artifact_id": "agent_abc123", "mode": "handoff"} or {"agent_run_id": "<child-run-uuid>"}.');
        }

        return $dto;
    }
}
