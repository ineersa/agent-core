<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Denormalizes and validates raw `subagent` tool arguments.
 */
final class SubagentArgumentsFactory
{
    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function fromToolArguments(array $arguments): SubagentArgumentsDTO
    {
        if (isset($arguments['concurrency'])) {
            throw new ToolCallException('The "concurrency" argument is not supported. Parallel subagent calls run all tasks concurrently up to agents.max_agents.', retryable: false, hint: 'Omit "concurrency" and pass {"tasks":[{"agent":"scout","task":"..."}, ...]} instead.');
        }

        if (isset($arguments['background']) && true === $arguments['background']) {
            throw new ToolCallException('Background subagent execution is not yet implemented. Use foreground mode by omitting the "background" field.', retryable: false);
        }

        $normalized = $this->normalizeTasksArray($arguments);

        try {
            /** @var SubagentArgumentsDTO $dto */
            $dto = $this->denormalizer->denormalize(
                $normalized,
                SubagentArgumentsDTO::class,
            );
        } catch (\Throwable $e) {
            throw new ToolCallException('Invalid subagent arguments: '.$e->getMessage(), retryable: false);
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

            throw new ToolCallException($message, retryable: false, hint: 'Single mode: {"agent":"scout","task":"..."}  Parallel mode: {"tasks":[{"agent":"scout","task":"..."}]}');
        }

        return $dto;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function normalizeTasksArray(array $arguments): array
    {
        if (!isset($arguments['tasks']) || !\is_array($arguments['tasks'])) {
            return $arguments;
        }

        $tasks = [];
        foreach ($arguments['tasks'] as $index => $rawTask) {
            if (!\is_array($rawTask)) {
                throw new ToolCallException(\sprintf('tasks[%d] must be an object with "agent" and "task" strings.', $index), retryable: false);
            }

            $agent = $rawTask['agent'] ?? null;
            $task = $rawTask['task'] ?? null;
            if (!\is_string($agent) || '' === trim($agent) || !\is_string($task) || '' === trim($task)) {
                throw new ToolCallException(\sprintf('tasks[%d] must include non-empty "agent" and "task" strings.', $index), retryable: false);
            }

            $tasks[] = new SubagentTaskDTO(agent: trim($agent), task: trim($task));
        }

        $normalized = $arguments;
        $normalized['tasks'] = $tasks;

        return $normalized;
    }
}
