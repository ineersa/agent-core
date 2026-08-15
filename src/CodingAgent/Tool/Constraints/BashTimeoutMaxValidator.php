<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Constraints;

use Ineersa\CodingAgent\Config\BashToolConfig;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see BashTimeoutMax} against the configured max timeout.
 *
 * Autowired with the same BashToolConfig instance the bash tool and its
 * schema provider consume. Null (omitted) timeout is optional and skipped;
 * non-int values cannot reach validation through the native resolver.
 */
final class BashTimeoutMaxValidator extends ConstraintValidator
{
    public function __construct(
        private readonly BashToolConfig $config,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof BashTimeoutMax) {
            throw new UnexpectedTypeException($constraint, BashTimeoutMax::class);
        }

        if (null === $value || !\is_int($value)) {
            return;
        }

        $max = $this->config->maxTimeoutSeconds;
        if ($value > $max) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ limit }}', (string) $max)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }
}
