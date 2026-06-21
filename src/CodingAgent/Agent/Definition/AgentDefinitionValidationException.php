<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Validation/parse exception for agent definitions.
 *
 * Every message should include the file path and the relevant field name
 * so the user can locate and fix the problem without manual searching.
 *
 * This exception is thrown by {@see AgentDefinitionParser} and
 * {@see AgentFrontmatterParser} for hard failures.
 */
final class AgentDefinitionValidationException extends \RuntimeException
{
}
