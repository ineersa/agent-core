<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills\Exception;

/**
 * SKILL.md frontmatter failed type or constraint validation.
 *
 * The message is user-facing and never contains file contents.
 */
final class SkillFrontmatterValidationException extends \RuntimeException
{
    /**
     * @param string $field Offending frontmatter key (e.g. "disable-model-invocation")
     */
    public function __construct(
        public readonly string $field,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
