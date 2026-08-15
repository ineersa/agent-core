<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Constraints;

use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\EditFileArgumentsDTO;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see EditFileTarget} on EditFileArgumentsDTO.
 *
 * Replaces the tool's resolveAndVerifyTarget() precheck: the target must
 * exist and be readable before execution. Writability is deliberately not
 * pre-checked — the current code never checked it and PatchApplier reports
 * write failures atomically under lock at execution time.
 */
final class EditFileTargetValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EditFileTarget) {
            throw new UnexpectedTypeException($constraint, EditFileTarget::class);
        }

        if (!$value instanceof EditFileArgumentsDTO) {
            throw new UnexpectedTypeException($value, EditFileArgumentsDTO::class);
        }

        // Blank paths are rejected by the property-level NotBlank constraint;
        // skip filesystem checks so the model sees one clear violation.
        if ('' === trim($value->path)) {
            return;
        }

        $resolvedPath = PathResolver::resolve($value->path);

        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            $this->context->buildViolation(\sprintf('File "%s" does not exist or is not readable. Use the write tool to create new files.', $resolvedPath))
                ->addViolation();
        }
    }
}
