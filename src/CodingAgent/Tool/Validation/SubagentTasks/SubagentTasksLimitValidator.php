<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Validation\SubagentTasks;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see SubagentTasksLimit} against the configured max agents.
 *
 * Autowired with the same AgentsConfig instance the subagent definition
 * builder and its schema provider consume. Null (single mode) and empty
 * arrays are skipped — emptiness is the Count constraint's job.
 */
final class SubagentTasksLimitValidator extends ConstraintValidator
{
    public function __construct(
        private readonly AgentsConfig $config,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SubagentTasksLimit) {
            throw new UnexpectedTypeException($constraint, SubagentTasksLimit::class);
        }

        if (!\is_array($value) || [] === $value) {
            return;
        }

        $max = $this->config->maxAgents;
        if (\count($value) > $max) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ limit }}', (string) $max)
                ->setParameter('{{ count }}', (string) \count($value))
                ->addViolation();
        }
    }
}
